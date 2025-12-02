<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Domain\Money;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalMath;
use SomeWork\P2PPathFinder\Tests\Helpers\MoneyAssertions;

use function str_repeat;

final class MoneyTest extends TestCase
{
    use MoneyAssertions;

    public function test_normalization_rounds_half_up(): void
    {
        $money = Money::fromString('usd', '1.23456', 4);

        self::assertSame('USD', $money->currency());
        self::assertMoneyAmount($money, '1.2346', 4);
    }

    public function test_decimal_accessor_reflects_amount(): void
    {
        $money = Money::fromString('usd', '42.4200', 4);

        self::assertMoneyAmount($money, '42.4200', 4);
    }

    public function test_from_string_rejects_empty_currency(): void
    {
        $this->expectException(InvalidInput::class);

        Money::fromString('', '1.00');
    }

    /**
     * @dataProvider provideMalformedCurrencies
     */
    public function test_from_string_rejects_malformed_currency(string $currency): void
    {
        $this->expectException(InvalidInput::class);

        Money::fromString($currency, '1.00');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedCurrencies(): iterable
    {
        yield 'too short' => ['US'];
        yield 'contains digits' => ['U5D'];
        yield 'contains symbols' => ['U$D'];
        yield 'contains whitespace' => ['U D'];
        yield 'excessively long' => [str_repeat('A', 13)];
    }

    /**
     * @dataProvider provideValidCurrencies
     */
    public function test_from_string_accepts_valid_currency(string $currency): void
    {
        $money = Money::fromString($currency, '5.00', 2);

        self::assertSame(strtoupper($currency), $money->currency());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideValidCurrencies(): iterable
    {
        yield 'lowercase' => ['usd'];
        yield 'uppercase' => ['JPY'];
        yield 'mixed case' => ['eUr'];
        yield 'extended length' => ['asset'];
        yield 'upper bound length' => [str_repeat('Z', 12)];
    }

    public function test_rejects_negative_amounts(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money amount cannot be negative');

        Money::fromString('USD', '-10.00', 2);
    }

    /**
     * @dataProvider provideNegativeAmounts
     */
    public function test_rejects_various_negative_amounts(string $amount, int $scale): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money amount cannot be negative');

        Money::fromString('USD', $amount, $scale);
    }

    /**
     * @return iterable<string, array{amount: string, scale: int}>
     */
    public static function provideNegativeAmounts(): iterable
    {
        yield 'negative with decimals' => ['amount' => '-10.50', 'scale' => 2];
        yield 'negative integer' => ['amount' => '-100', 'scale' => 0];
        yield 'small negative' => ['amount' => '-0.01', 'scale' => 2];
        yield 'large negative' => ['amount' => '-999999999.99', 'scale' => 2];
        yield 'negative with high precision' => ['amount' => '-0.00000001', 'scale' => 8];
    }

    /**
     * @dataProvider provideInvalidAmountStrings
     */
    public function test_from_string_rejects_invalid_amount_strings(string $amount): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('is not numeric');

        Money::fromString('USD', $amount);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidAmountStrings(): iterable
    {
        yield 'empty string' => [''];
        yield 'non-numeric' => ['abc'];
        yield 'mixed alpha-numeric' => ['123abc'];
        yield 'special characters' => ['123.45$'];
        yield 'multiple decimals' => ['123.45.67'];
        yield 'leading/trailing spaces' => [' 123.45 '];
        yield 'hexadecimal' => ['0xFF'];
    }

    public function test_allows_zero_amount(): void
    {
        $money = Money::fromString('USD', '0.00', 2);

        $this->assertSame('0.00', $money->amount());
        $this->assertTrue($money->isZero());
    }

    public function test_allows_positive_amounts(): void
    {
        $money = Money::fromString('USD', '100.50', 2);

        $this->assertSame('100.50', $money->amount());
        $this->assertFalse($money->isZero());
    }

    public function test_zero_from_constructor_is_non_negative(): void
    {
        $zero = Money::zero('EUR', 2);

        $this->assertSame('0.00', $zero->amount());
        $this->assertTrue($zero->isZero());
    }

    public function test_add_and_subtract_respect_scale(): void
    {
        $a = Money::fromString('EUR', '10.5', 2);
        $b = Money::fromString('EUR', '2.345', 3);

        $sum = $a->add($b);
        self::assertMoneyAmount($sum, '12.845', 3);

        $difference = $sum->subtract($b);
        self::assertTrue($difference->equals($a->withScale(3)));
    }

    public function test_multiply_rounds_result(): void
    {
        $money = Money::fromString('GBP', '12.00', 2);

        $result = $money->multiply('1.157', 2);

        self::assertMoneyAmount($result, '13.88', 2);
    }

    public function test_divide_rounds_result_and_honours_custom_scale(): void
    {
        $money = Money::fromString('USD', '100.00', 2);

        $result = $money->divide('3', 4);

        self::assertMoneyAmount($result, '33.3333', 4);
        self::assertSame(0, $result->compare(Money::fromString('USD', '33.3333', 4), 4));
    }

    /**
     * @dataProvider provideInvalidDivisors
     */
    public function test_divide_rejects_invalid_divisors(string $divisor): void
    {
        $money = Money::fromString('USD', '10.00', 2);

        $this->expectException(InvalidInput::class);

        $money->divide($divisor);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidDivisors(): iterable
    {
        yield 'non-numeric' => ['foo'];
        yield 'zero' => ['0'];
    }

    public function test_is_zero_respects_scale(): void
    {
        $zero = Money::fromString('JPY', '0.000', 3);
        $alsoZero = Money::fromString('JPY', '0', 0);
        $nonZero = Money::fromString('JPY', '0.001', 3);

        self::assertTrue($zero->isZero());
        self::assertTrue($alsoZero->isZero());
        self::assertFalse($nonZero->isZero());
    }

    public function test_with_scale_returns_same_instance_when_unchanged(): void
    {
        $money = Money::fromString('AUD', '42.42', 2);

        self::assertSame($money, $money->withScale(2));
    }

    public function test_decimal_accessor_returns_bigdecimal_representation(): void
    {
        $money = Money::fromString('USD', '123.456', 3);

        $decimal = $money->decimal();

        // Verify it's a BigDecimal instance
        self::assertInstanceOf(BigDecimal::class, $decimal);

        // Verify it represents the correct value
        self::assertTrue(DecimalMath::decimal('123.456', 3)->isEqualTo($decimal));

        // Verify scale is preserved
        self::assertSame(3, $decimal->getScale());
    }

    public function test_compare_detects_order(): void
    {
        $low = Money::fromString('CHF', '99.999', 3);
        $high = Money::fromString('CHF', '100.001', 3);

        self::assertTrue($low->lessThan($high));
        self::assertTrue($high->greaterThan($low));
        self::assertFalse($low->equals($high));
    }

    public function test_currency_mismatch_throws_exception(): void
    {
        $this->expectException(InvalidInput::class);

        Money::fromString('USD', '1.00')->add(Money::fromString('EUR', '1.00'));
    }

    // ==================== Scale Boundary Tests ====================

    public function test_scale_zero_allows_integer_amounts(): void
    {
        $money = Money::fromString('USD', '100', 0);

        $this->assertSame('100', $money->amount());
        $this->assertSame(0, $money->scale());
        $this->assertSame('USD', $money->currency());
    }

    public function test_scale_zero_rounds_decimals_to_integer(): void
    {
        $money = Money::fromString('JPY', '123.6', 0);

        $this->assertSame('124', $money->amount());
        $this->assertSame(0, $money->scale());
    }

    public function test_scale_maximum_allows_fifty_decimals(): void
    {
        $amount = '1.12345678901234567890123456789012345678901234567890';
        $money = Money::fromString('BTC', $amount, 50);

        $this->assertSame(50, $money->scale());
        $this->assertSame('BTC', $money->currency());
        $this->assertSame('1.12345678901234567890123456789012345678901234567890', $money->amount());
    }

    public function test_scale_maximum_handles_rounding_at_boundary(): void
    {
        // Input has 51 decimals, should round to 50 using HALF_UP
        $amount = '1.123456789012345678901234567890123456789012345678905';
        $money = Money::fromString('ETH', $amount, 50);

        $this->assertSame(50, $money->scale());
        // The 51st digit is 5, so HALF_UP rounds up the 50th digit
        $this->assertSame('1.12345678901234567890123456789012345678901234567891', $money->amount());
    }

    public function test_negative_scale_throws_exception(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Scale cannot be negative');

        Money::fromString('USD', '100.00', -1);
    }

    public function test_scale_above_maximum_throws_exception(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Scale cannot exceed 50 decimal places');

        Money::fromString('USD', '100.00', 51);
    }

    public function test_scale_boundary_with_scale_method(): void
    {
        $money = Money::fromString('USD', '100.123', 3);

        // Scale 0 should work
        $scaleZero = $money->withScale(0);
        $this->assertSame('100', $scaleZero->amount());

        // Scale 50 should work
        $scaleFifty = $money->withScale(50);
        $this->assertSame(50, $scaleFifty->scale());
    }

    public function test_with_scale_rejects_negative_scale(): void
    {
        $money = Money::fromString('USD', '100.00', 2);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Scale cannot be negative');

        $money->withScale(-1);
    }

    public function test_with_scale_rejects_scale_above_maximum(): void
    {
        $money = Money::fromString('USD', '100.00', 2);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Scale cannot exceed 50 decimal places');

        $money->withScale(51);
    }

    public function test_arithmetic_operations_work_at_scale_boundaries(): void
    {
        // Scale 0
        $a = Money::fromString('USD', '100', 0);
        $b = Money::fromString('USD', '50', 0);
        $sum = $a->add($b);
        $this->assertSame('150', $sum->amount());

        // Scale 50
        $c = Money::fromString('BTC', '1.'.str_repeat('1', 50), 50);
        $d = Money::fromString('BTC', '2.'.str_repeat('2', 50), 50);
        $sumHigh = $c->add($d);
        $this->assertSame(50, $sumHigh->scale());
    }

    // ==================== Extreme Value Tests ====================

    public function test_very_large_amount(): void
    {
        // Test very large amounts (hundreds of digits)
        $veryLargeAmount = '999999999999999999999999999.99';
        $money = Money::fromString('USD', $veryLargeAmount, 2);

        $this->assertSame('USD', $money->currency());
        $this->assertSame('999999999999999999999999999.99', $money->amount());
        $this->assertSame(2, $money->scale());
        $this->assertFalse($money->isZero());

        // Test even larger amount with high precision
        $extremelyLarge = '123456789012345678901234567890123456789012345678901234567890.12345678901234567890123456789012345678901234567890';
        $largeWithScale = Money::fromString('ASSET', $extremelyLarge, 50);

        $this->assertSame(50, $largeWithScale->scale());
        $this->assertSame('123456789012345678901234567890123456789012345678901234567890.12345678901234567890123456789012345678901234567890', $largeWithScale->amount());

        // Test that large amounts can be compared
        $smaller = Money::fromString('USD', '999999999999999999999999999.98', 2);
        $larger = Money::fromString('USD', '999999999999999999999999999.99', 2);

        $this->assertTrue($larger->greaterThan($smaller));
        $this->assertTrue($smaller->lessThan($larger));
        $this->assertFalse($larger->equals($smaller));
    }

    public function test_very_small_amount(): void
    {
        // Test very small amounts with high precision scale
        $verySmallAmount = '0.00000000000000000001';
        $money = Money::fromString('BTC', $verySmallAmount, 20);

        $this->assertSame('BTC', $money->currency());
        $this->assertSame('0.00000000000000000001', $money->amount());
        $this->assertSame(20, $money->scale());
        $this->assertFalse($money->isZero());

        // Test extremely small amount at maximum scale (50)
        $extremelySmall = '0.'.str_repeat('0', 49).'1';
        $smallWithMaxScale = Money::fromString('ETH', $extremelySmall, 50);

        $this->assertSame(50, $smallWithMaxScale->scale());
        $this->assertSame($extremelySmall, $smallWithMaxScale->amount());
        $this->assertFalse($smallWithMaxScale->isZero());

        // Test that very small amounts can be compared
        $smaller = Money::fromString('BTC', '0.00000000000000000001', 20);
        $larger = Money::fromString('BTC', '0.00000000000000000002', 20);

        $this->assertTrue($larger->greaterThan($smaller));
        $this->assertTrue($smaller->lessThan($larger));

        // Test that rounding to lower scale can result in zero
        $tinyAmount = Money::fromString('USD', '0.00000001', 8);
        $roundedDown = $tinyAmount->withScale(2);
        $this->assertTrue($roundedDown->isZero());
        $this->assertSame('0.00', $roundedDown->amount());
    }

    public function test_arithmetic_with_extreme_values(): void
    {
        // Test addition with very large amounts
        $large1 = Money::fromString('USD', '999999999999999999999999999.99', 2);
        $large2 = Money::fromString('USD', '111111111111111111111111111.11', 2);
        $largeSum = $large1->add($large2);

        $this->assertSame('1111111111111111111111111111.10', $largeSum->amount());
        $this->assertSame(2, $largeSum->scale());

        // Test subtraction with very large amounts
        $largeDiff = $largeSum->subtract($large2);
        $this->assertTrue($largeDiff->equals($large1));

        // Test multiplication with large amounts
        $largeProduct = Money::fromString('USD', '999999999999999999.99', 2)
            ->multiply('1.5', 2);
        $this->assertSame('1499999999999999999.99', $largeProduct->amount());

        // Test division with large amounts
        $largeQuotient = Money::fromString('USD', '999999999999999999.99', 2)
            ->divide('3', 2);
        $this->assertSame('333333333333333333.33', $largeQuotient->amount());

        // Test addition with very small amounts
        $small1 = Money::fromString('BTC', '0.00000000000000000001', 20);
        $small2 = Money::fromString('BTC', '0.00000000000000000002', 20);
        $smallSum = $small1->add($small2);

        $this->assertSame('0.00000000000000000003', $smallSum->amount());
        $this->assertSame(20, $smallSum->scale());

        // Test subtraction with very small amounts
        $smallDiff = $smallSum->subtract($small1);
        $this->assertTrue($smallDiff->equals($small2));

        // Test multiplication with very small amounts
        $smallProduct = Money::fromString('BTC', '0.00000000000000000001', 20)
            ->multiply('2', 20);
        $this->assertSame('0.00000000000000000002', $smallProduct->amount());

        // Test division with very small amounts
        $smallQuotient = Money::fromString('BTC', '0.00000000000000000002', 20)
            ->divide('2', 20);
        $this->assertSame('0.00000000000000000001', $smallQuotient->amount());

        // Test mixed operations: large multiplied by very small
        $large = Money::fromString('USD', '1000000000000', 10);
        $result = $large->multiply('0.0000000001', 10);
        $this->assertSame('100.0000000000', $result->amount());

        // Test that precision is maintained across multiple operations
        $base = Money::fromString('ETH', '123.456789012345', 12);
        $doubled = $base->multiply('2', 12);
        $halved = $doubled->divide('2', 12);
        $this->assertTrue($halved->equals($base));

        // Test extreme boundary: maximum scale with complex arithmetic
        $a = Money::fromString('CRYPTO', '1.'.str_repeat('1', 50), 50);
        $b = Money::fromString('CRYPTO', '2.'.str_repeat('2', 50), 50);
        $complexSum = $a->add($b);

        // Verify the result has correct scale
        $this->assertSame(50, $complexSum->scale());

        // Verify arithmetic integrity: (a + b) - a should equal b
        $difference = $complexSum->subtract($a);
        $this->assertTrue($difference->equals($b));
    }

    // ==================== Scale Mismatch Arithmetic Tests ====================

    public function test_addition_with_different_scales(): void
    {
        // Test scale=2 + scale=8 (should use max scale = 8)
        $a = Money::fromString('USD', '100.50', 2);
        $b = Money::fromString('USD', '25.12345678', 8);
        $sum = $a->add($b);

        $this->assertSame('125.62345678', $sum->amount());
        $this->assertSame(8, $sum->scale());
        $this->assertSame('USD', $sum->currency());

        // Test scale=0 + scale=4 (should use max scale = 4)
        $c = Money::fromString('JPY', '1000', 0);
        $d = Money::fromString('JPY', '0.5555', 4);
        $sum2 = $c->add($d);

        $this->assertSame('1000.5555', $sum2->amount());
        $this->assertSame(4, $sum2->scale());

        // Test scale=4 + scale=2 (should use max scale = 4)
        $e = Money::fromString('EUR', '99.9999', 4);
        $f = Money::fromString('EUR', '0.01', 2);
        $sum3 = $e->add($f);

        $this->assertSame('100.0099', $sum3->amount());
        $this->assertSame(4, $sum3->scale());

        // Test explicit scale override (lower than both operands)
        $g = Money::fromString('GBP', '10.12345', 5);
        $h = Money::fromString('GBP', '20.67890', 5);
        $sum4 = $g->add($h, 2);

        $this->assertSame('30.80', $sum4->amount());
        $this->assertSame(2, $sum4->scale());

        // Test explicit scale override (higher than both operands)
        $i = Money::fromString('CHF', '5.5', 1);
        $j = Money::fromString('CHF', '4.4', 1);
        $sum5 = $i->add($j, 6);

        $this->assertSame('9.900000', $sum5->amount());
        $this->assertSame(6, $sum5->scale());
    }

    public function test_subtraction_with_different_scales(): void
    {
        // Test scale=8 - scale=2 (should use max scale = 8)
        $a = Money::fromString('BTC', '1.50000000', 8);
        $b = Money::fromString('BTC', '0.25', 2);
        $diff = $a->subtract($b);

        $this->assertSame('1.25000000', $diff->amount());
        $this->assertSame(8, $diff->scale());

        // Test scale=2 - scale=8 (should use max scale = 8)
        $c = Money::fromString('ETH', '100.50', 2);
        $d = Money::fromString('ETH', '25.12345678', 8);
        $diff2 = $c->subtract($d);

        $this->assertSame('75.37654322', $diff2->amount());
        $this->assertSame(8, $diff2->scale());

        // Test scale=0 - scale=3 (should use max scale = 3)
        $e = Money::fromString('USD', '1000', 0);
        $f = Money::fromString('USD', '0.999', 3);
        $diff3 = $e->subtract($f);

        $this->assertSame('999.001', $diff3->amount());
        $this->assertSame(3, $diff3->scale());

        // Test explicit scale override
        $g = Money::fromString('EUR', '100.123456', 6);
        $h = Money::fromString('EUR', '50.5', 1);
        $diff4 = $g->subtract($h, 3);

        $this->assertSame('49.623', $diff4->amount());
        $this->assertSame(3, $diff4->scale());

        // Test subtraction resulting in very small difference
        $i = Money::fromString('USD', '10.00000001', 8);
        $j = Money::fromString('USD', '10.00', 2);
        $diff5 = $i->subtract($j);

        $this->assertSame('0.00000001', $diff5->amount());
        $this->assertSame(8, $diff5->scale());
        $this->assertFalse($diff5->isZero());
    }

    public function test_multiplication_with_different_scales(): void
    {
        // Multiply uses the scale of the Money instance by default
        $a = Money::fromString('USD', '100.50', 2);
        $result1 = $a->multiply('1.5');

        $this->assertSame('150.75', $result1->amount());
        $this->assertSame(2, $result1->scale());

        // Test with higher scale on Money instance
        $b = Money::fromString('BTC', '0.12345678', 8);
        $result2 = $b->multiply('2.5');

        $this->assertSame('0.30864195', $result2->amount());
        $this->assertSame(8, $result2->scale());

        // Test with explicit scale override (lower)
        $c = Money::fromString('ETH', '123.456789', 6);
        $result3 = $c->multiply('1.111111', 2);

        $this->assertSame('137.17', $result3->amount());
        $this->assertSame(2, $result3->scale());

        // Test with explicit scale override (higher)
        $d = Money::fromString('EUR', '50.5', 1);
        $result4 = $d->multiply('1.23456789', 10);

        $this->assertSame('62.3456784450', $result4->amount());
        $this->assertSame(10, $result4->scale());

        // Test multiplication with very precise multiplier
        $e = Money::fromString('USD', '1.00', 2);
        $result5 = $e->multiply('1.005', 4);

        $this->assertSame('1.0050', $result5->amount());
        $this->assertSame(4, $result5->scale());

        // Test that scale=0 still works correctly
        $f = Money::fromString('JPY', '1000', 0);
        $result6 = $f->multiply('1.5');

        $this->assertSame('1500', $result6->amount());
        $this->assertSame(0, $result6->scale());
    }

    public function test_division_with_different_scales(): void
    {
        // Divide uses the scale of the Money instance by default
        $a = Money::fromString('USD', '100.00', 2);
        $result1 = $a->divide('3');

        $this->assertSame('33.33', $result1->amount());
        $this->assertSame(2, $result1->scale());

        // Test with higher scale on Money instance
        $b = Money::fromString('BTC', '1.00000000', 8);
        $result2 = $b->divide('3');

        $this->assertSame('0.33333333', $result2->amount());
        $this->assertSame(8, $result2->scale());

        // Test with explicit scale override (lower)
        $c = Money::fromString('ETH', '100.123456', 6);
        $result3 = $c->divide('7', 2);

        $this->assertSame('14.30', $result3->amount());
        $this->assertSame(2, $result3->scale());

        // Test with explicit scale override (higher)
        $d = Money::fromString('EUR', '50.5', 1);
        $result4 = $d->divide('3', 10);

        $this->assertSame('16.8333333333', $result4->amount());
        $this->assertSame(10, $result4->scale());

        // Test division with very small divisor (large result)
        $e = Money::fromString('USD', '1.00', 2);
        $result5 = $e->divide('0.01', 2);

        $this->assertSame('100.00', $result5->amount());
        $this->assertSame(2, $result5->scale());

        // Test that scale=0 rounds to integer
        $f = Money::fromString('JPY', '1000', 0);
        $result6 = $f->divide('3');

        $this->assertSame('333', $result6->amount());
        $this->assertSame(0, $result6->scale());
    }

    public function test_scale_derivation_rules(): void
    {
        // Rule 1: Addition/Subtraction use max(left.scale, right.scale)
        $low = Money::fromString('USD', '10.5', 1);
        $high = Money::fromString('USD', '20.12345', 5);

        $addResult = $low->add($high);
        $this->assertSame(5, $addResult->scale());
        $this->assertSame('30.62345', $addResult->amount());

        $subResult = $high->subtract($low);
        $this->assertSame(5, $subResult->scale());
        $this->assertSame('9.62345', $subResult->amount());

        // Rule 2: Multiplication/Division use left.scale
        $money = Money::fromString('EUR', '100.00', 2);

        $multResult = $money->multiply('1.123456789');
        $this->assertSame(2, $multResult->scale());
        $this->assertSame('112.35', $multResult->amount());

        $divResult = $money->divide('3.123456789');
        $this->assertSame(2, $divResult->scale());
        $this->assertSame('32.02', $divResult->amount());

        // Rule 3: Explicit scale override takes precedence
        $a = Money::fromString('BTC', '1.12345678', 8);
        $b = Money::fromString('BTC', '2.34', 2);

        $explicitAdd = $a->add($b, 4);
        $this->assertSame(4, $explicitAdd->scale());
        $this->assertSame('3.4635', $explicitAdd->amount());

        $explicitMult = $a->multiply('2', 10);
        $this->assertSame(10, $explicitMult->scale());
        $this->assertSame('2.2469135600', $explicitMult->amount());

        // Rule 4: Verify scale propagation in chained operations
        $base = Money::fromString('USD', '100', 0);
        $add1 = Money::fromString('USD', '0.5', 1);
        $add2 = Money::fromString('USD', '0.25', 2);

        // First addition: max(0, 1) = 1
        $chain1 = $base->add($add1);
        $this->assertSame(1, $chain1->scale());
        $this->assertSame('100.5', $chain1->amount());

        // Second addition: max(1, 2) = 2
        $chain2 = $chain1->add($add2);
        $this->assertSame(2, $chain2->scale());
        $this->assertSame('100.75', $chain2->amount());

        // Rule 5: Compare operation respects scale
        $x = Money::fromString('CHF', '10.1', 1);
        $y = Money::fromString('CHF', '10.10', 2);
        $this->assertTrue($x->equals($y));

        $z = Money::fromString('CHF', '10.101', 3);
        $this->assertFalse($x->equals($z));
        $this->assertTrue($z->greaterThan($x));
    }
}
