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
use function array_map;
use function array_merge;
use function bcdiv;
use function bcsub;
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
     * @var array<string, Money>
     */
    private static array $moneyCache = [];

    /**
     * @var array<string, AssetPair>
     */
    private static array $assetPairCache = [];

    /**
     * @var array<string, ExchangeRate>
     */
    private static array $exchangeRateCache = [];

    /**
     * @var list<Order>|null
     */
    private static ?array $baseOrderPrototypes = null;

    /**
     * @var list<Order>
     */
    private array $baseOrders;

    /**
     * @var array<string, OrderBook>
     */
    private array $bottleneckOrderBooks = [];

    public function setUp(): void
    {
        $this->service = new PathFinderService(new GraphBuilder());
        $this->baseOrders = $this->createBaseOrderSet();
        $this->bottleneckOrderBooks = [
            'create' => BottleneckOrderBookFactory::create(),
            'createHighFanOut' => BottleneckOrderBookFactory::createHighFanOut(),
        ];
    }

    /**
     * @param array{orderSetRepeats:int,minHop:int,maxHop:int} $params
     */
    #[ParamProviders('provideMarketDepthScenarios')]
    public function benchFindBestPaths(array $params): void
    {
        $orderBook = new OrderBook(array_merge(...array_fill(0, $params['orderSetRepeats'], $this->baseOrders)));
        $config = PathSearchConfig::builder()
            ->withSpendAmount(self::money('USD', '100.00', 2))
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
            ->withSpendAmount(self::money('SRC', '10.00', 2))
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
            ->withSpendAmount(self::money('SRC', '1.00', 2))
            ->withToleranceBounds('0.00', '0.00')
            ->withHopLimits(2, 2)
            ->withResultLimit($params['resultLimit'])
            ->build();

        $this->service->findBestPaths($orderBook, $config, 'DST');
    }

    /**
     * @param array{spend:string,minHop:int,maxHop:int,resultLimit:int,factory:non-empty-string} $params
     */
    #[ParamProviders('provideBottleneckMandatoryMinima')]
    public function benchFindBottleneckMandatoryMinima(array $params): void
    {
        $factory = $params['factory'];
        $orderBook = clone $this->bottleneckOrderBooks[$factory];
        $config = PathSearchConfig::builder()
            ->withSpendAmount(self::money('SRC', $params['spend'], 2))
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
     * @return iterable<string, array{spend:string,minHop:int,maxHop:int,resultLimit:int,factory:non-empty-string}>
     */
    public function provideBottleneckMandatoryMinima(): iterable
    {
        yield 'bottleneck-hop-3' => [
            'spend' => '120.00',
            'minHop' => 3,
            'maxHop' => 3,
            'resultLimit' => 3,
            'factory' => 'create',
        ];

        yield 'bottleneck-high-fanout-hop-4' => [
            'spend' => '260.00',
            'minHop' => 3,
            'maxHop' => 4,
            'resultLimit' => 5,
            'factory' => 'createHighFanOut',
        ];
    }

    /**
     * @return list<Order>
     */
    private function createBaseOrderSet(): array
    {
        self::$baseOrderPrototypes ??= [
            new Order(
                OrderSide::SELL,
                self::assetPair('USD', 'BTC'),
                OrderBounds::from(
                    self::money('USD', '10.00', 2),
                    self::money('USD', '1000.00', 2),
                ),
                self::exchangeRate('USD', 'BTC', '1.0000', 4),
            ),
            new Order(
                OrderSide::SELL,
                self::assetPair('BTC', 'EUR'),
                OrderBounds::from(
                    self::money('BTC', '50.00', 2),
                    self::money('BTC', '5000.00', 2),
                ),
                self::exchangeRate('BTC', 'EUR', '0.92', 8),
            ),
            new Order(
                OrderSide::SELL,
                self::assetPair('EUR', 'BTC'),
                OrderBounds::from(
                    self::money('EUR', '10.00', 2),
                    self::money('EUR', '1000.00', 2),
                ),
                self::exchangeRate('EUR', 'BTC', '0.000015', 8),
            ),
        ];

        return array_map(
            static fn (Order $order): Order => clone $order,
            self::$baseOrderPrototypes,
        );
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
                        self::assetPair($nextAsset, $asset),
                        OrderBounds::from(
                            self::money($nextAsset, '1.00', 2),
                            self::money($nextAsset, '1.00', 2),
                        ),
                        self::exchangeRate($nextAsset, $asset, '1.000', 3),
                    );
                    $nextLayer[] = $nextAsset;
                }
            }

            $currentLayer = $nextLayer;
        }

        foreach ($currentLayer as $asset) {
            $orders[] = new Order(
                OrderSide::SELL,
                self::assetPair('DST', $asset),
                OrderBounds::from(
                    self::money('DST', '1.00', 2),
                    self::money('DST', '1.00', 2),
                ),
                self::exchangeRate('DST', $asset, '1.000', 3),
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
            $decrement = bcdiv((string) ($pathIndex + 1), '100000', 6);
            $rate = bcsub('1.000000', $decrement, 6);
            $orders[] = new Order(
                OrderSide::BUY,
                self::assetPair('SRC', $branchCurrency),
                OrderBounds::from(
                    self::money('SRC', '1.00', 2),
                    self::money('SRC', '1.00', 2),
                ),
                self::exchangeRate('SRC', $branchCurrency, '1.000000', 6),
            );

            $orders[] = new Order(
                OrderSide::BUY,
                self::assetPair($branchCurrency, 'DST'),
                OrderBounds::from(
                    self::money($branchCurrency, '1.00', 2),
                    self::money($branchCurrency, '1.00', 2),
                ),
                self::exchangeRate(
                    $branchCurrency,
                    'DST',
                    $rate,
                    6,
                ),
            );
        }

        return new OrderBook($orders);
    }

    private static function money(string $currency, string $amount, int $scale): Money
    {
        $key = $currency.'|'.$amount.'|'.$scale;

        return self::$moneyCache[$key] ??= Money::fromString($currency, $amount, $scale);
    }

    private static function assetPair(string $base, string $quote): AssetPair
    {
        $key = $base.'|'.$quote;

        return self::$assetPairCache[$key] ??= AssetPair::fromString($base, $quote);
    }

    private static function exchangeRate(string $base, string $quote, string $rate, int $scale): ExchangeRate
    {
        $key = $base.'|'.$quote.'|'.$rate.'|'.$scale;

        return self::$exchangeRateCache[$key] ??= ExchangeRate::fromString($base, $quote, $rate, $scale);
    }
}
