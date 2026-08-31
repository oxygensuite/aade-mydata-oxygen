<?php

namespace OxygenSuite\AadeMyData\Mapping;

use Firebed\AadeMyData\Models\Invoice;
use Firebed\AadeMyData\Models\InvoiceDetails;
use Firebed\AadeMyData\Models\InvoiceHeader;
use Firebed\AadeMyData\Models\PaymentMethodDetail;
use OxygenSuite\AadeMyData\Api\ProviderException;
use OxygenSuite\AadeMyData\Api\UnauthorizedException;
use OxygenSuite\AadeMyData\Exceptions\MarkNotFoundException;

/**
 * Turns a package Invoice into the JSON payload of the provider's POST /invoices.
 */
final class InvoiceMapper
{
    private HeaderMapper $headers;
    private PartyMapper $parties;
    private LineMapper $lines;
    private PaymentMethodMapper $payments;
    private SummaryMapper $summary;

    public function __construct(private DocumentResolver $documents)
    {
        $classifications = new ClassificationMapper();

        $this->parties = new PartyMapper();
        $this->headers = new HeaderMapper($this->parties);
        $this->lines = new LineMapper($classifications);
        $this->payments = new PaymentMethodMapper();
        $this->summary = new SummaryMapper($classifications);
    }

    /**
     * @throws MarkNotFoundException|ProviderException|UnauthorizedException
     * @return array<array-key, mixed>
     */
    public function map(Invoice $invoice): array
    {
        $header = $invoice->getInvoiceHeader() ?? new InvoiceHeader();

        $lines = $invoice->getInvoiceDetails() ?? [];
        usort($lines, fn (InvoiceDetails $a, InvoiceDetails $b) => ($a->getLineNumber() ?? 0) <=> ($b->getLineNumber() ?? 0));

        return Values::compact([
            'issuer' => $this->parties->issuer($invoice->getIssuer()),
            'counterpart' => $this->parties->counterpart($invoice->getCounterpart()),
            'header' => $this->headers->header($header),
            'correlated_documents' => $this->documents->resolve($header->getCorrelatedInvoices()),
            'connected_documents' => $this->documents->resolve($header->getMultipleConnectedMarks()),
            'correlated_entities' => $this->headers->correlatedEntities($header->getOtherCorrelatedEntities()),
            'shipping_details' => $this->headers->shippingDetails($header->getOtherDeliveryNoteHeader()),
            'vehicles' => $this->headers->vehicles($invoice->getOtherTransportDetails()),
            'payment_methods' => array_map(fn (PaymentMethodDetail $method) => $this->payments->map($method), $invoice->getPaymentMethods()?->all() ?? []),
            'lines' => array_map(fn (InvoiceDetails $line) => $this->lines->map($line), $lines),
            'taxes' => $this->summary->taxes($invoice->getTaxesTotals()),
            'summary' => $this->summary->summary($invoice->getInvoiceSummary()),
            'transmission_failure' => Values::scalar($invoice->get('transmissionFailure')),
        ]);
    }
}
