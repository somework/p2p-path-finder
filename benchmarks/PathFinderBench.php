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
use function sprintf;

use PhpBench\Attributes\BeforeMethods;

#[BeforeMethods('setUp')]
class PathFinderBench
{
    private PathFinderService $service;

    private OrderBook $orderBook;

    private PathSearchConfig $config;

    private OrderBook $denseOrderBook;

    private PathSearchConfig $denseConfig;

    public function setUp(): void
    {
        $orderSet = [];
        $orderSet[] = new Order(
            OrderSide::SELL,
            AssetPair::fromString('USD', 'BTC'),
            OrderBounds::from(
                Money::fromString('USD', '10.00', 2),
                Money::fromString('USD', '1000.00', 2),
            ),
            ExchangeRate::fromString('USD', 'BTC', '1.0000', 4),
        );

        $orderSet[] = new Order(
            OrderSide::SELL,
            AssetPair::fromString('BTC', 'EUR'),
            OrderBounds::from(
                Money::fromString('BTC', '50.00', 2),
                Money::fromString('BTC', '5000.00', 2),
            ),
            ExchangeRate::fromString('BTC', 'EUR', '0.92', 8),
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

        $this->denseOrderBook = $this->buildDenseOrderBook(4, 4);
        $this->denseConfig = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('SRC', '10.00', 2))
            ->withToleranceBounds(0.00, 0.00)
            ->withHopLimits(1, 5)
            ->withSearchGuards(100000, 100000)
            ->withResultLimit(3)
            ->build();
    }

    public function benchFindBestPaths(): void
    {
        $this->service->findBestPaths($this->orderBook, $this->config, 'BTC');
    }

    public function benchFindBestPathsDenseGraph(): void
    {
        $this->service->findBestPaths($this->denseOrderBook, $this->denseConfig, 'DST');
    }

    private function buildDenseOrderBook(int $depth, int $fanout): OrderBook
    {
        $orders = [];
        $currentLayer = ['SRC'];
        $counter = 0;

        for ($layer = 1; $layer <= $depth; ++$layer) {
            $nextLayer = [];

            foreach ($currentLayer as $index => $asset) {
                for ($i = 0; $i < $fanout; ++$i) {
                    $nextAsset = $this->syntheticCurrency($counter++);
                    $orders[] = new Order(
                        OrderSide::SELL,
                        AssetPair::fromString($nextAsset, $asset),
                        OrderBounds::from(
                            Money::fromString($nextAsset, '1.00', 2),
                            Money::fromString($nextAsset, '1.00', 2),
                        ),
                        ExchangeRate::fromString($nextAsset, $asset, '1.000', 3),
                    );
                    $nextLayer[] = $nextAsset;
                }
            }

            $currentLayer = $nextLayer;
        }

        foreach ($currentLayer as $asset) {
            $orders[] = new Order(
                OrderSide::SELL,
                AssetPair::fromString('DST', $asset),
                OrderBounds::from(
                    Money::fromString('DST', '1.00', 2),
                    Money::fromString('DST', '1.00', 2),
                ),
                ExchangeRate::fromString('DST', $asset, '1.000', 3),
            );
        }

        return new OrderBook($orders);
    }

    private function syntheticCurrency(int $index): string
    {
        $alphabet = range('A', 'Z');

        $first = intdiv($index, 26 * 26) % 26;
        $second = intdiv($index, 26) % 26;
        $third = $index % 26;

        return $alphabet[$first].$alphabet[$second].$alphabet[$third];
    }
}
