<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Benchmarks;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Domain\Money\AssetPair;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\OrderBounds;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Tests\Fixture\BottleneckOrderBookFactory;

use function array_keys;
use function chr;
use function ord;
use function str_pad;
use function strlen;

use const STR_PAD_LEFT;

/**
 * Benchmarks for ExecutionPlanSearchEngine and ExecutionPlanService.
 *
 * Performance targets:
 * - Linear path (100 orders): < 10ms target, < 50ms acceptable
 * - Linear path (1000 orders): < 100ms target, < 500ms acceptable
 * - Split/merge (100 orders): < 50ms target, < 200ms acceptable
 * - Split/merge (1000 orders): < 500ms target, < 2000ms acceptable
 * - Memory (1000 orders): < 10MB target, < 50MB acceptable
 * - vs Legacy (linear): < 2x slower target, < 5x slower acceptable
 */
#[BeforeMethods('setUp')]
class ExecutionPlanBench
{
    private ExecutionPlanService $service;

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
     * Pre-built order books for different scenarios.
     *
     * @var array<string, OrderBook>
     */
    private array $orderBooks = [];

    /**
     * Pre-built configs for different scenarios.
     *
     * @var array<string, PathSearchConfig>
     */
    private array $configs = [];

    public function setUp(): void
    {
        $graphBuilder = new GraphBuilder();
        $this->service = new ExecutionPlanService($graphBuilder);

        // Pre-build all scenario order books and configs
        $this->buildLinearScenarios();
        $this->buildSplitMergeScenarios();
        $this->buildMultiOrderScenarios();
        $this->buildBottleneckScenarios();
    }

    // ========================================================================
    // LINEAR PATH BENCHMARKS
    // ========================================================================

    /**
     * @param array{scenario: string} $params
     */
    #[Revs(10)]
    #[Iterations(5)]
    #[Groups(['linear', 'execution_plan'])]
    #[ParamProviders('provideLinearScenarios')]
    public function benchFindLinearPath(array $params): void
    {
        $scenario = $params['scenario'];
        $orderBook = $this->orderBooks[$scenario];
        $config = $this->configs[$scenario];

        $request = new PathSearchRequest($orderBook, $config, 'DST');
        $this->service->findBestPlans($request);
    }

    /**
     * @return iterable<string, array{scenario: string}>
     */
    public function provideLinearScenarios(): iterable
    {
        yield 'linear-100-orders' => ['scenario' => 'linear_100'];
        yield 'linear-500-orders' => ['scenario' => 'linear_500'];
        yield 'linear-1000-orders' => ['scenario' => 'linear_1000'];
    }

    // ========================================================================
    // SPLIT/MERGE PATH BENCHMARKS
    // ========================================================================

    /**
     * @param array{scenario: string} $params
     */
    #[Revs(10)]
    #[Iterations(5)]
    #[Groups(['split_merge', 'execution_plan'])]
    #[ParamProviders('provideSplitMergeScenarios')]
    public function benchFindSplitMergePath(array $params): void
    {
        $scenario = $params['scenario'];
        $orderBook = $this->orderBooks[$scenario];
        $config = $this->configs[$scenario];

        $request = new PathSearchRequest($orderBook, $config, 'DST');
        $this->service->findBestPlans($request);
    }

    /**
     * @return iterable<string, array{scenario: string}>
     */
    public function provideSplitMergeScenarios(): iterable
    {
        yield 'split-merge-100-orders' => ['scenario' => 'split_100'];
        yield 'split-merge-500-orders' => ['scenario' => 'split_500'];
        yield 'split-merge-1000-orders' => ['scenario' => 'split_1000'];
    }

    // ========================================================================
    // MULTI-ORDER SAME DIRECTION BENCHMARKS
    // ========================================================================

