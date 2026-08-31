<?php

namespace Tests\Mapping;

use Firebed\AadeMyData\Enums\PaymentMethod;
use Firebed\AadeMyData\Models\PaymentMethodDetail;
use OxygenSuite\AadeMyData\Mapping\PaymentMethodMapper;
use Tests\TestCase;

class PaymentMethodMapperTest extends TestCase
{
    public function test_maps_payment_method_and_drops_pos_signature_fields(): void
    {
        $method = (new PaymentMethodDetail())->setType(PaymentMethod::METHOD_7)->setAmount(12.4)->setPaymentMethodInfo('card')
            ->setTipAmount(1.0)->setTransactionId('TX1')->setTid('TID1')->setProvidersSignature('author', 'signed');

        $this->assertSame([
            'type' => 7,
            'amount' => 12.4,
            'info' => 'card',
            'tip_amount' => 1.0,
            'transaction_id' => 'TX1',
        ], (new PaymentMethodMapper())->map($method));
    }

    public function test_minimal_payment_method(): void
    {
        $this->assertSame(['type' => 3, 'amount' => 5.0], (new PaymentMethodMapper())->map((new PaymentMethodDetail())->setType(3)->setAmount(5)));
    }
}
