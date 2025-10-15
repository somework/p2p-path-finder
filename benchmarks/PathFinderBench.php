<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Benchmarks;

use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;
use function array_fill;
use function array_merge;

use PhpBench\Attributes\BeforeMethods;

#[BeforeMethods('setUp')]
class PathFinderBench
{
    private PathFinderService $service;

    private OrderBook $orderBook;

    private PathSearchConfig $config;

    public function setUp(): void
    {
        $orderSet = [];
        $orderSet[] = new Order(
            OrderSide::SELL,
            AssetPair::fromString('USD', 'USDT'),
            OrderBounds::from(
                Money::fromString('USD', '10.00', 2),
                Money::fromString('USD', '1000.00', 2),
            ),
            ExchangeRate::fromString('USD', 'USDT', '1.0000', 4),
        );

        $orderSet[] = new Order(
            OrderSide::SELL,
            AssetPair::fromString('USDT', 'EUR'),
            OrderBounds::from(
                Money::fromString('USDT', '50.00', 2),
                Money::fromString('USDT', '5000.00', 2),
            ),
            ExchangeRate::fromString('USDT', 'EUR', '0.92', 8),
        );

        $orderSet[] = new Order(
            OrderSide::SELL,
            AssetPair::fromString('EUR', 'BTC'),
            OrderBounds::from(
                Money::fromString('EUR', '10.00', 2),
                Money::fromString('EUR', '1000.00', 2),
            ),
            ExchangeRate::fromString('EUR', 'BTC', '0.000015', 8),
        );

        $this->orderBook = new OrderBook(array_merge(...array_fill(0, 5, $orderSet)));
        $this->service = new PathFinderService(new GraphBuilder());
        $this->config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds(0.00, 0.02)
            ->withHopLimits(1, 3)
            ->withResultLimit(5)
            ->build();
    }

    public function benchFindBestPaths(): void
    {
        $this->service->findBestPaths($this->orderBook, $this->config, 'BTC');
    }
}
