<?php

namespace OxygenSuite\AadeMyData\Mapping;

use Firebed\AadeMyData\Models\PaymentMethodDetail;

/**
 * One payment method, as both POST /invoices and POST /invoices/{id}/payments accept it.
 *
 * A card payment carries the provider's own signature, issued beforehand through
 * OxygenProvider::signatures(). What the provider fills in or has no field for is dropped:
 *
 * - tid — the provider reads the terminal id back off the signature it issued, so the ERP's
 *   tid travels once, at signing time, and never on the document.
 * - ProvidersSignature.SigningAuthor — stamped by the provider from its own ΥΠΑΗΕΣ
 *   registration, which is why setProvidersSignature(null, $signature) is the documented call.
 * - ProvidersSignature.EndToEndReferenceID — no provider field; IRIS references cannot travel.
 * - ECRToken — the ERP channel's own signature, meaningless on the provider's channel.
 */
final class PaymentMethodMapper
{
    /**
     * @return array<array-key, mixed>
     */
    public function map(PaymentMethodDetail $method): array
    {
        return Values::compact([
            'type' => Values::scalar($method->getType()),
            'amount' => $method->getAmount(),
            'info' => $method->getPaymentMethodInfo(),
            'tip_amount' => $method->getTipAmount(),
            'transaction_id' => $method->getTransactionId(),
            // Relayed as the ERP set it. The provider permits it only on card payments and
            // names the field when it does not belong, which beats dropping a signature the
            // ERP meant to send and transmitting the payment unsigned.
            'signature' => $method->getProvidersSignature()?->getSignature(),
        ]);
    }
}
