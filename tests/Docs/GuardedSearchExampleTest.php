<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Docs;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Application\Service\PathSearchRequest;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

/**
 * @coversNothing
 */
final class GuardedSearchExampleTest extends TestCase
{
    public function test_documentation_example_produces_route(): void
    {
        $orderBook = new OrderBook([
            new Order(
                OrderSide::SELL,
                AssetPair::fromString('USD', 'USDT'),
                OrderBounds::from(
                    Money::fromString('USD', '10.00', 2),
                    Money::fromString('USD', '2500.00', 2),
                ),
                ExchangeRate::fromString('USD', 'USDT', '1.0001', 6),
            ),
            new Order(
                OrderSide::SELL,
                AssetPair::fromString('USDT', 'BTC'),
                OrderBounds::from(
                    Money::fromString('USDT', '100.00', 2),
                    Money::fromString('USDT', '10000.00', 2),
                ),
                ExchangeRate::fromString('USDT', 'BTC', '0.000031', 8),
            ),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.01', '0.05')
            ->withHopLimits(1, 3)
            ->withSearchGuards(20000, 50000)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $result = $service->findBestPaths($request);

        self::assertFalse($result->guardLimits()->anyLimitReached(), 'Guard limits should not be triggered for the example input.');

        $paths = $result->paths()->toArray();
        self::assertNotEmpty($paths, 'Example should yield at least one conversion path.');

        $firstPath = $paths[0];
        self::assertSame('USD', $firstPath->totalSpent()->currency());
        self::assertSame('BTC', $firstPath->totalReceived()->currency());
        self::assertCount(2, $firstPath->legs());
    }
}
