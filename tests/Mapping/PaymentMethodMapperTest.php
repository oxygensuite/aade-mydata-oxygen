<?php

namespace Tests\Mapping;

use Firebed\AadeMyData\Enums\PaymentMethod;
use Firebed\AadeMyData\Models\PaymentMethodDetail;
use OxygenSuite\AadeMyData\Mapping\PaymentMethodMapper;
use Tests\TestCase;

class PaymentMethodMapperTest extends TestCase
{
    public function test_maps_a_card_payment_with_its_provider_signature(): void
    {
        $method = (new PaymentMethodDetail())->setType(PaymentMethod::METHOD_7)->setAmount(12.4)->setPaymentMethodInfo('card')
            ->setTipAmount(1.0)->setTransactionId('TX1')->setTid('TID1')->setProvidersSignature('author', 'MEUCIQ==');

        $this->assertSame([
            'type' => 7,
            'amount' => 12.4,
            'info' => 'card',
            'tip_amount' => 1.0,
            'transaction_id' => 'TX1',
            'signature' => 'MEUCIQ==',
        ], (new PaymentMethodMapper())->map($method));
    }

    public function test_minimal_payment_method(): void
    {
        $this->assertSame(['type' => 3, 'amount' => 5.0], (new PaymentMethodMapper())->map((new PaymentMethodDetail())->setType(3)->setAmount(5)));
    }

    /**
     * Nothing the ERP set is silently discarded: the provider allows a signature only on card
     * payments and answers by naming the field, which is a far better diagnosis than an
     * invoice transmitted with the signature quietly stripped off it.
     */
    public function test_a_signature_on_a_non_card_payment_is_still_relayed(): void
    {
        $method = (new PaymentMethodDetail())->setType(PaymentMethod::METHOD_3)->setAmount(5.0)->setProvidersSignature(null, 'MEUCIQ==');

        $this->assertSame('MEUCIQ==', (new PaymentMethodMapper())->map($method)['signature']);
    }

    /**
     * The ERP channel's own POS signature, and the IRIS reference, have no provider field.
     */
    public function test_the_ecr_token_and_the_iris_reference_are_dropped(): void
    {
        $method = (new PaymentMethodDetail())->setType(PaymentMethod::METHOD_7)->setAmount(12.4)
            ->setECRToken('ECR-1', '123456')
            ->setProvidersSignature(null, 'MEUCIQ==');
        $method->getProvidersSignature()->setEndToEndReferenceID('IRIS-1');

        $this->assertSame([
            'type' => 7,
            'amount' => 12.4,
            'signature' => 'MEUCIQ==',
        ], (new PaymentMethodMapper())->map($method));
    }
}
