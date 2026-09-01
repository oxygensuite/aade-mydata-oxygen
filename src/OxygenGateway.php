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
use Firebed\AadeMyData\Http\SendPaymentsMethod;
use Firebed\AadeMyData\Models\Invoice;
use Firebed\AadeMyData\Models\InvoicesDoc;
use Firebed\AadeMyData\Models\PaymentMethod;
use Firebed\AadeMyData\Models\PaymentMethodsDoc;
use Firebed\AadeMyData\Models\Response;
use Firebed\AadeMyData\Models\ResponseDoc;
use LogicException;
use OxygenSuite\AadeMyData\Api\ProviderClient;
use OxygenSuite\AadeMyData\Api\ProviderException;
use OxygenSuite\AadeMyData\Api\ProviderResponse;
use OxygenSuite\AadeMyData\Api\UnauthorizedException;
use OxygenSuite\AadeMyData\Exceptions\IssueTimeMissingException;
use OxygenSuite\AadeMyData\Exceptions\MarkNotFoundException;
use OxygenSuite\AadeMyData\Mapping\CompanyResolver;
use OxygenSuite\AadeMyData\Mapping\DocumentResolver;
use OxygenSuite\AadeMyData\Mapping\InvoiceMapper;
use OxygenSuite\AadeMyData\Mapping\PaymentMapper;
use OxygenSuite\AadeMyData\Mapping\PaymentMethodMapper;
use OxygenSuite\AadeMyData\Mapping\SignatureMapper;
use OxygenSuite\AadeMyData\Response\ProviderResponseMapper;
use OxygenSuite\AadeMyData\Response\ResponseBatch;
use OxygenSuite\AadeMyData\Response\ResponseDocWriter;
use OxygenSuite\AadeMyData\Signatures\SignatureService;

/**
 * Routes SendInvoices, CancelInvoice and SendPaymentsMethod through the Oxygen provider;
 * every other request goes to AADE through the inner (default) gateway.
 */
final class OxygenGateway implements Gateway
{
    private Gateway $inner;
    private DocumentResolver $documents;
    private CompanyResolver $company;
    private InvoiceMapper $mapper;
    private PaymentMapper $payments;
    private ProviderResponseMapper $responses;
    private ResponseBatch $batch;
    private SignatureService $signatures;

    public function __construct(private ProviderClient $client, ?Gateway $inner = null)
    {
        $this->inner = $inner ?? new GuzzleGateway();
        $this->documents = new DocumentResolver($client);
        $this->company = new CompanyResolver($client);
        $this->mapper = new InvoiceMapper($this->documents);
        $this->payments = new PaymentMapper(new PaymentMethodMapper());
        $this->responses = new ProviderResponseMapper();
        $this->batch = new ResponseBatch($this->responses);
        $this->signatures = new SignatureService($client, new SignatureMapper());
    }

    /**
     * POS signatures. Issuing one is not a myDATA request, so it has no place in the
     * gateway seam; it is reached from the registered gateway because that is where the
     * configured provider connection lives. See OxygenProvider::signatures().
     */
    public function signatures(): SignatureService
    {
        return $this->signatures;
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
                $request instanceof SendPaymentsMethod => $this->sendPayments($request->getPaymentMethodsDoc() ?? throw new LogicException('OxygenGateway needs the payment models: send through SendPaymentsMethod::handle().')),
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
        return $this->asXml($this->batch->collect($invoices, fn (Invoice $invoice): Response => $this->sendInvoice($invoice)));
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
        } catch (IssueTimeMissingException $e) {
            return $this->responses->forMissingIssueTime($e);
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
    private function sendPayments(PaymentMethodsDoc $payments): string
    {
        return $this->asXml($this->batch->collect($payments, fn (PaymentMethod $payment): Response => $this->sendPayment($payment)));
    }

    /**
     * @throws UnauthorizedException
     */
    private function sendPayment(PaymentMethod $payment): Response
    {
        $mark = $payment->getInvoiceMark();

        if ($mark === null) {
            return $this->responses->forMissingMark('An invoice mark is required to send payment methods.');
        }

        try {
            $ulid = $this->documents->resolveOne((string) $mark);
            // myDATA leaves entityVatNumber empty when the ERP transmits its own documents,
            // and the provider needs it: ask it who the token belongs to.
            $vatNumber = $payment->getEntityVatNumber() ?: $this->company->vatNumber();

            return $this->responses->forPayments($this->client->storePayments($ulid, $this->payments->map($payment, $vatNumber)));
        } catch (MarkNotFoundException $e) {
            return $this->responses->forMarkNotFound($e);
        } catch (ProviderException $e) {
            return $this->responses->forTransportFailure($e);
        }
    }

    /**
     * @throws UnauthorizedException
     */
    private function cancelInvoice(string $mark): string
    {
        return $this->asXml((new ResponseDoc())->add($this->cancel($mark)));
    }

    /**
     * @throws UnauthorizedException
     */
    private function cancel(string $mark): Response
    {
        if ($mark === '') {
            return $this->responses->forMissingMark('A mark is required to cancel an invoice.');
        }

        try {
            return $this->responses->forCancel($this->client->cancelInvoice($this->documents->resolveOne($mark)));
        } catch (MarkNotFoundException $e) {
            return $this->responses->forMarkNotFound($e);
        } catch (ProviderException $e) {
            return $this->responses->forTransportFailure($e);
        }
    }

    /**
     * The gateway contract is XML in, XML out. One writer per document.
     */
    private function asXml(ResponseDoc $doc): string
    {
        return (new ResponseDocWriter())->asXml($doc);
    }
}
