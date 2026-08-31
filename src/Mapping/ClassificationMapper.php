<?php

namespace OxygenSuite\AadeMyData\Mapping;

use Firebed\AadeMyData\Models\ExpensesClassification;
use Firebed\AadeMyData\Models\IncomeClassification;

final class ClassificationMapper
{
    /**
     * @return array<array-key, mixed>
     */
    public function map(IncomeClassification|ExpensesClassification $classification): array
    {
        return Values::compact([
            'type' => Values::scalar($classification->getClassificationType()),
            'category' => Values::scalar($classification->getClassificationCategory()),
            'amount' => $classification->getAmount(),
        ]);
    }

    /**
     * @param IncomeClassification[]|null $income
     * @param ExpensesClassification[]|null $expenses
     *
     * @return list<array<array-key, mixed>>
     */
    public function collect(?array $income, ?array $expenses): array
    {
        $classifications = [];

        foreach ([...($income ?? []), ...($expenses ?? [])] as $classification) {
            $mapped = $this->map($classification);

            // A classification with nothing to say is dropped, as Values::compact would.
            if ($mapped !== []) {
                $classifications[] = $mapped;
            }
        }

        return $classifications;
    }
}
