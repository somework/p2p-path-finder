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
}
