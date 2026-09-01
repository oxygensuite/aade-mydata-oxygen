<?php

namespace Tests\Mapping;

use DateTimeImmutable;
use DateTimeZone;
use Firebed\AadeMyData\Enums\CurrencyCode;
use Firebed\AadeMyData\Enums\EntityTypes;
use Firebed\AadeMyData\Enums\InvoiceType;
use Firebed\AadeMyData\Enums\MovePurpose;
use Firebed\AadeMyData\Models\Address;
use Firebed\AadeMyData\Models\EntityType;
use Firebed\AadeMyData\Models\InvoiceHeader;
use Firebed\AadeMyData\Models\OtherDeliveryNoteHeader;
use Firebed\AadeMyData\Models\Party;
use Firebed\AadeMyData\Models\TransportDetail;
use OxygenSuite\AadeMyData\Exceptions\IssueTimeMissingException;
use OxygenSuite\AadeMyData\Mapping\HeaderMapper;
use OxygenSuite\AadeMyData\Mapping\PartyMapper;
use Tests\TestCase;

class HeaderMapperTest extends TestCase
{
    private function mapper(): HeaderMapper
    {
        return new HeaderMapper(new PartyMapper());
    }

    private function nowInAthens(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Athens'));
    }

    public function test_erp_issue_time_is_used_and_flags_are_sent_only_when_true(): void
    {
        $header = (new InvoiceHeader())->setSeries('A')->setAa('42')->setIssueDate('2026-08-27')->setIssueTime('10:15:00')->setInvoiceType(InvoiceType::TYPE_1_1)
            ->setCurrency(CurrencyCode::EUR)->setVatPaymentSuspension(false)->setSelfPricing(false)->setFuelInvoice(false)
            ->setSpecialInvoiceCategory(1)->setMovePurpose(MovePurpose::TYPE_1)->setThirdPartyCollection(false)->setReverseDeliveryNote(false);

        $this->assertSame([
            'series' => 'A',
            'number' => '42',
            'issued_at' => '2026-08-27T10:15:00+03:00',
            'invoice_type' => '1.1',
            'currency' => 'EUR',
            'move_purpose' => 1,
            'special_invoice_category' => 1,
        ], $this->mapper()->header($header));
    }

    /**
     * The bridge used to time an untimed document itself — the current time for today, Athens
     * midnight for any other date — so the invoice carried, and a POS signature attested, a
     * time the ERP never issued. It is refused instead.
     */
    public function test_a_date_without_an_issue_time_is_refused(): void
    {
        foreach (['2026-08-20', $this->nowInAthens()->format('Y-m-d')] as $issueDate) {
            $header = (new InvoiceHeader())->setSeries('A')->setAa('1')->setIssueDate($issueDate)->setInvoiceType('1.1');

            try {
                $this->mapper()->header($header);
                $this->fail("expected $issueDate to be refused without an issue time");
            } catch (IssueTimeMissingException $e) {
                $this->assertSame('A-1', $e->document);
                $this->assertStringContainsString('setIssueTime', $e->getMessage());
            }
        }
    }

    /**
     * A dispatch time is not an issue time: it used to cap the invented instant, which is
     * exactly the kind of near-enough value this refuses to send.
     */
    public function test_a_dispatch_time_does_not_stand_in_for_the_issue_time(): void
    {
        $today = $this->nowInAthens()->format('Y-m-d');
        $header = (new InvoiceHeader())->setAa('7')->setIssueDate($today)->setDispatchDate($today)->setDispatchTime('00:00:00');

        $this->expectException(IssueTimeMissingException::class);
        $this->mapper()->header($header);
    }

    public function test_a_missing_series_is_omitted_for_the_provider_to_default(): void
    {
        $payload = $this->mapper()->header((new InvoiceHeader())->setAa('1')->setIssueDate('2026-08-20')->setIssueTime('10:15:00')->setInvoiceType('1.1'));

        $this->assertSame('2026-08-20T10:15:00+03:00', $payload['issued_at']);
        $this->assertArrayNotHasKey('series', $payload);
    }
    public function test_erp_issue_time_is_not_capped_by_the_dispatch_time(): void
    {
        $header = (new InvoiceHeader())->setAa('7')->setIssueDate('2026-08-27')->setIssueTime('10:15:00')->setDispatchDate('2026-08-27')->setDispatchTime('09:00:00');

        $payload = $this->mapper()->header($header);

        $this->assertSame('2026-08-27T10:15:00+03:00', $payload['issued_at']);
        $this->assertSame('2026-08-27T09:00:00+03:00', $payload['dispatched_at']);
    }

