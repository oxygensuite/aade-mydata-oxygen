<?php

namespace OxygenSuite\AadeMyData\Mapping;

use Firebed\AadeMyData\Models\PaymentMethod;
use Firebed\AadeMyData\Models\PaymentMethodDetail;

/**
 * Turns one PaymentMethodsDoc entry into the JSON payload of the provider's
 * POST /invoices/{id}/payments — the deferred flow, for payments settled after the document
 * was transmitted.
 *
 * The entries themselves are shaped exactly like an invoice's payment_methods, so they go
 * through the same mapper.
 */
final class PaymentMapper
{
    public function __construct(private PaymentMethodMapper $payments) {}

    /**
     * @return array<array-key, mixed>
     */
    public function map(PaymentMethod $payment, ?string $issuerVatNumber): array
    {
        return Values::compact([
            'issuer_vat_number' => $issuerVatNumber,
            'payments' => array_map(fn (PaymentMethodDetail $detail) => $this->payments->map($detail), $payment->getPaymentMethodDetails() ?? []),
        ]);
    }
}
