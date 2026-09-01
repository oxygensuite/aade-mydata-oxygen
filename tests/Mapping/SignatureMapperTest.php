<?php

namespace Tests\Mapping;

use Firebed\AadeMyData\Enums\InvoiceType;
use Firebed\AadeMyData\Models\Invoice;
use Firebed\AadeMyData\Models\InvoiceHeader;
use Firebed\AadeMyData\Models\PaymentMethodDetail;
use OxygenSuite\AadeMyData\Enums\NSP;
use OxygenSuite\AadeMyData\Enums\SignatureDuration;
use OxygenSuite\AadeMyData\Mapping\HeaderMapper;
use OxygenSuite\AadeMyData\Mapping\PartyMapper;
use OxygenSuite\AadeMyData\Mapping\SignatureMapper;
use Tests\Fixtures\Invoices;
use Tests\TestCase;

class SignatureMapperTest extends TestCase
{
    public function test_maps_a_pos_payment_of_a_retail_invoice(): void
    {
        $invoice = Invoices::pos();

        $this->assertSame([
            'nsp' => 1,
            'issuer_vat_number' => '123456789',
            'branch_code' => 1,
            'invoice_series' => 'R',
            'invoice_number' => '9',
            'invoice_issued_at' => '2026-08-27T10:15:00+03:00',
            'invoice_type' => '11.1',
            'invoice_net_amount' => 10.0,
            'invoice_vat_amount' => 2.4,
            'invoice_total_amount' => 12.4,
            'payment_amount' => 12.4,
            'duration' => 1,
            'terminal_id' => 'TID-1',
        ], $this->map($invoice));
    }

    public function test_the_invoice_mark_is_sent_once_the_invoice_has_one(): void
    {
        $invoice = Invoices::pos();
        $invoice->set('mark', '400001');

        $payload = $this->map($invoice);

        $this->assertSame('400001', $payload['mark']);
        // Right after nsp, as the provider documents it.
        $this->assertSame(['nsp', 'mark', 'issuer_vat_number'], array_slice(array_keys($payload), 0, 3));
    }

    /**
     * Nothing is invented for a value the ERP did not supply: the key is dropped and the
     * provider's own validation names it.
     */
    public function test_missing_values_are_omitted_rather_than_guessed(): void
    {
        $payload = (new SignatureMapper())->map(new Invoice(), new PaymentMethodDetail(), NSP::EDPS, SignatureDuration::HOURS_2);

        $this->assertSame(['nsp' => 4, 'duration' => 2], $payload);
    }

    /**
     * The signature's uid is generated from the issue instant, so it has to be the very one
     * the invoice will carry. Nothing on the provider validates the pair, so a drift would
     * be silent.
     */
    public function test_the_issue_instant_is_the_one_the_invoice_payload_carries(): void
    {
        $invoice = Invoices::pos();

        $this->assertSame($this->headerIssuedAt($invoice->getInvoiceHeader()), $this->map($invoice)['invoice_issued_at']);
    }

    /**
     * The case a naive reuse would get wrong: without an issue time, today's instant is
     * capped at an earlier dispatch time, which only the shared helper knows about.
     */
    public function test_the_issue_instant_matches_when_it_is_capped_by_the_dispatch_time(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Athens')))->format('Y-m-d');
        $header = (new InvoiceHeader())->setSeries('D')->setAa('7')->setIssueDate($today)->setInvoiceType(InvoiceType::TYPE_1_1)
            ->setDispatchDate($today)->setDispatchTime('00:00:01');

        $invoice = Invoices::pos();
        $invoice->setInvoiceHeader($header);

        $capped = $today.'T00:00:01+03:00';
        $this->assertSame($capped, $this->headerIssuedAt($header));
        $this->assertSame($capped, $this->map($invoice)['invoice_issued_at']);
    }

    private function map(Invoice $invoice): array
    {
        $payment = $invoice->getPaymentMethods()->first();

        return (new SignatureMapper())->map($invoice, $payment, NSP::VIVA, SignatureDuration::HOURS_60);
    }

    private function headerIssuedAt(InvoiceHeader $header): string
    {
        return (new HeaderMapper(new PartyMapper()))->header($header)['issued_at'];
    }
}
