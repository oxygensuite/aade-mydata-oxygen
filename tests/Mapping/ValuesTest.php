<?php

namespace Tests\Mapping;

use Firebed\AadeMyData\Enums\VatCategory;
use OxygenSuite\AadeMyData\Mapping\Values;
use Tests\TestCase;

class ValuesTest extends TestCase
{
    public function test_scalar_unwraps_backed_enums(): void
    {
        $this->assertSame(1, Values::scalar(VatCategory::VAT_1));
        $this->assertSame('x', Values::scalar('x'));
        $this->assertNull(Values::scalar(null));
    }

    public function test_flag_keeps_only_true(): void
    {
        $this->assertTrue(Values::flag(true));
        $this->assertNull(Values::flag(false));
        $this->assertNull(Values::flag(null));
    }

    public function test_compact_drops_nulls_and_empty_arrays_recursively(): void
    {
        $this->assertSame(
            ['a' => 1, 'c' => ['d' => 0.0], 'list' => [['x' => 1], ['x' => 2]]],
            Values::compact(['a' => 1, 'b' => null, 'c' => ['d' => 0.0, 'e' => null], 'f' => [], 'g' => ['h' => null], 'list' => [['x' => 1], [], ['x' => 2]]]),
        );
        $this->assertSame([], Values::compact([]));
    }
}