    public function test_unparseable_issue_date_is_forwarded_for_the_provider_to_reject(): void
    {
        $header = (new InvoiceHeader())->setAa('1')->setIssueDate('not-a-date');

        $this->assertSame('not-a-date', $this->mapper()->header($header)['issued_at']);
    }

    public function test_missing_issue_date_is_omitted(): void
    {
        $payload = $this->mapper()->header((new InvoiceHeader())->setAa('1')->setIssueTime('10:15:00')->setInvoiceType('1.1'));

        $this->assertArrayNotHasKey('issued_at', $payload);
        $this->assertArrayNotHasKey('dispatched_at', $payload);
    }

    public function test_delivery_note_dispatch_time_is_kept(): void
    {
        $header = (new InvoiceHeader())->setAa('7')->setIssueDate('2026-08-27')->setIssueTime('08:45:00')->setInvoiceType('9.3')
            ->setDispatchDate('2026-08-27')->setDispatchTime('09:00:00')->setVehicleNumber('ABC1234')->setIsDeliveryNote(true)
            ->setTotalCancelDeliveryOrders(true)->setTableAA('T1')->setOtherMovePurposeTitle('x');

        $payload = $this->mapper()->header($header);

        $this->assertSame('2026-08-27T08:45:00+03:00', $payload['issued_at']);
        $this->assertSame('2026-08-27T09:00:00+03:00', $payload['dispatched_at']);
        $this->assertSame('ABC1234', $payload['vehicle_number']);
        $this->assertTrue($payload['is_delivery_note']);
        $this->assertTrue($payload['cancels_delivery_orders']);
        $this->assertSame('T1', $payload['table_number']);
        $this->assertSame('x', $payload['other_move_purpose_title']);
    }

    public function test_dispatch_without_time_borrows_the_issue_time(): void
    {
        $header = (new InvoiceHeader())->setAa('7')->setIssueDate('2026-08-27')->setIssueTime('10:15:00')->setInvoiceType('9.3')->setDispatchDate('2026-08-27');

        $payload = $this->mapper()->header($header);

        $this->assertSame('2026-08-27T10:15:00+03:00', $payload['issued_at']);
        $this->assertSame('2026-08-27T10:15:00+03:00', $payload['dispatched_at']);
    }

    public function test_shipping_details(): void
    {
        $details = (new OtherDeliveryNoteHeader())->setStartShippingBranch(0)->setCompleteShippingBranch(3)
            ->setLoadingAddress((new Address())->setStreet('A')->setNumber('1')->setPostalCode('11111')->setCity('X'))
            ->setDeliveryAddress((new Address())->setStreet('B')->setNumber('2')->setPostalCode('22222')->setCity('Y'));

        $this->assertSame([
            'pickup_branch_code' => 0,
            'delivery_branch_code' => 3,
            'pickup_address' => ['street' => 'A', 'number' => '1', 'postal_code' => '11111', 'city' => 'X'],
            'delivery_address' => ['street' => 'B', 'number' => '2', 'postal_code' => '22222', 'city' => 'Y'],
        ], $this->mapper()->shippingDetails($details));

        $this->assertSame([], $this->mapper()->shippingDetails(null));
    }

    public function test_correlated_entities_and_vehicles(): void
    {
        $entity = (new EntityType())->setType(EntityTypes::TYPE_1)->setEntityData((new Party())->setVatNumber('123456789')->setCountry('GR')->setName('Carrier'));

        $this->assertSame([
            ['type' => 1, 'party' => ['vat_number' => '123456789', 'country_code' => 'GR', 'name' => 'Carrier']],
        ], $this->mapper()->correlatedEntities([$entity]));
        $this->assertSame([], $this->mapper()->correlatedEntities(null));

        $this->assertSame(['ABC1234', 'XYZ9876'], $this->mapper()->vehicles([new TransportDetail('ABC1234'), new TransportDetail('XYZ9876')]));
        $this->assertSame([], $this->mapper()->vehicles(null));
    }
}
