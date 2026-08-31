<?php

namespace Tests\Mapping;

use Firebed\AadeMyData\Enums\ExpenseClassificationCategory;
use Firebed\AadeMyData\Enums\ExpenseClassificationType;
use Firebed\AadeMyData\Enums\IncomeClassificationCategory;
use Firebed\AadeMyData\Enums\IncomeClassificationType;
use Firebed\AadeMyData\Models\ExpensesClassification;
use Firebed\AadeMyData\Models\IncomeClassification;
use OxygenSuite\AadeMyData\Mapping\ClassificationMapper;
use Tests\TestCase;

class ClassificationMapperTest extends TestCase
{
    public function test_collects_income_then_expenses(): void
    {
        $income = (new IncomeClassification())->setClassificationType(IncomeClassificationType::E3_561_001)->setClassificationCategory(IncomeClassificationCategory::CATEGORY_1_1)->setAmount(100.0);
        $categoryOnly = (new IncomeClassification())->setClassificationCategory(IncomeClassificationCategory::CATEGORY_1_95)->setAmount(0.0);
        $expense = (new ExpensesClassification())->setClassificationType(ExpenseClassificationType::E3_102_001)->setClassificationCategory(ExpenseClassificationCategory::CATEGORY_2_1)->setAmount(5.5);

        $this->assertSame([
            ['type' => 'E3_561_001', 'category' => 'category1_1', 'amount' => 100.0],
            ['category' => 'category1_95', 'amount' => 0.0],
            ['type' => 'E3_102_001', 'category' => 'category2_1', 'amount' => 5.5],
        ], (new ClassificationMapper())->collect([$income, $categoryOnly], [$expense]));

        $this->assertSame([], (new ClassificationMapper())->collect(null, null));
    }
}
