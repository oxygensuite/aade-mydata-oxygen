<?php

namespace OxygenSuite\AadeMyData\Mapping;

use Firebed\AadeMyData\Models\PaymentMethodDetail;

final class PaymentMethodMapper
{
    /**
     * tid / ProvidersSignature / ECRToken are not forwarded: POS signatures must be
     * registered with the provider through its /signatures API.
     *
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
        ]);
    }
}
