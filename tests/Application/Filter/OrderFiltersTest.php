<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Filter;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SomeWork\P2PPathFinder\Application\Filter\CurrencyPairFilter;
use SomeWork\P2PPathFinder\Application\Filter\MaximumAmountFilter;
use SomeWork\P2PPathFinder\Application\Filter\MinimumAmountFilter;
use SomeWork\P2PPathFinder\Application\Filter\ToleranceWindowFilter;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class OrderFiltersTest extends TestCase
{
    public function test_filter_composes_multiple_criteria(): void
    {
        $book = new OrderBook([
            OrderFactory::buy(),
            OrderFactory::buy(minAmount: '0.600', maxAmount: '2.000', rate: '30500'),
            OrderFactory::buy(minAmount: '0.050', maxAmount: '0.400', rate: '29900'),
            OrderFactory::buy(base: 'ETH', rate: '2000'),
        ]);

        $filters = [
            new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD')),
            new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.500', 3)),
            new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.500', 3)),
        ];

        $filtered = iterator_to_array($book->filter(...$filters));

        self::assertCount(1, $filtered);
        self::assertTrue($filtered[0]->bounds()->min()->equals(CurrencyScenarioFactory::money('BTC', '0.100', 3)));
    }

    public function test_currency_pair_filter_requires_exact_match(): void
    {
        $filter = new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD'));

        self::assertFalse($filter->accepts(OrderFactory::buy(base: 'BTC', quote: 'EUR')));
        self::assertFalse($filter->accepts(OrderFactory::buy(base: 'ETH', quote: 'USD')));
        self::assertTrue($filter->accepts(OrderFactory::buy()));
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

    public function test_amount_filters_ignore_currency_mismatches(): void
    {
        $order = OrderFactory::buy();
        $foreignMoney = CurrencyScenarioFactory::money('ETH', '1.000', 3);

        self::assertFalse((new MinimumAmountFilter($foreignMoney))->accepts($order));
        self::assertFalse((new MaximumAmountFilter($foreignMoney))->accepts($order));
    }

    public function test_minimum_amount_filter_accepts_order_at_exact_boundary(): void
    {
        $order = OrderFactory::buy(minAmount: '0.100', maxAmount: '1.000');
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.100', 3));

        self::assertTrue($filter->accepts($order));
    }

    public function test_minimum_amount_filter_rejects_order_above_boundary(): void
    {
        $order = OrderFactory::buy(minAmount: '0.101', maxAmount: '1.000');
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.100', 3));

        self::assertFalse($filter->accepts($order));
    }

    public function test_maximum_amount_filter_accepts_order_at_exact_boundary(): void
    {
        $order = OrderFactory::buy(minAmount: '0.100', maxAmount: '1.000');
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.000', 3));

        self::assertTrue($filter->accepts($order));
    }

    public function test_maximum_amount_filter_rejects_order_below_boundary(): void
    {
        $order = OrderFactory::buy(minAmount: '0.100', maxAmount: '0.999');
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.000', 3));

        self::assertFalse($filter->accepts($order));
    }

    public function test_filter_returns_empty_when_all_orders_rejected(): void
    {
        $book = new OrderBook([
            OrderFactory::buy(minAmount: '0.100', maxAmount: '0.500'),
            OrderFactory::buy(minAmount: '0.200', maxAmount: '0.600'),
        ]);

        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.000', 3));
        $filtered = iterator_to_array($book->filter($filter));

        self::assertCount(0, $filtered);
    }

    public function test_filter_accepts_all_orders_when_all_pass(): void
    {
        $first = OrderFactory::buy(minAmount: '0.100', maxAmount: '1.000');
        $second = OrderFactory::buy(minAmount: '0.200', maxAmount: '2.000');
        $third = OrderFactory::buy(minAmount: '0.300', maxAmount: '3.000');

        $book = new OrderBook([$first, $second, $third]);

        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.500', 3));
        $filtered = iterator_to_array($book->filter($filter));

        self::assertCount(3, $filtered);
        self::assertSame([$first, $second, $third], $filtered);
    }

    public function test_filter_handles_empty_order_book(): void
    {
        $book = new OrderBook([]);

        $filter = new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD'));
        $filtered = iterator_to_array($book->filter($filter));

        self::assertCount(0, $filtered);
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
}
