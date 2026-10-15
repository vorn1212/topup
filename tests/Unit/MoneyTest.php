<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_addition_has_no_float_error(): void
    {
        $this->assertSame('0.3000', Money::add('0.1', '0.2'));
    }

    public function test_subtraction_can_go_negative(): void
    {
        $this->assertSame('-1.5000', Money::sub('1', '2.5'));
        $this->assertTrue(Money::isNegative('-0.0001'));
    }

    public function test_multiplication_rounds_to_four_places(): void
    {
        $this->assertSame('1.0000', Money::mul('0.80', '1.25'));
        $this->assertSame('1.0001', Money::mul('0.33335', '3'));
    }

    public function test_division(): void
    {
        $this->assertSame('0.3333', Money::div('1', '3'));
    }

    public function test_division_by_zero_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::div('1', '0');
    }

    public function test_compare_ignores_trailing_zeros(): void
    {
        $this->assertSame(0, Money::cmp('1.0', '1.0000'));
        $this->assertSame(1, Money::cmp('1.0001', '1'));
    }

    public function test_format(): void
    {
        $this->assertSame('$1,234.57', Money::format('1234.565'));
        $this->assertSame('-$5.00', Money::format('-5'));
        $this->assertSame('$0.00', Money::format('0'));
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::add('abc', '1');
    }
}