    /**
     * @param array{scenario: string} $params
     */
    #[Revs(10)]
    #[Iterations(5)]
    #[Groups(['multi_order', 'execution_plan'])]
    #[ParamProviders('provideMultiOrderScenarios')]
    public function benchFindMultiOrderPath(array $params): void
    {
        $scenario = $params['scenario'];
        $orderBook = $this->orderBooks[$scenario];
        $config = $this->configs[$scenario];

        $request = new PathSearchRequest($orderBook, $config, 'DST');
        $this->service->findBestPlans($request);
    }

    /**
     * @return iterable<string, array{scenario: string}>
     */
    public function provideMultiOrderScenarios(): iterable
    {
        yield 'multi-order-100' => ['scenario' => 'multi_100'];
        yield 'multi-order-500' => ['scenario' => 'multi_500'];
        yield 'multi-order-1000' => ['scenario' => 'multi_1000'];
    }

    // ========================================================================
    // BOTTLENECK / STRESS TESTS
    // ========================================================================

    /**
     * @param array{scenario: string} $params
     */
    #[Revs(5)]
    #[Iterations(3)]
    #[Groups(['bottleneck', 'execution_plan'])]
    #[ParamProviders('provideBottleneckScenarios')]
    public function benchFindBottleneckPath(array $params): void
    {
        $scenario = $params['scenario'];
        $orderBook = $this->orderBooks[$scenario];
        $config = $this->configs[$scenario];

        // Bottleneck scenarios search from DST to SRC due to SELL order edge direction
        $request = new PathSearchRequest($orderBook, $config, 'SRC');
        $this->service->findBestPlans($request);
    }

    /**
     * @return iterable<string, array{scenario: string}>
     */
    public function provideBottleneckScenarios(): iterable
    {
        yield 'bottleneck-standard' => ['scenario' => 'bottleneck_standard'];
        yield 'bottleneck-high-fanout' => ['scenario' => 'bottleneck_high_fanout'];
    }

    // ========================================================================
    // DENSE GRAPH BENCHMARKS
    // ========================================================================

    /**
     * @param array{depth: int, fanout: int, maxExpansions: int} $params
     */
    #[Revs(5)]
    #[Iterations(3)]
    #[Groups(['dense', 'execution_plan'])]
    #[ParamProviders('provideDenseGraphScenarios')]
    public function benchFindDenseGraphPath(array $params): void
    {
        $orderBook = $this->buildDenseOrderBook($params['depth'], $params['fanout']);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(self::money('SRC', '10.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, 6)
            ->withSearchGuards($params['maxExpansions'], $params['maxExpansions'])
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'DST');
        $this->service->findBestPlans($request);
    }

    /**
     * @return iterable<string, array{depth: int, fanout: int, maxExpansions: int}>
     */
    public function provideDenseGraphScenarios(): iterable
    {
        yield 'dense-3x4-hop-4' => [
            'depth' => 3,
            'fanout' => 4,
            'maxExpansions' => 10000,
        ];

        yield 'dense-4x3-hop-5' => [
            'depth' => 4,
            'fanout' => 3,
            'maxExpansions' => 20000,
        ];
    }

    // ========================================================================
    // TOP-K EXECUTION PLAN BENCHMARKS
    // ========================================================================

    /**
     * Benchmarks Top-K execution plan discovery with varying K values.
     *
     * @param array{k: int, orderCount: int} $params
     */
    #[Revs(5)]
    #[Iterations(3)]
    #[Groups(['topk', 'execution_plan'])]
    #[ParamProviders('provideTopKScenarios')]
    public function benchFindTopKPlans(array $params): void
    {
        $orderBook = $this->buildTopKOrderBook($params['orderCount']);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(self::money('SRC', '1000.00', 2))
            ->withToleranceBounds('0.00', '0.20')
            ->withHopLimits(1, 2)
            ->withResultLimit($params['k'])
            ->withSearchGuards(10000, 25000)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'DST');
        $this->service->findBestPlans($request);
    }

