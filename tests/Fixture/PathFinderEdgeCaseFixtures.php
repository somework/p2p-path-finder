<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Fixture;

use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function chr;
use function ord;
use function sprintf;

final class PathFinderEdgeCaseFixtures
{
    public const LONG_CHAIN_SEGMENTS = 16;

    public static function emptyOrderBook(): OrderBook
    {
        return new OrderBook([]);
    }

    public static function incompatibleBounds(): OrderBook
    {
        return new OrderBook([
            OrderFactory::buy('SRC', 'HUB', '5.000', '6.000', '1.000', 3, 3),
            OrderFactory::buy('HUB', 'DST', '0.100', '0.500', '1.000', 3, 3),
        ]);
    }

    public static function longGuardLimitedChain(int $segments = self::LONG_CHAIN_SEGMENTS): OrderBook
    {
        $orders = [];
        $current = 'SRC';

        for ($hop = 1; $hop <= $segments; ++$hop) {
            $next = $hop === $segments ? 'DST' : sprintf('GA%s', chr(ord('A') + $hop - 1));

            $orders[] = OrderFactory::buy($current, $next, '1.000', '1.250', '1.000', 3, 3);
            $current = $next;
        }

        return new OrderBook($orders);
    }

    /**
     * @return iterable<string, array{OrderBook, Money, string}>
     */
    public static function unresolvedSearches(): iterable
    {
        yield 'empty_order_book' => [
            self::emptyOrderBook(),
            CurrencyScenarioFactory::money('USD', '100.00', 2),
            'BTC',
        ];

        yield 'incompatible_bounds' => [
            self::incompatibleBounds(),
            CurrencyScenarioFactory::money('SRC', '1.000', 3),
            'DST',
        ];
    }

    /**
     * @return iterable<string, array{OrderBook, Money, string, int, int}>
     */
    public static function guardLimitedSearches(): iterable
    {
        yield 'expansion_guard' => [
            self::longGuardLimitedChain(),
            CurrencyScenarioFactory::money('SRC', '1.000', 3),
            'DST',
            5,
            128,
        ];
    }
}
