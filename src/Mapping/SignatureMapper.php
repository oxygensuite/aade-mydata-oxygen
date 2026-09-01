<?php

namespace OxygenSuite\AadeMyData\Mapping;

use Firebed\AadeMyData\Models\Invoice;
use Firebed\AadeMyData\Models\InvoiceHeader;
use Firebed\AadeMyData\Models\PaymentMethodDetail;
use OxygenSuite\AadeMyData\Enums\NSP;
use OxygenSuite\AadeMyData\Enums\SignatureDuration;

/**
 * Turns an invoice and one of its POS payments into the JSON payload of the provider's
 * POST /signatures. Everything except the network and the validity window is derived from
 * the models the ERP already built; missing values are omitted so the provider's own
 * validation names them.
 */
final class SignatureMapper
{
    /**
     * @return array<array-key, mixed>
     */
    public function map(Invoice $invoice, PaymentMethodDetail $payment, NSP $nsp, SignatureDuration $duration): array
    {
        $header = $invoice->getInvoiceHeader() ?? new InvoiceHeader();
        $issuer = $invoice->getIssuer();
        $summary = $invoice->getInvoiceSummary();

        return Values::compact([
            'nsp' => Values::scalar($nsp),
            // Set only once the invoice has been transmitted: the deferred flow requires the
            // signature's mark to match the invoice's.
            'mark' => $invoice->getMark(),
            'issuer_vat_number' => $issuer?->getVatNumber(),
            // The provider defaults a missing branch_code to 0 and a missing series to "0".
            'branch_code' => $issuer?->getBranch(),
            'invoice_series' => $header->getSeries(),
            'invoice_number' => $header->getAa(),
            'invoice_issued_at' => IssuedAt::atom($header),
            'invoice_type' => Values::scalar($header->getInvoiceType()),
            'invoice_net_amount' => $summary?->getTotalNetValue(),
            'invoice_vat_amount' => $summary?->getTotalVatAmount(),
            'invoice_total_amount' => $summary?->getTotalGrossValue(),
            'payment_amount' => $payment->getAmount(),
            'duration' => Values::scalar($duration),
            // myDATA's tid is the POS terminal id. It is not forwarded on the invoice — the
            // provider reads it back off the signature — so it travels exactly once, here.
            'terminal_id' => $payment->getTid(),
        ]);
    }
}
