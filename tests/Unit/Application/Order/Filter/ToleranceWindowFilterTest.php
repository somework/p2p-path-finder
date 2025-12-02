<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\Order\Filter;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SomeWork\P2PPathFinder\Application\Order\Filter\ToleranceWindowFilter;
use SomeWork\P2PPathFinder\Domain\Money\AssetPair;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBounds;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class ToleranceWindowFilterTest extends TestCase
{
    public function test_accepts_order_within_tolerance_window(): void
    {
        $filter = new ToleranceWindowFilter(
            ExchangeRate::fromString('AAA', 'BBB', '100.0000', 4),
            '0.05',
        );

        $order = $this->createOrder('AAA', 'BBB', '100.5000');

        self::assertTrue($filter->accepts($order));
    }

    public function test_rejects_order_outside_upper_tolerance(): void
    {
        $filter = new ToleranceWindowFilter(
            ExchangeRate::fromString('AAA', 'BBB', '50.0000', 4),
            '0.02',
        );

        $order = $this->createOrder('AAA', 'BBB', '52.0000');

        self::assertFalse($filter->accepts($order));
    }

    public function test_rejects_order_outside_lower_tolerance(): void
    {
        $filter = new ToleranceWindowFilter(
            ExchangeRate::fromString('AAA', 'BBB', '20.0000', 4),
            '0.05',
        );

        $order = $this->createOrder('AAA', 'BBB', '18.5000');

        self::assertFalse($filter->accepts($order));
    }

    public function test_rejects_order_with_mismatched_currency_pair(): void
    {
        $filter = new ToleranceWindowFilter(
            ExchangeRate::fromString('AAA', 'BBB', '10.0000', 4),
            '0.10',
        );

        $order = $this->createOrder('AAA', 'CCC', '10.5000');

        self::assertFalse($filter->accepts($order));
    }

    public function test_constructor_rejects_negative_tolerance(): void
    {
        $this->expectException(InvalidInput::class);

        new ToleranceWindowFilter(
            ExchangeRate::fromString('AAA', 'BBB', '10.0000', 4),
            '-0.01',
        );
    }

    // ==================== Edge Case Tests ====================

    public function test_tolerance_with_extreme_precision(): void
    {
        // Test with scale 18 (canonical scale)
        $filter = new ToleranceWindowFilter(
            ExchangeRate::fromString('ETH', 'BTC', '0.045678901234567890', 18),
            '0.000000000000000001', // Extremely tight tolerance
        );

        // Exact match
        $exactOrder = $this->createOrderWithScale('ETH', 'BTC', '0.045678901234567890', 18);
        self::assertTrue($filter->accepts($exactOrder));

        // Slightly different - outside tolerance
        $differentOrder = $this->createOrderWithScale('ETH', 'BTC', '0.045678901234567891', 18);
        self::assertFalse($filter->accepts($differentOrder));
    }

    public function test_tolerance_with_zero_amount(): void
    {
        // Reference rate of 0 should still work (though unusual)
        $filter = new ToleranceWindowFilter(
            ExchangeRate::fromString('AAA', 'BBB', '0.0001', 4),
            '0.50', // 50% tolerance
        );

        // Within tolerance: 0.0001 ± 50% = [0.00005, 0.00015]
        $withinTolerance = $this->createOrder('AAA', 'BBB', '0.0001');
        self::assertTrue($filter->accepts($withinTolerance));
    }

    public function test_tolerance_boundary_rounding_behavior(): void
    {
        // Test rounding at tolerance boundaries with scale 2
        $filter = new ToleranceWindowFilter(
            ExchangeRate::fromString('BTC', 'USD', '30000.00', 2),
            '0.05', // 5% = ±1500
        );

        // Boundaries are 28500.00 and 31500.00
        // Test values that would round differently
        $atLowerBoundary = $this->createOrder('BTC', 'USD', '28500.00');
        $atUpperBoundary = $this->createOrder('BTC', 'USD', '31500.00');

        self::assertTrue($filter->accepts($atLowerBoundary));
        self::assertTrue($filter->accepts($atUpperBoundary));

        // Just outside boundaries
        $belowLower = $this->createOrder('BTC', 'USD', '28499.99');
        $aboveUpper = $this->createOrder('BTC', 'USD', '31500.01');

        self::assertFalse($filter->accepts($belowLower));
        self::assertFalse($filter->accepts($aboveUpper));
    }

    public function test_tolerance_with_multiple_scales(): void
    {
        // Reference at scale 2
        $filter = new ToleranceWindowFilter(
            ExchangeRate::fromString('BTC', 'USD', '30000.00', 2),
            '0.10', // 10% = ±3000
        );

        // Order at scale 8 (high precision)
        $highPrecisionOrder = $this->createOrderWithScale('BTC', 'USD', '30100.00000000', 8);
        self::assertTrue($filter->accepts($highPrecisionOrder));

        // Order at scale 0 (no decimals)
        $lowPrecisionOrder = $this->createOrderWithScale('BTC', 'USD', '30100', 0);
        self::assertTrue($filter->accepts($lowPrecisionOrder));
    }

    public function test_tolerance_with_very_large_reference_rate(): void
    {
        // Test with very large numbers
        $filter = new ToleranceWindowFilter(
            ExchangeRate::fromString('HUGE', 'TINY', '999999999.99', 2),
            '0.01', // 1%
        );

        // Within tolerance
        $withinOrder = $this->createOrder('HUGE', 'TINY', '999999999.00');
        self::assertTrue($filter->accepts($withinOrder));

        // Outside tolerance
        $outsideOrder = $this->createOrder('HUGE', 'TINY', '1010000000.00');
        self::assertFalse($filter->accepts($outsideOrder));
    }

    public function test_tolerance_with_very_small_reference_rate(): void
    {
        // Test with very small numbers at high precision
        $filter = new ToleranceWindowFilter(
            ExchangeRate::fromString('SMALL', 'LARGE', '0.000000000000000001', 18),
            '0.50', // 50%
        );

        // Within tolerance
        $withinOrder = $this->createOrderWithScale('SMALL', 'LARGE', '0.0000000000000000015', 19);
        self::assertTrue($filter->accepts($withinOrder));
    }

    public function test_tolerance_zero_with_exact_match(): void
    {
        // Zero tolerance = only exact matches
        $filter = new ToleranceWindowFilter(
            ExchangeRate::fromString('AAA', 'BBB', '100.0000', 4),
            '0.0000',
        );

        $exactOrder = $this->createOrder('AAA', 'BBB', '100.0000');
        self::assertTrue($filter->accepts($exactOrder));

        // Even tiny difference should be rejected
        $slightlyDifferent = $this->createOrder('AAA', 'BBB', '100.0001');
        self::assertFalse($filter->accepts($slightlyDifferent));
    }

    public function test_tolerance_clamping_to_zero_lower_bound(): void
    {
        // Large tolerance that would create negative lower bound
        $filter = new ToleranceWindowFilter(
            ExchangeRate::fromString('AAA', 'BBB', '10.00', 2),
            '2.00', // 200% tolerance
        );

        // Lower bound should be clamped to 0, not -10
        // So rates from 0 to 30 should be accepted
        $veryLowOrder = $this->createOrder('AAA', 'BBB', '0.01');
        self::assertTrue($filter->accepts($veryLowOrder));

        $highOrder = $this->createOrder('AAA', 'BBB', '30.00');
        self::assertTrue($filter->accepts($highOrder));

        $tooHighOrder = $this->createOrder('AAA', 'BBB', '30.01');
        self::assertFalse($filter->accepts($tooHighOrder));
    }

    // ==================== Helper Methods ====================

    private function createOrder(string $base, string $quote, string $rate): Order
    {
        return new Order(
            OrderSide::BUY,
            AssetPair::fromString($base, $quote),
            OrderBounds::from(
                Money::fromString($base, '1.0000', 4),
                Money::fromString($base, '5.0000', 4),
            ),
            ExchangeRate::fromString($base, $quote, $rate, 4),
        );
    }

    /**
     * @dataProvider provideToleranceWindowCandidates
     */
    public function test_tolerance_window_filter_handles_rate_window(Order $order, bool $expected): void
    {
        $filter = new ToleranceWindowFilter(CurrencyScenarioFactory::exchangeRate('BTC', 'USD', '30000', 2), '0.05');

        self::assertSame($expected, $filter->accepts($order));
    }

    /**
     * @return iterable<string, array{Order, bool}>
     */
    public static function provideToleranceWindowCandidates(): iterable
    {
        yield 'upper boundary inside window' => [OrderFactory::buy(rate: '31500'), true];
        yield 'lower boundary inside window' => [OrderFactory::buy(rate: '28500'), true];
        yield 'outside tolerance window' => [OrderFactory::buy(rate: '32000'), false];
        yield 'different asset pair' => [OrderFactory::buy(base: 'ETH', rate: '31500'), false];
    }

    public function test_tolerance_window_filter_rejects_negative_tolerance(): void
    {
        $reference = CurrencyScenarioFactory::exchangeRate('BTC', 'USD', '30000', 2);

        $this->expectException(InvalidInput::class);

        new ToleranceWindowFilter($reference, '-0.01');
    }

    public function test_tolerance_window_filter_requires_numeric_tolerance(): void
    {
        $reference = CurrencyScenarioFactory::exchangeRate('BTC', 'USD', '30000', 2);

        $this->expectException(InvalidInput::class);

        new ToleranceWindowFilter($reference, 'not-a-number');
    }

    public function test_tolerance_window_filter_clamps_negative_bounds_to_zero(): void
    {
        $reference = CurrencyScenarioFactory::exchangeRate('BTC', 'USD', '100.00', 2);

        $filter = new ToleranceWindowFilter($reference, '1.50');

        $lowerBound = new ReflectionProperty(ToleranceWindowFilter::class, 'lowerBound');
        $lowerBound->setAccessible(true);

        $value = $lowerBound->getValue($filter);

        self::assertInstanceOf(BigDecimal::class, $value);
        self::assertSame('0.00', $value->__toString());
    }

    public function test_tolerance_window_filter_with_zero_tolerance(): void
    {
        $reference = CurrencyScenarioFactory::exchangeRate('BTC', 'USD', '30000.00', 2);
        $filter = new ToleranceWindowFilter($reference, '0.00');

        $exactOrder = OrderFactory::buy(rate: '30000.00');
        $differentOrder = OrderFactory::buy(rate: '30000.01');

        self::assertTrue($filter->accepts($exactOrder));
        self::assertFalse($filter->accepts($differentOrder));
    }

    public function test_tolerance_window_filter_with_very_wide_tolerance(): void
    {
        $reference = CurrencyScenarioFactory::exchangeRate('BTC', 'USD', '30000.00', 2);
        $filter = new ToleranceWindowFilter($reference, '10.00');

        $order = OrderFactory::buy(rate: '100000.00');

        self::assertTrue($filter->accepts($order));
    }

    private function createOrderWithScale(string $base, string $quote, string $rate, int $scale): Order
    {
        return new Order(
            OrderSide::BUY,
            AssetPair::fromString($base, $quote),
            OrderBounds::from(
                Money::fromString($base, '1', $scale),
                Money::fromString($base, '5', $scale),
            ),
            ExchangeRate::fromString($base, $quote, $rate, $scale),
        );
    }
}