    /**
     * @return iterable<string, array{k: int, orderCount: int}>
     */
    public function provideTopKScenarios(): iterable
    {
        yield 'topk-1-from-10' => ['k' => 1, 'orderCount' => 10];
        yield 'topk-3-from-10' => ['k' => 3, 'orderCount' => 10];
        yield 'topk-5-from-20' => ['k' => 5, 'orderCount' => 20];
        yield 'topk-10-from-50' => ['k' => 10, 'orderCount' => 50];
        yield 'topk-5-from-100' => ['k' => 5, 'orderCount' => 100];
    }

    /**
     * Benchmarks graph filtering (withoutOrders) performance.
     *
     * @param array{orderCount: int, excludeCount: int} $params
     */
    #[Revs(10)]
    #[Iterations(5)]
    #[Groups(['topk', 'graph_filter'])]
    #[ParamProviders('provideGraphFilterScenarios')]
    public function benchGraphFiltering(array $params): void
    {
        $orderBook = $this->buildTopKOrderBook($params['orderCount']);
        $graph = (new GraphBuilder())->build($orderBook->all());

        // Collect order IDs to exclude
        $excludedIds = [];
        $count = 0;
        foreach ($orderBook->all() as $order) {
            if ($count >= $params['excludeCount']) {
                break;
            }
            $excludedIds[spl_object_id($order)] = true;
            ++$count;
        }

        // Measure filtering performance
        $graph->withoutOrders($excludedIds);
    }

    /**
     * @return iterable<string, array{orderCount: int, excludeCount: int}>
     */
    public function provideGraphFilterScenarios(): iterable
    {
        yield 'filter-10-exclude-1' => ['orderCount' => 10, 'excludeCount' => 1];
        yield 'filter-50-exclude-5' => ['orderCount' => 50, 'excludeCount' => 5];
        yield 'filter-100-exclude-10' => ['orderCount' => 100, 'excludeCount' => 10];
        yield 'filter-100-exclude-50' => ['orderCount' => 100, 'excludeCount' => 50];
    }

    /**
     * Builds an order book suitable for Top-K testing.
     * Creates multiple alternative routes from SRC to DST.
     */
    private function buildTopKOrderBook(int $orderCount): OrderBook
    {
        $orders = [];

        // Create orderCount direct routes from SRC to DST with varying rates
        for ($i = 0; $i < $orderCount; ++$i) {
            $rate = '0.9' . str_pad((string) ($i % 100), 2, '0', STR_PAD_LEFT);
            $orders[] = new Order(
                OrderSide::SELL,
                self::assetPair('DST', 'SRC'),
                OrderBounds::from(
                    self::money('DST', '100.00', 2),
                    self::money('DST', '10000.00', 2),
                ),
                self::exchangeRate('DST', 'SRC', $rate, 4),
            );
        }

        return new OrderBook($orders);
    }

    // ========================================================================
    // GUARD LIMIT IMPACT BENCHMARKS
    // ========================================================================

