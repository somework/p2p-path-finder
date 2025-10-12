<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Filter;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Filter\CurrencyPairFilter;
use SomeWork\P2PPathFinder\Application\Filter\MaximumAmountFilter;
use SomeWork\P2PPathFinder\Application\Filter\MinimumAmountFilter;
use SomeWork\P2PPathFinder\Application\Filter\ToleranceWindowFilter;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

final class OrderFiltersTest extends TestCase
{
    public function test_filter_composes_multiple_criteria(): void
    {
        $book = new OrderBook([
            $this->createOrder('BTC', 'USD', '0.100', '1.000', '30000'),
            $this->createOrder('BTC', 'USD', '0.600', '2.000', '30500'),
            $this->createOrder('BTC', 'USD', '0.050', '0.400', '29900'),
            $this->createOrder('ETH', 'USD', '0.100', '5.000', '2000'),
        ]);

        $filters = [
            new CurrencyPairFilter(AssetPair::fromString('BTC', 'USD')),
            new MinimumAmountFilter(Money::fromString('BTC', '0.500', 3)),
            new MaximumAmountFilter(Money::fromString('BTC', '0.500', 3)),
        ];

        $filtered = iterator_to_array($book->filter(...$filters));

        self::assertCount(1, $filtered);
        self::assertTrue($filtered[0]->bounds()->min()->equals(Money::fromString('BTC', '0.100', 3)));
    }

    public function test_tolerance_window_filter_handles_rate_window(): void
    {
        $reference = ExchangeRate::fromString('BTC', 'USD', '30000', 2);
        $filter = new ToleranceWindowFilter($reference, '0.05');

        $matching = $this->createOrder('BTC', 'USD', '0.100', '1.000', '31500');
        $lowerEdge = $this->createOrder('BTC', 'USD', '0.100', '1.000', '28500');
        $outside = $this->createOrder('BTC', 'USD', '0.100', '1.000', '32000');
        $differentPair = $this->createOrder('ETH', 'USD', '0.100', '1.000', '31500');

        self::assertTrue($filter->accepts($matching));
        self::assertTrue($filter->accepts($lowerEdge));
        self::assertFalse($filter->accepts($outside));
        self::assertFalse($filter->accepts($differentPair));
    }

    public function test_tolerance_window_filter_rejects_negative_tolerance(): void
    {
        $reference = ExchangeRate::fromString('BTC', 'USD', '30000', 2);

        $this->expectException(InvalidArgumentException::class);

        new ToleranceWindowFilter($reference, '-0.01');
    }

    public function test_amount_filters_ignore_currency_mismatches(): void
    {
        $order = $this->createOrder('BTC', 'USD', '0.100', '1.000', '30000');
        $foreignMoney = Money::fromString('ETH', '1.000', 3);

        self::assertFalse((new MinimumAmountFilter($foreignMoney))->accepts($order));
        self::assertFalse((new MaximumAmountFilter($foreignMoney))->accepts($order));
    }

    /**
     * @param non-empty-string $base
     * @param non-empty-string $quote
     */
    private function createOrder(string $base, string $quote, string $min, string $max, string $rate): Order
    {
        $assetPair = AssetPair::fromString($base, $quote);
        $bounds = OrderBounds::from(
            Money::fromString($base, $min, 3),
            Money::fromString($base, $max, 3),
        );
        $exchangeRate = ExchangeRate::fromString($base, $quote, $rate, 2);

        return new Order(OrderSide::BUY, $assetPair, $bounds, $exchangeRate);
    }
}
