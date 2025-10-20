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
use function number_format;
use function str_pad;
use function strlen;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\ParamProviders;
use SomeWork\P2PPathFinder\Tests\Fixture\BottleneckOrderBookFactory;

#[BeforeMethods('setUp')]
class PathFinderBench
{
    private PathFinderService $service;

    /**
     * @var list<Order>
     */
    private array $baseOrders;

    public function setUp(): void
    {
        $this->service = new PathFinderService(new GraphBuilder());
        $this->baseOrders = $this->createBaseOrderSet();
    }

    /**
     * @param array{orderSetRepeats:int,minHop:int,maxHop:int} $params
     */
    #[ParamProviders('provideMarketDepthScenarios')]
    public function benchFindBestPaths(array $params): void
    {
        $orderBook = new OrderBook(array_merge(...array_fill(0, $params['orderSetRepeats'], $this->baseOrders)));
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.00', '0.02')
            ->withHopLimits($params['minHop'], $params['maxHop'])
            ->withResultLimit(5)
            ->build();

        $this->service->findBestPaths($orderBook, $config, 'BTC');
    }

    /**
     * @param array{depth:int,fanout:int,maxHop:int,searchGuard:int} $params
     */
    #[ParamProviders('provideDenseGraphScenarios')]
    public function benchFindBestPathsDenseGraph(array $params): void
    {
        $orderBook = $this->buildDenseOrderBook($params['depth'], $params['fanout']);
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('SRC', '10.00', 2))
            ->withToleranceBounds('0.00', '0.00')
            ->withHopLimits(1, $params['maxHop'])
            ->withSearchGuards($params['searchGuard'], $params['searchGuard'])
            ->withResultLimit(3)
            ->build();

        $this->service->findBestPaths($orderBook, $config, 'DST');
    }

    /**
     * @param array{orderCount:int,resultLimit:int} $params
     */
    #[ParamProviders('provideKBestSearchScenarios')]
    public function benchFindKBestPaths(array $params): void
    {
        $orderBook = $this->buildKBestOrderBook($params['orderCount']);
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('SRC', '1.00', 2))
            ->withToleranceBounds('0.00', '0.00')
            ->withHopLimits(2, 2)
            ->withResultLimit($params['resultLimit'])
            ->build();

        $this->service->findBestPaths($orderBook, $config, 'DST');
    }

    /**
     * @param array{spend:string,minHop:int,maxHop:int,resultLimit:int} $params
     */
    #[ParamProviders('provideBottleneckMandatoryMinima')]
    public function benchFindBottleneckMandatoryMinima(array $params): void
    {
        $orderBook = BottleneckOrderBookFactory::create();
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('SRC', $params['spend'], 2))
            ->withToleranceBounds('0.00', '0.00')
            ->withHopLimits($params['minHop'], $params['maxHop'])
            ->withResultLimit($params['resultLimit'])
            ->build();

        $this->service->findBestPaths($orderBook, $config, 'DST');
    }

    /**
     * @return iterable<string, array{orderSetRepeats:int,minHop:int,maxHop:int}>
     */
    public function provideMarketDepthScenarios(): iterable
    {
        yield 'light-depth-hop-3' => [
            'orderSetRepeats' => 5,
            'minHop' => 1,
            'maxHop' => 3,
        ];

        yield 'moderate-depth-hop-4' => [
            'orderSetRepeats' => 15,
            'minHop' => 1,
            'maxHop' => 4,
        ];
    }

    /**
     * @return iterable<string, array{depth:int,fanout:int,maxHop:int,searchGuard:int}>
     */
    public function provideDenseGraphScenarios(): iterable
    {
        yield 'dense-4x4-hop-5' => [
            'depth' => 4,
            'fanout' => 4,
            'maxHop' => 5,
            'searchGuard' => 20000,
        ];

        yield 'dense-3x7-hop-6' => [
            'depth' => 3,
            'fanout' => 7,
            'maxHop' => 6,
            'searchGuard' => 30000,
        ];
    }

    /**
     * @return iterable<string, array{orderCount:int,resultLimit:int}>
     */
    public function provideKBestSearchScenarios(): iterable
    {
        yield 'k-best-n1e2' => [
            'orderCount' => 100,
            'resultLimit' => 16,
        ];

        yield 'k-best-n1e3' => [
            'orderCount' => 1000,
            'resultLimit' => 16,
        ];

        yield 'k-best-n1e4' => [
            'orderCount' => 10000,
            'resultLimit' => 16,
        ];
    }

    /**
     * @return iterable<string, array{spend:string,minHop:int,maxHop:int,resultLimit:int}>
     */
    public function provideBottleneckMandatoryMinima(): iterable
    {
        yield 'bottleneck-hop-3' => [
            'spend' => '120.00',
            'minHop' => 3,
            'maxHop' => 3,
            'resultLimit' => 3,
        ];
    }

    /**
     * @return list<Order>
     */
    private function createBaseOrderSet(): array
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

        return $orderSet;
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
                    $nextAsset = $this->syntheticCurrency($counter);
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

    private function syntheticCurrency(int &$counter): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $base = strlen($alphabet);

        while (true) {
            $value = $counter++;
            $candidate = '';

            do {
                $candidate = $alphabet[$value % $base].$candidate;
                $value = intdiv($value, $base);
            } while ($value > 0);

            if (strlen($candidate) < 3) {
                $candidate = str_pad($candidate, 3, $alphabet[0], STR_PAD_LEFT);
            }

            if ($candidate === 'SRC' || $candidate === 'DST') {
                continue;
            }

            return $candidate;
        }
    }

    private function buildKBestOrderBook(int $orderCount): OrderBook
    {
        $orders = [];
        $counter = 0;
        $paths = intdiv($orderCount, 2);

        for ($pathIndex = 0; $pathIndex < $paths; ++$pathIndex) {
            $branchCurrency = $this->syntheticCurrency($counter);
            $orders[] = new Order(
                OrderSide::BUY,
                AssetPair::fromString('SRC', $branchCurrency),
                OrderBounds::from(
                    Money::fromString('SRC', '1.00', 2),
                    Money::fromString('SRC', '1.00', 2),
                ),
                ExchangeRate::fromString('SRC', $branchCurrency, '1.000000', 6),
            );

            $orders[] = new Order(
                OrderSide::BUY,
                AssetPair::fromString($branchCurrency, 'DST'),
                OrderBounds::from(
                    Money::fromString($branchCurrency, '1.00', 2),
                    Money::fromString($branchCurrency, '1.00', 2),
                ),
                ExchangeRate::fromString(
                    $branchCurrency,
                    'DST',
                    number_format(1.0 - (($pathIndex + 1) * 0.00001), 6, '.', ''),
                    6,
                ),
            );
        }

        return new OrderBook($orders);
    }
}