    /**
     * Tests how different guard limits affect performance.
     *
     * @param array{maxExpansions: int, maxVisited: int, timeBudget: int|null} $params
     */
    #[Revs(5)]
    #[Iterations(3)]
    #[Groups(['guards', 'execution_plan'])]
    #[ParamProviders('provideGuardLimitScenarios')]
    public function benchFindWithGuardLimits(array $params): void
    {
        // Use the 500-order split/merge scenario for consistent baseline
        $orderBook = $this->orderBooks['split_500'];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(self::money('SRC', '1000.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, 4)
            ->withSearchGuards($params['maxExpansions'], $params['maxVisited'], $params['timeBudget'])
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'DST');
        $this->service->findBestPlans($request);
    }

    /**
     * @return iterable<string, array{maxExpansions: int, maxVisited: int, timeBudget: int|null}>
     */
    public function provideGuardLimitScenarios(): iterable
    {
        yield 'guards-conservative' => [
            'maxExpansions' => 5000,
            'maxVisited' => 10000,
            'timeBudget' => 1000,
        ];

        yield 'guards-moderate' => [
            'maxExpansions' => 10000,
            'maxVisited' => 25000,
            'timeBudget' => 3000,
        ];

        yield 'guards-aggressive' => [
            'maxExpansions' => 20000,
            'maxVisited' => 50000,
            'timeBudget' => 5000,
        ];
    }

    // ========================================================================
    // SCENARIO BUILDERS
    // ========================================================================

    private function buildLinearScenarios(): void
    {
        // Linear 100: SRC → HUB0 → HUB1 → ... → DST (chain with 100 orders)
        $this->orderBooks['linear_100'] = $this->buildLinearOrderBook(100);
        $this->configs['linear_100'] = PathSearchConfig::builder()
            ->withSpendAmount(self::money('SRC', '100.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, 100)
            ->withSearchGuards(5000, 10000)
            ->build();

        // Linear 500
        $this->orderBooks['linear_500'] = $this->buildLinearOrderBook(500);
        $this->configs['linear_500'] = PathSearchConfig::builder()
            ->withSpendAmount(self::money('SRC', '100.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, 500)
            ->withSearchGuards(10000, 25000)
            ->build();

        // Linear 1000
        $this->orderBooks['linear_1000'] = $this->buildLinearOrderBook(1000);
        $this->configs['linear_1000'] = PathSearchConfig::builder()
            ->withSpendAmount(self::money('SRC', '100.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, 1000)
            ->withSearchGuards(20000, 50000)
            ->build();
    }

    private function buildSplitMergeScenarios(): void
    {
        // Split/Merge with diamond patterns creating parallel paths
        $this->orderBooks['split_100'] = $this->buildSplitMergeOrderBook(100, 3, 3);
        $this->configs['split_100'] = PathSearchConfig::builder()
            ->withSpendAmount(self::money('SRC', '1000.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, 4)
            ->withSearchGuards(10000, 25000)
            ->build();

        $this->orderBooks['split_500'] = $this->buildSplitMergeOrderBook(500, 5, 4);
        $this->configs['split_500'] = PathSearchConfig::builder()
            ->withSpendAmount(self::money('SRC', '1000.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, 5)
            ->withSearchGuards(20000, 50000)
            ->build();

        $this->orderBooks['split_1000'] = $this->buildSplitMergeOrderBook(1000, 8, 5);
        $this->configs['split_1000'] = PathSearchConfig::builder()
            ->withSpendAmount(self::money('SRC', '1000.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, 6)
            ->withSearchGuards(50000, 100000)
            ->build();
    }

    private function buildMultiOrderScenarios(): void
    {
        // Multiple orders for same currency pair (aggregation scenarios)
        $this->orderBooks['multi_100'] = $this->buildMultiOrderSameDirectionBook(100);
        $this->configs['multi_100'] = PathSearchConfig::builder()
            ->withSpendAmount(self::money('SRC', '1000.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, 3)
            ->withSearchGuards(5000, 10000)
            ->build();

        $this->orderBooks['multi_500'] = $this->buildMultiOrderSameDirectionBook(500);
        $this->configs['multi_500'] = PathSearchConfig::builder()
            ->withSpendAmount(self::money('SRC', '1000.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, 3)
            ->withSearchGuards(10000, 25000)
            ->build();

        $this->orderBooks['multi_1000'] = $this->buildMultiOrderSameDirectionBook(1000);
        $this->configs['multi_1000'] = PathSearchConfig::builder()
            ->withSpendAmount(self::money('SRC', '1000.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, 3)
            ->withSearchGuards(20000, 50000)
            ->build();
    }

    private function buildBottleneckScenarios(): void
    {
        // Note: BottleneckOrderBookFactory creates SELL orders resulting in edges
        // from quote to base (DST -> ... -> SRC). We search from DST to SRC.
        $this->orderBooks['bottleneck_standard'] = BottleneckOrderBookFactory::create();
        $this->configs['bottleneck_standard'] = PathSearchConfig::builder()
            ->withSpendAmount(self::money('DST', '120.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(2, 4)
            ->withSearchGuards(10000, 25000)
            ->build();

        $this->orderBooks['bottleneck_high_fanout'] = BottleneckOrderBookFactory::createHighFanOut();
        $this->configs['bottleneck_high_fanout'] = PathSearchConfig::builder()
            ->withSpendAmount(self::money('DST', '151.000', 3))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(2, 6)
            ->withSearchGuards(20000, 50000)
            ->build();
    }

    // ========================================================================
    // ORDER BOOK GENERATORS
    // ========================================================================

    /**
     * Builds a linear chain: SRC → HUB0 → HUB1 → ... → DST
     */
    private function buildLinearOrderBook(int $orderCount): OrderBook
    {
        $orders = [];
        $counter = 0;
        $hops = $orderCount - 1;

        // Create chain with small rate variations
        $currentCurrency = 'SRC';
        for ($i = 0; $i < $hops; ++$i) {
            $nextCurrency = $i === $hops - 1 ? 'DST' : $this->syntheticCurrency($counter);
            $rate = '1.00' . str_pad((string) ($i % 100), 2, '0', STR_PAD_LEFT);

            $orders[] = new Order(
                OrderSide::SELL,
                self::assetPair($nextCurrency, $currentCurrency),
                OrderBounds::from(
                    self::money($nextCurrency, '1.00', 2),
                    self::money($nextCurrency, '1000.00', 2),
                ),
                self::exchangeRate($nextCurrency, $currentCurrency, $rate, 4),
            );

            $currentCurrency = $nextCurrency;
        }

        // Add final hop if not already at DST
        if ($currentCurrency !== 'DST') {
            $orders[] = new Order(
                OrderSide::SELL,
                self::assetPair('DST', $currentCurrency),
                OrderBounds::from(
                    self::money('DST', '1.00', 2),
                    self::money('DST', '1000.00', 2),
                ),
                self::exchangeRate('DST', $currentCurrency, '1.0000', 4),
            );
        }

        return new OrderBook($orders);
    }

    /**
     * Builds a split/merge graph with diamond patterns.
     *
     * Structure: SRC → [layer1] → [layer2] → ... → DST
     * Each layer has multiple parallel paths that can split and merge.
     */
    private function buildSplitMergeOrderBook(int $orderCount, int $pathsPerLayer, int $layers): OrderBook
    {
        $orders = [];
        $counter = 0;
        $ordersPerPath = max(1, intdiv($orderCount, $pathsPerLayer * $layers));

        // Build layer-by-layer
        for ($layer = 0; $layer < $layers; ++$layer) {
            $srcCurrencies = $layer === 0
                ? ['SRC']
                : $this->generateLayerCurrencies($counter - $pathsPerLayer, $pathsPerLayer);

            $dstCurrencies = $layer === $layers - 1
                ? ['DST']
                : $this->generateLayerCurrencies($counter, $pathsPerLayer);

            // Create connections from each src to each dst (creating diamond patterns)
            foreach ($srcCurrencies as $src) {
                foreach ($dstCurrencies as $dstCurrency) {
                    for ($i = 0; $i < $ordersPerPath; ++$i) {
                        $rate = '1.0' . str_pad((string) (($counter + $i) % 1000), 3, '0', STR_PAD_LEFT);
                        $orders[] = new Order(
                            OrderSide::SELL,
                            self::assetPair($dstCurrency, $src),
                            OrderBounds::from(
                                self::money($dstCurrency, '10.00', 2),
                                self::money($dstCurrency, '10000.00', 2),
                            ),
                            self::exchangeRate($dstCurrency, $src, $rate, 4),
                        );
                    }
                }
            }

            $counter += $pathsPerLayer;
        }

        return new OrderBook($orders);
    }

    /**
     * Builds an order book with multiple orders for the same currency pairs.
     *
     * Structure: SRC → MID (many orders) → DST
     */
    private function buildMultiOrderSameDirectionBook(int $orderCount): OrderBook
    {
        $orders = [];
        $srcToMidCount = intdiv($orderCount, 2);
        $midToDstCount = $orderCount - $srcToMidCount;

        // SRC → MID orders with varying rates
        for ($i = 0; $i < $srcToMidCount; ++$i) {
            $rate = '1.0' . str_pad((string) ($i % 1000), 3, '0', STR_PAD_LEFT);
            $orders[] = new Order(
                OrderSide::SELL,
                self::assetPair('MID', 'SRC'),
                OrderBounds::from(
                    self::money('MID', '1.00', 2),
                    self::money('MID', '100.00', 2),
                ),
                self::exchangeRate('MID', 'SRC', $rate, 4),
            );
        }

        // MID → DST orders with varying rates
        for ($i = 0; $i < $midToDstCount; ++$i) {
            $rate = '1.0' . str_pad((string) (($i + 500) % 1000), 3, '0', STR_PAD_LEFT);
            $orders[] = new Order(
                OrderSide::SELL,
                self::assetPair('DST', 'MID'),
                OrderBounds::from(
                    self::money('DST', '1.00', 2),
                    self::money('DST', '100.00', 2),
                ),
                self::exchangeRate('DST', 'MID', $rate, 4),
            );
        }

        return new OrderBook($orders);
    }

    /**
     * Builds a dense graph with high fan-out at each node.
     */
    private function buildDenseOrderBook(int $depth, int $fanout): OrderBook
    {
        $orders = [];
        $currentLayer = ['SRC'];
        $counter = 0;

        for ($layer = 1; $layer <= $depth; ++$layer) {
            $nextLayer = [];

            foreach ($currentLayer as $asset) {
                for ($i = 0; $i < $fanout; ++$i) {
                    $nextAsset = $this->syntheticCurrency($counter);
                    $orders[] = new Order(
                        OrderSide::SELL,
                        self::assetPair($nextAsset, $asset),
                        OrderBounds::from(
                            self::money($nextAsset, '1.00', 2),
                            self::money($nextAsset, '100.00', 2),
                        ),
                        self::exchangeRate($nextAsset, $asset, '1.0000', 4),
                    );
                    $nextLayer[] = $nextAsset;
                }
            }

            $currentLayer = $nextLayer;
        }

        // Connect final layer to DST
        foreach ($currentLayer as $asset) {
            $orders[] = new Order(
                OrderSide::SELL,
                self::assetPair('DST', $asset),
                OrderBounds::from(
                    self::money('DST', '1.00', 2),
                    self::money('DST', '100.00', 2),
                ),
                self::exchangeRate('DST', $asset, '1.0000', 4),
            );
        }

        return new OrderBook($orders);
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

    /**
     * @return list<string>
     */
    private function generateLayerCurrencies(int $startCounter, int $count): array
    {
        $currencies = [];
        $counter = $startCounter;
        for ($i = 0; $i < $count; ++$i) {
            $currencies[] = $this->syntheticCurrency($counter);
        }

        return $currencies;
    }

    private function syntheticCurrency(int &$counter): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $base = strlen($alphabet);

        while (true) {
            $value = $counter++;
            $candidate = '';

            do {
                $candidate = $alphabet[$value % $base] . $candidate;
                $value = intdiv($value, $base);
            } while ($value > 0);

            if (strlen($candidate) < 3) {
                $candidate = str_pad($candidate, 3, $alphabet[0], STR_PAD_LEFT);
            }

            if ('SRC' === $candidate || 'DST' === $candidate || 'MID' === $candidate) {
                continue;
            }

            return $candidate;
        }
    }

    private static function money(string $currency, string $amount, int $scale): Money
    {
        $key = $currency . '|' . $amount . '|' . $scale;

        return self::$moneyCache[$key] ??= Money::fromString($currency, $amount, $scale);
    }

    private static function assetPair(string $base, string $quote): AssetPair
    {
        $key = $base . '|' . $quote;

        return self::$assetPairCache[$key] ??= AssetPair::fromString($base, $quote);
    }

    private static function exchangeRate(string $base, string $quote, string $rate, int $scale): ExchangeRate
    {
        $key = $base . '|' . $quote . '|' . $rate . '|' . $scale;

        return self::$exchangeRateCache[$key] ??= ExchangeRate::fromString($base, $quote, $rate, $scale);
    }
}
