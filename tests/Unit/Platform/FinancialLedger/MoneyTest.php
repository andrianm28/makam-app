<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\FinancialLedger;

use App\Platform\FinancialLedger\Money;
use OverflowException;
use Tests\Support\WeakMoneyCaller;
use Tests\TestCase;
use TypeError;

final class MoneyTest extends TestCase
{
    public function test_money_config_uses_idr_with_two_minor_units(): void
    {
        $this->assertSame('IDR', config('money.currency'));
        $this->assertSame(2, config('money.minor_units'));
    }

    public function test_from_decimal_converts_without_float_rounding(): void
    {
        $this->assertSame(15000025, Money::fromDecimal('150000.25'));
        $this->assertSame(1, Money::fromDecimal('0.01'));
        $this->assertSame(10, Money::fromDecimal('0.1'));
    }

    public function test_money_round_trips_an_integer_minor_value(): void
    {
        $money = new Money(15000025);

        $this->assertSame(15000025, $money->toMinorInt());
    }

    public function test_money_arithmetic_stays_in_integer_minor_units(): void
    {
        $money = new Money(15000000);

        $this->assertSame(15000025, $money->add(new Money(25))->toMinorInt());
        $this->assertSame(14999975, $money->subtract(new Money(25))->toMinorInt());
        $this->assertSame(-15000000, $money->negate()->toMinorInt());
        $this->assertSame(-1, $money->compare(new Money(15000025)));
        $this->assertTrue($money->isPositive());
    }

    public function test_format_uses_major_units_without_float_arithmetic(): void
    {
        $this->assertSame('Rp 150.000', (new Money(15000000))->format());
        $this->assertSame('Rp 150.000,25', (new Money(15000025))->format());
    }

    public function test_format_rejects_php_int_minimum_instead_of_negating_into_a_float(): void
    {
        $this->expectException(OverflowException::class);

        (new Money(PHP_INT_MIN))->format();
    }

    public function test_float_constructor_input_is_rejected_from_a_weak_caller(): void
    {
        $this->expectException(TypeError::class);

        WeakMoneyCaller::construct(1.5);
    }
}
