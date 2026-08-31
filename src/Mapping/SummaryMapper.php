<?php

namespace OxygenSuite\AadeMyData\Mapping;

use Firebed\AadeMyData\Models\InvoiceSummary;
use Firebed\AadeMyData\Models\TaxesTotals;
use Firebed\AadeMyData\Models\TaxTotals;

final class SummaryMapper
{
    public function __construct(private ClassificationMapper $classifications) {}

    /**
     * @return list<array<array-key, mixed>>
     */
    public function taxes(?TaxesTotals $totals): array
    {
        $taxes = [];

        foreach ($totals?->all() ?? [] as $tax) {
            /** @var TaxTotals $tax */
            $taxes[] = Values::compact([
                'type' => Values::scalar($tax->getTaxType()),
                'category' => Values::scalar($tax->getTaxCategory()),
                'underlying_amount' => $tax->getUnderlyingValue(),
                'amount' => $tax->getTaxAmount(),
            ]);
        }

        return $taxes;
    }

    /**
     * The provider recomputes withheld/fees/stamp/other/deductions totals itself,
     * so only the three totals it validates are forwarded.
     *
     * @return array<array-key, mixed>
     */
    public function summary(?InvoiceSummary $summary): array
    {
        if ($summary === null) {
            return [];
        }

        return Values::compact([
            'total_net_amount' => $summary->getTotalNetValue(),
            'total_vat_amount' => $summary->getTotalVatAmount(),
            'total_gross_amount' => $summary->getTotalGrossValue(),
            'classifications' => $this->classifications->collect($summary->getIncomeClassifications(), $summary->getExpensesClassifications()),
        ]);
    }
}
