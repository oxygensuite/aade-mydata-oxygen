<?php

namespace OxygenSuite\AadeMyData;

use Firebed\AadeMyData\Exceptions\MyDataAuthenticationException;
use Firebed\AadeMyData\Exceptions\MyDataException;
use Firebed\AadeMyData\Http\CancelDeliveryNote;
use Firebed\AadeMyData\Http\CancelInvoice;
use Firebed\AadeMyData\Http\Gateway;
use Firebed\AadeMyData\Http\GuzzleGateway;
use Firebed\AadeMyData\Http\MyDataRequest;
use Firebed\AadeMyData\Http\SendInvoices;
use Firebed\AadeMyData\Models\Invoice;
use Firebed\AadeMyData\Models\InvoicesDoc;
use Firebed\AadeMyData\Models\Response;
use Firebed\AadeMyData\Models\ResponseDoc;
use LogicException;
use OxygenSuite\AadeMyData\Api\ProviderClient;
use OxygenSuite\AadeMyData\Api\ProviderException;
use OxygenSuite\AadeMyData\Api\ProviderResponse;
use OxygenSuite\AadeMyData\Api\UnauthorizedException;
use OxygenSuite\AadeMyData\Exceptions\MarkNotFoundException;
use OxygenSuite\AadeMyData\Mapping\DocumentResolver;
use OxygenSuite\AadeMyData\Mapping\InvoiceMapper;
use OxygenSuite\AadeMyData\Response\ProviderResponseMapper;
use OxygenSuite\AadeMyData\Response\ResponseDocWriter;

/**
 * Routes SendInvoices and CancelInvoice through the Oxygen provider; every other
 * request goes to AADE through the inner (default) gateway.
 */
final class OxygenGateway implements Gateway
{
    private Gateway $inner;
    private DocumentResolver $documents;
    private InvoiceMapper $mapper;
    private ProviderResponseMapper $responses;

    public function __construct(private ProviderClient $client, ?Gateway $inner = null)
    {
        $this->inner = $inner ?? new GuzzleGateway();
        $this->documents = new DocumentResolver($client);
        $this->mapper = new InvoiceMapper($this->documents);
        $this->responses = new ProviderResponseMapper();
    }

    /**
     * @param array<array-key, mixed> $query
     */
    public function get(MyDataRequest $request, array $query): string
    {
        return $this->inner->get($request, $query);
    }

    /**
     * @throws MyDataException
     *
     * @param array<array-key, mixed>|null $query
     */
    public function post(MyDataRequest $request, ?array $query = null, ?string $body = null): string
    {
        try {
            return match (true) {
                $request instanceof SendInvoices => $this->sendInvoices($request->getInvoicesDoc() ?? throw new LogicException('OxygenGateway needs the invoice models: send through SendInvoices::handle().')),
                // CancelDeliveryNote is the package's provider-channel variant; an ERP never routes it through us.
                $request instanceof CancelInvoice && ! $request instanceof CancelDeliveryNote => $this->cancelInvoice($this->mark($query)),
                default => $this->inner->post($request, $query, $body),
            };
        } catch (UnauthorizedException $e) {
            throw new MyDataAuthenticationException(401, $e);
        }
    }

    /**
     * @param array<array-key, mixed>|null $query
     */
    private function mark(?array $query): string
    {
        $mark = $query['mark'] ?? '';

        return is_scalar($mark) ? (string) $mark : '';
    }

    /**
     * @throws UnauthorizedException
     */
    private function sendInvoices(InvoicesDoc $invoices): string
    {
        $doc = new ResponseDoc();
        $index = 0;
        $rejected = null;

        // Sequential on purpose: the provider locks per uid and the ERP relies on index order.
        foreach ($invoices as $invoice) {
            if ($rejected !== null) {
                $doc->add($this->responses->forUnauthorized($rejected)->setIndex(++$index));

                continue;
            }

            try {
                $doc->add($this->sendInvoice($invoice)->setIndex(++$index));
            } catch (UnauthorizedException $e) {
                // Nothing is registered yet, so the ERP is better served by the exception.
                if ($index === 0) {
                    throw $e;
                }

                // The invoices before this one are registered at the provider and legally
                // issued: their marks have to reach the ERP, so the rest of the batch is
                // reported per invoice instead of aborting and losing them.
                $rejected = $e;
                $doc->add($this->responses->forUnauthorized($e)->setIndex(++$index));
            }
        }

        return (new ResponseDocWriter())->asXml($doc);
    }

    /**
     * @throws UnauthorizedException
     */
    private function sendInvoice(Invoice $invoice): Response
    {
        // The provider wants the detailed rows and squashes them itself; a clone keeps the ERP's object as handed over.
        if ($invoice->isSquashed()) {
            $invoice = (clone $invoice)->unSquashInvoiceRows();
        }

        try {
            $payload = $this->mapper->map($invoice);

            $response = $invoice->getInvoiceHeader()?->getTotalCancelDeliveryOrders() === true
                ? $this->client->cancelCateringDocuments($payload)
                : $this->client->storeInvoice($payload);

            $duplicateUid = $this->responses->duplicateUid($response);

            return $duplicateUid === null
                ? $this->responses->forStore($response)
                : $this->recoverDuplicate($duplicateUid, $response);
        } catch (MarkNotFoundException $e) {
            return $this->responses->forMarkNotFound($e);
        } catch (ProviderException $e) {
            return $this->responses->forTransportFailure($e);
        }
    }

    /**
     * The provider already holds this uid (typically a retry after a lost response):
     * answer with the stored record instead of a rejection.
     *
     * @throws UnauthorizedException
     */
    private function recoverDuplicate(string $uid, ProviderResponse $rejection): Response
    {
        try {
            $ulid = $this->client->findInvoices(['uid' => $uid])->firstId();

            return $ulid === null
                ? $this->responses->forStore($rejection)
                : $this->responses->forStoredInvoice($this->client->showInvoice($ulid), $rejection);
        } catch (ProviderException) {
            return $this->responses->forStore($rejection);
        }
    }

    /**
     * @throws UnauthorizedException
     */
    private function cancelInvoice(string $mark): string
    {
        return (new ResponseDocWriter())->asXml((new ResponseDoc())->add($this->cancel($mark)));
    }

    /**
     * @throws UnauthorizedException
     */
    private function cancel(string $mark): Response
    {
        if ($mark === '') {
            return $this->responses->forMissingMark();
        }

        try {
            return $this->responses->forCancel($this->client->cancelInvoice($this->documents->resolveOne($mark)));
        } catch (MarkNotFoundException $e) {
            return $this->responses->forMarkNotFound($e);
        } catch (ProviderException $e) {
            return $this->responses->forTransportFailure($e);
        }
    }
}
