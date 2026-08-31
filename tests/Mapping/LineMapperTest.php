<?php

namespace Tests\Mapping;

use Firebed\AadeMyData\Enums\FeesPercentCategory;
use Firebed\AadeMyData\Enums\IncomeClassificationCategory;
use Firebed\AadeMyData\Enums\IncomeClassificationType;
use Firebed\AadeMyData\Enums\MovePurpose;
use Firebed\AadeMyData\Enums\RecType;
use Firebed\AadeMyData\Enums\UnitMeasurement;
use Firebed\AadeMyData\Enums\VatCategory;
use Firebed\AadeMyData\Enums\VatExemption;
use Firebed\AadeMyData\Enums\WithheldPercentCategory;
use Firebed\AadeMyData\Models\InvoiceDetails;
use Firebed\AadeMyData\Models\Ship;
use OxygenSuite\AadeMyData\Mapping\ClassificationMapper;
use OxygenSuite\AadeMyData\Mapping\LineMapper;
use Tests\TestCase;

class LineMapperTest extends TestCase
{
    private function mapper(): LineMapper
    {
        return new LineMapper(new ClassificationMapper());
    }

    public function test_maps_a_full_line(): void
    {
        $line = (new InvoiceDetails())
            ->setLineNumber(1)->setItemCode('SKU-1')->setItemDescr('Widget')
            ->setQuantity(3)->setMeasurementUnit(UnitMeasurement::UNIT_1)->setUnitPrice(3.333333)
            ->setNetValue(10.0)->setVatCategory(VatCategory::VAT_1)->setVatAmount(2.4)
            ->setLineComments('note')->setTaricNo('1234567890')
            ->setWithheldAmount(2.0)->setWithheldPercentCategory(WithheldPercentCategory::TAX_1)
            ->setNotVAT195(true)
            ->addIncomeClassification(IncomeClassificationType::E3_561_001, IncomeClassificationCategory::CATEGORY_1_1, 10.0);

        $this->assertSame([
            'item_code' => 'SKU-1',
            'description' => 'Widget',
            'quantity' => 3.0,
            'measurement_unit' => 1,
            'unit_price' => 3.333333,
            'net_amount' => 10.0,
            'vat_category' => 1,
            'vat_amount' => 2.4,
            'line_comments' => 'note',
            'taric_no' => '1234567890',
            'not_vat_195' => true,
            'taxes' => [['type' => 1, 'category' => 1, 'amount' => 2.0]],
            'classifications' => [['type' => 'E3_561_001', 'category' => 'category1_1', 'amount' => 10.0]],
        ], $this->mapper()->map($line));
    }

    /**
     * myDATA has no unit price, so the ERP supplies it through InvoiceDetails::setUnitPrice()
     * (kept off the myDATA XML) and the bridge forwards it untouched.
     */
    public function test_the_unit_price_is_taken_from_the_model(): void
    {
        $line = (new InvoiceDetails())->setQuantity(3)->setUnitPrice(3.33)->setNetValue(9.99)->setVatCategory(VatCategory::VAT_1)->setVatAmount(2.4);

        $this->assertSame(3.33, $this->mapper()->map($line)['unit_price']);
    }

    /**
     * Deriving the unit price from net / quantity would put a price the ERP never issued on the
     * invoice, and a missing quantity used to be priced as a single unit on top of that. Both
     * fields are required by the provider, so an incomplete line is forwarded as it stands and
     * rejected by name instead of being silently completed here.
     */
    public function test_a_missing_unit_price_is_omitted_rather_than_derived(): void
    {
        $line = (new InvoiceDetails())->setNetValue(12.5)->setVatCategory(VatCategory::VAT_7)->setVatAmount(0)->setVatExemptionCategory(VatExemption::TYPE_1);

        $payload = $this->mapper()->map($line);

        $this->assertArrayNotHasKey('unit_price', $payload);
        $this->assertArrayNotHasKey('quantity', $payload);
        $this->assertSame(1, $payload['vat_exemption_category']);
    }

    public function test_a_quantity_the_erp_left_out_is_never_replaced_by_one(): void
    {
        $line = (new InvoiceDetails())->setUnitPrice(12.5)->setNetValue(12.5)->setVatCategory(VatCategory::VAT_1)->setVatAmount(3.0);

        $payload = $this->mapper()->map($line);

        $this->assertArrayNotHasKey('quantity', $payload);
        $this->assertSame(12.5, $payload['unit_price']);
    }

    /**
     * A zero quantity is legal for a squashed line without a rec_type, and the provider checks
     * net_amount = ROUND(quantity * unit_price - discount, 2) on it like any other line.
     */
    public function test_a_zero_quantity_line_is_sent_as_the_model_holds_it(): void
    {
        $line = (new InvoiceDetails())->setQuantity(0)->setUnitPrice(0)->setNetValue(0)->setVatCategory(VatCategory::VAT_1)->setVatAmount(0);

        $payload = $this->mapper()->map($line);

        $this->assertSame(0.0, $payload['quantity']);
        $this->assertSame(0.0, $payload['unit_price']);
        $this->assertSame(0.0, $payload['net_amount']);
    }

    public function test_rec_type_lines_send_tax_category_without_amount(): void
    {
        $line = (new InvoiceDetails())->setRecType(RecType::TYPE_2)->setNetValue(4.0)->setVatCategory(VatCategory::VAT_1)->setVatAmount(0.96)
            ->setFeesPercentCategory(FeesPercentCategory::TYPE_1);

        $payload = $this->mapper()->map($line);

        $this->assertSame(2, $payload['rec_type']);
        $this->assertSame([['type' => 2, 'category' => 1]], $payload['taxes']);
        $this->assertArrayNotHasKey('classifications', $payload);
    }

    public function test_ship_and_move_purpose(): void
    {
        $line = (new InvoiceDetails())->setNetValue(1)->setVatCategory(VatCategory::VAT_1)->setVatAmount(0.24)
            ->setMovePurposeLine(MovePurpose::TYPE_1)->setOtherMovePurposeLineTitle('other')
            ->setDienergia((new Ship())->setApplicationId('APP')->setApplicationDate('2026-01-01')->setShipId('SHIP')->setDoy('A'));

        $payload = $this->mapper()->map($line);

        $this->assertSame(1, $payload['move_purpose']);
        $this->assertSame('other', $payload['other_move_purpose_title']);
        $this->assertSame(['application_id' => 'APP', 'application_date' => '2026-01-01', 'ship_id' => 'SHIP', 'tax_office' => 'A'], $payload['ship']);
    }

    public function test_false_not_vat_195_is_omitted(): void
    {
        $line = (new InvoiceDetails())->setNetValue(1)->setVatCategory(VatCategory::VAT_1)->setVatAmount(0.24)->setNotVAT195(false);

        $this->assertArrayNotHasKey('not_vat_195', $this->mapper()->map($line));
    }
}
