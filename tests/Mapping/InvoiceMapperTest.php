<?php

namespace Tests\Mapping;

use Firebed\AadeMyData\Enums\DiscountType;
use Firebed\AadeMyData\Enums\TransmissionFailure;
use OxygenSuite\AadeMyData\Mapping\InvoiceMapper;
use Tests\Fixtures\Invoices;
use Tests\TestCase;

class InvoiceMapperTest extends TestCase
{
    private InvoiceMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new InvoiceMapper();
    }

    public function test_maps_a_b2b_invoice(): void
    {
        $this->assertSame([
            'issuer' => ['vat_number' => '123456789', 'branch_code' => 0],
            'counterpart' => [
                'vat_number' => '987654321', 'country_code' => 'GR', 'branch_code' => 0, 'name' => 'Customer',
                'address' => ['street' => 'Ermou', 'number' => '1', 'postal_code' => '10563', 'city' => 'Athens'],
            ],
            'header' => ['series' => 'A', 'number' => '42', 'issued_at' => '2026-08-27T09:30:00+03:00', 'invoice_type' => '1.1', 'currency' => 'EUR'],
            'payment_methods' => [['type' => 3, 'amount' => 124.0]],
            'lines' => [[
                'description' => 'Consulting', 'quantity' => 2.0, 'measurement_unit' => 1, 'unit_price' => 50.0,
                'net_amount' => 100.0, 'vat_category' => 1, 'vat_amount' => 24.0,
                'classifications' => [['type' => 'E3_561_001', 'category' => 'category1_1', 'amount' => 100.0]],
            ]],
            'summary' => [
                'total_net_amount' => 100.0, 'total_vat_amount' => 24.0, 'total_gross_amount' => 124.0,
                'classifications' => [['type' => 'E3_561_001', 'category' => 'category1_1', 'amount' => 100.0]],
            ],
        ], $this->mapper->map(Invoices::b2b()));
    }

    public function test_retail_invoice_has_no_counterpart(): void
    {
        $payload = $this->mapper->map(Invoices::retail());

        $this->assertArrayNotHasKey('counterpart', $payload);
        $this->assertSame(1, $payload['issuer']['branch_code']);
        $this->assertSame('11.1', $payload['header']['invoice_type']);
    }

    public function test_delivery_note_carries_shipping_details_and_vehicles(): void
    {
        $payload = $this->mapper->map(Invoices::deliveryNote());

        $this->assertSame('2026-08-27T09:00:00+03:00', $payload['header']['dispatched_at']);
        $this->assertSame(['street' => 'Depot', 'number' => '5', 'postal_code' => '11111', 'city' => 'Piraeus'], $payload['shipping_details']['pickup_address']);
        $this->assertSame(['XYZ9876'], $payload['vehicles']);
        $this->assertSame([['category' => 'category3', 'amount' => 0.0]], $payload['lines'][0]['classifications']);
    }

    /**
     * Line discounts and the free extra fields on the invoice, its lines and its payment
     * methods are not part of myDATA; the package keeps them on the models and the provider
     * takes them by these names.
     */
    public function test_discounts_and_extra_fields_are_forwarded(): void
    {
        $invoice = Invoices::b2b()->setExtraFields(['order' => 'SO-1']);
        $invoice->getInvoiceDetails()[0]->setDiscount(DiscountType::PERCENTAGE, 10.0)->setExtraFields(['warehouse' => 'A1', 'lot' => 42]);
        $invoice->getPaymentMethods()[0]->addExtraField('terminal', 'T1');

        $payload = $this->mapper->map($invoice);

        $this->assertSame(['order' => 'SO-1'], $payload['extra_fields']);
        $this->assertSame(1, $payload['lines'][0]['discount_type']);
        $this->assertSame(10.0, $payload['lines'][0]['discount_amount']);
        $this->assertSame(['warehouse' => 'A1', 'lot' => 42], $payload['lines'][0]['extra_fields']);
        $this->assertSame(['terminal' => 'T1'], $payload['payment_methods'][0]['extra_fields']);
    }

    /**
     * Sent as the ERP stated them: the provider links its own marks and forwards the rest.
     */
    public function test_marks_are_sent_as_they_are(): void
    {
        $invoice = Invoices::b2b();
        $invoice->getInvoiceHeader()->setCorrelatedInvoices([400001])->setMultipleConnectedMarks([400002, '400003']);

        $payload = $this->mapper->map($invoice);

        $this->assertSame([400001], $payload['correlated_documents']);
        $this->assertSame([400002, '400003'], $payload['connected_documents']);
    }

    /**
     * A value that is not a mark is left for the provider to reject rather than being
     * dropped silently; entries that cannot name a document at all are skipped.
     */
    public function test_a_malformed_reference_still_reaches_the_provider(): void
    {
        $invoice = Invoices::b2b();
        $invoice->getInvoiceHeader()->setCorrelatedInvoices(['not-a-mark', ['nested'], null, 400001]);

        $payload = $this->mapper->map($invoice);

        $this->assertSame(['not-a-mark', 400001], $payload['correlated_documents']);
    }

    public function test_lines_are_ordered_by_line_number_and_transmission_failure_passes_through(): void
    {
        $invoice = Invoices::b2b();
        $invoice->setTransmissionFailure(TransmissionFailure::ERP_CONNECTION_FAILURE);
        $second = clone $invoice->getInvoiceDetails()[0];
        $second->setLineNumber(0)->setItemDescr('First');
        $invoice->addInvoiceDetails($second);

        $payload = $this->mapper->map($invoice);

        $this->assertSame(1, $payload['transmission_failure']);
        $this->assertSame(['First', 'Consulting'], array_column($payload['lines'], 'description'));
    }
}
