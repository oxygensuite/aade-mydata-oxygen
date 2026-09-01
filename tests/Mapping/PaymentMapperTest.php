<?php

namespace Tests\Mapping;

use Firebed\AadeMyData\Enums\PaymentMethod as PaymentType;
use Firebed\AadeMyData\Models\PaymentMethod;
use Firebed\AadeMyData\Models\PaymentMethodDetail;
use OxygenSuite\AadeMyData\Mapping\PaymentMapper;
use OxygenSuite\AadeMyData\Mapping\PaymentMethodMapper;
use Tests\TestCase;

class PaymentMapperTest extends TestCase
{
    public function test_maps_every_detail_in_model_order(): void
    {
        $payment = (new PaymentMethod())->setInvoiceMark(400001)
            ->addPaymentMethodDetails((new PaymentMethodDetail())->setType(PaymentType::METHOD_7)->setAmount(12.4)->setTransactionId('TX-1')->setProvidersSignature(null, 'MEUCIQ=='))
            ->addPaymentMethodDetails((new PaymentMethodDetail())->setType(PaymentType::METHOD_3)->setAmount(5.5)->setTipAmount(0.5));

        $this->assertSame([
            'issuer_vat_number' => '123456789',
            'payments' => [
                ['type' => 7, 'amount' => 12.4, 'transaction_id' => 'TX-1', 'signature' => 'MEUCIQ=='],
                ['type' => 3, 'amount' => 5.5, 'tip_amount' => 0.5],
            ],
        ], $this->mapper()->map($payment, '123456789'));
    }

    public function test_an_entry_without_details_sends_no_payments(): void
    {
        $payment = (new PaymentMethod())->setInvoiceMark(400001);

        $this->assertSame(['issuer_vat_number' => '123456789'], $this->mapper()->map($payment, '123456789'));
    }

    /**
     * Never sent as null: the provider names the field itself.
     */
    public function test_an_unresolved_vat_number_is_omitted(): void
    {
        $payment = (new PaymentMethod())->setInvoiceMark(400001)
            ->addPaymentMethodDetails((new PaymentMethodDetail())->setType(3)->setAmount(5.5));

        $this->assertSame(['payments' => [['type' => 3, 'amount' => 5.5]]], $this->mapper()->map($payment, null));
    }

    private function mapper(): PaymentMapper
    {
        return new PaymentMapper(new PaymentMethodMapper());
    }
}
