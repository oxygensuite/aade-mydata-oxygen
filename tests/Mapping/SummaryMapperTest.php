<?php

namespace Tests\Mapping;

use Firebed\AadeMyData\Enums\IncomeClassificationCategory;
use Firebed\AadeMyData\Enums\IncomeClassificationType;
use Firebed\AadeMyData\Enums\TaxType;
use Firebed\AadeMyData\Enums\WithheldPercentCategory;
use Firebed\AadeMyData\Models\InvoiceSummary;
use Firebed\AadeMyData\Models\TaxesTotals;
use Firebed\AadeMyData\Models\TaxTotals;
use OxygenSuite\AadeMyData\Mapping\ClassificationMapper;
use OxygenSuite\AadeMyData\Mapping\SummaryMapper;
use Tests\TestCase;

class SummaryMapperTest extends TestCase
{
    private function mapper(): SummaryMapper
    {
        return new SummaryMapper(new ClassificationMapper());
    }

    public function test_invoice_level_taxes(): void
    {
        $totals = new TaxesTotals([
            (new TaxTotals())->setTaxType(TaxType::TYPE_1)->setTaxCategory(WithheldPercentCategory::TAX_1)->setUnderlyingValue(100.0)->setTaxAmount(20.0),
            (new TaxTotals())->setTaxType(TaxType::TYPE_5)->setTaxAmount(3.0),
        ]);

        $this->assertSame([
            ['type' => 1, 'category' => 1, 'underlying_amount' => 100.0, 'amount' => 20.0],
            ['type' => 5, 'amount' => 3.0],
        ], $this->mapper()->taxes($totals));

        $this->assertSame([], $this->mapper()->taxes(null));
        $this->assertSame([], $this->mapper()->taxes(new TaxesTotals()));
    }

    public function test_summary_keeps_only_provider_totals_and_classifications(): void
    {
        $summary = (new InvoiceSummary())->setTotalNetValue(100.0)->setTotalVatAmount(24.0)->setTotalWithheldAmount(20.0)->setTotalGrossValue(104.0)
            ->addIncomeClassification(IncomeClassificationType::E3_561_001, IncomeClassificationCategory::CATEGORY_1_1, 100.0);

        $this->assertSame([
            'total_net_amount' => 100.0,
            'total_vat_amount' => 24.0,
            'total_gross_amount' => 104.0,
            'classifications' => [['type' => 'E3_561_001', 'category' => 'category1_1', 'amount' => 100.0]],
        ], $this->mapper()->summary($summary));

        $this->assertSame([], $this->mapper()->summary(null));
    }
}
