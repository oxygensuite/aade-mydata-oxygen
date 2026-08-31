<?php

namespace OxygenSuite\AadeMyData\Mapping;

use Firebed\AadeMyData\Enums\TaxType;
use Firebed\AadeMyData\Models\InvoiceDetails;
use Firebed\AadeMyData\Models\Ship;

final class LineMapper
{
    public function __construct(private ClassificationMapper $classifications) {}

    /**
     * Every value is forwarded as the model holds it. The provider requires quantity and
     * unit_price and re-checks net_amount = ROUND(quantity * unit_price - discount, 2), so a
     * line the ERP left incomplete is rejected by field name rather than completed here with
     * a price or a quantity it never issued. total_amount is left out for the same reason:
     * the provider derives it from net_amount + vat_amount itself.
     *
     * @return array<array-key, mixed>
     */
    public function map(InvoiceDetails $line): array
    {
        return Values::compact([
            'rec_type' => Values::scalar($line->getRecType()),
            'item_code' => $line->getItemCode(),
            'description' => $line->getItemDescr(),
            'invoice_detail_type' => Values::scalar($line->getInvoiceDetailType()),
            'quantity' => $line->getQuantity(),
            'measurement_unit' => Values::scalar($line->getMeasurementUnit()),
            // myDATA has no unit price: the ERP supplies it through InvoiceDetails::setUnitPrice(),
            // which the package keeps off the InvoicesDoc XML.
            'unit_price' => $line->getUnitPrice(),
            'net_amount' => $line->getNetValue(),
            'vat_category' => Values::scalar($line->getVatCategory()),
            'vat_amount' => $line->getVatAmount(),
            'vat_exemption_category' => Values::scalar($line->getVatExemptionCategory()),
            'line_comments' => $line->getLineComments(),
            'taric_no' => $line->getTaricNo(),
            'other_measurement_unit_quantity' => $line->getOtherMeasurementUnitQuantity(),
            'other_measurement_unit_title' => $line->getOtherMeasurementUnitTitle(),
            'not_vat_195' => Values::flag($line->getNotVAT195()),
            'move_purpose' => Values::scalar($line->getMovePurposeLine()),
            'other_move_purpose_title' => $line->getOtherMovePurposeLineTitle(),
            'fuel_code' => Values::scalar($line->getFuelCode()),
            'quantity15' => $line->getQuantity15(),
            'ship' => $this->ship($line->getDienergia()),
            'taxes' => $this->taxes($line),
            'classifications' => $this->classifications->collect($line->getIncomeClassification(), $line->getExpensesClassification()),
        ]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function ship(?Ship $ship): array
    {
        if ($ship === null) {
            return [];
        }

        return Values::compact([
            'application_id' => $ship->getApplicationId(),
            'application_date' => $ship->getApplicationDate(),
            'ship_id' => $ship->getShipId(),
            'tax_office' => $ship->getDoy(),
        ]);
    }

    /**
     * One provider tax per myDATA tax family. The amount is omitted when the package
     * has none (rec_type 2/3 lines carry the tax in net_amount and must not send it).
     *
     * @return list<array<array-key, mixed>>
     */
    private function taxes(InvoiceDetails $line): array
    {
        $taxes = [];

        foreach ([
            [TaxType::TYPE_1, $line->getWithheldPercentCategory(), $line->getWithheldAmount()],
            [TaxType::TYPE_2, $line->getFeesPercentCategory(), $line->getFeesAmount()],
            [TaxType::TYPE_3, $line->getOtherTaxesPercentCategory(), $line->getOtherTaxesAmount()],
            [TaxType::TYPE_4, $line->getStampDutyPercentCategory(), $line->getStampDutyAmount()],
            [TaxType::TYPE_5, null, $line->getDeductionsAmount()],
        ] as [$type, $category, $amount]) {
            if ($category === null && $amount === null) {
                continue;
            }

            $taxes[] = Values::compact([
                'type' => $type->value,
                'category' => Values::scalar($category),
                'amount' => $amount,
            ]);
        }

        return $taxes;
    }
}
