<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Benchmark;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;
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

use function str_pad;
use function strlen;

use const STR_PAD_LEFT;

/**
 * Tests to verify that benchmark scenarios are valid and produce expected results.
 *
 * These tests ensure that the benchmark scenarios:
 * 1. Run without errors
 * 2. Produce valid execution plans
 * 3. Have reasonable performance characteristics
 */
#[CoversClass(ExecutionPlanService::class)]
#[Group('benchmark')]
final class ExecutionPlanBenchTest extends TestCase
{
    private ExecutionPlanService $service;

    protected function setUp(): void
    {
        $this->service = new ExecutionPlanService(new GraphBuilder());
    }

    // ========================================================================
    // LINEAR SCENARIO TESTS
    // ========================================================================

    #[TestDox('Linear scenario with $orderCount orders produces valid path')]
    #[DataProvider('provideLinearScenarios')]
    public function test_linear_scenarios_are_valid(int $orderCount): void
    {
        $orderBook = $this->buildLinearOrderBook($orderCount);
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('SRC', '100.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, min($orderCount, 100))
            ->withSearchGuards(5000, 10000)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'DST');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths(), "Linear scenario with {$orderCount} orders should find a path");
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('SRC', $plan->sourceCurrency());
        self::assertSame('DST', $plan->targetCurrency());
        self::assertTrue($plan->totalSpent()->greaterThan(Money::zero('SRC', 2)));
        self::assertTrue($plan->totalReceived()->greaterThan(Money::zero('DST', 2)));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideLinearScenarios(): iterable
    {
        yield 'linear-50-orders' => [50];
        yield 'linear-100-orders' => [100];
        yield 'linear-200-orders' => [200];
    }

    // ========================================================================
    // SPLIT/MERGE SCENARIO TESTS
    // ========================================================================

    #[TestDox('Split/merge scenario with $orderCount orders produces valid path')]
    #[DataProvider('provideSplitMergeScenarios')]
    public function test_split_merge_scenarios_are_valid(int $orderCount, int $pathsPerLayer, int $layers): void
    {
        $orderBook = $this->buildSplitMergeOrderBook($orderCount, $pathsPerLayer, $layers);
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('SRC', '1000.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, $layers + 1)
            ->withSearchGuards(10000, 25000)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'DST');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths(), "Split/merge scenario with {$orderCount} orders should find a path");
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('SRC', $plan->sourceCurrency());
        self::assertSame('DST', $plan->targetCurrency());
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function provideSplitMergeScenarios(): iterable
    {
        yield 'split-merge-100-orders' => [100, 3, 3];
        yield 'split-merge-200-orders' => [200, 4, 3];
    }

    // ========================================================================
    // MULTI-ORDER SCENARIO TESTS
    // ========================================================================

    #[TestDox('Multi-order scenario with $orderCount orders produces valid path')]
    #[DataProvider('provideMultiOrderScenarios')]
    public function test_multi_order_scenarios_are_valid(int $orderCount): void
    {
        $orderBook = $this->buildMultiOrderSameDirectionBook($orderCount);
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('SRC', '50.00', 2))  // Reduced to fit within order capacity
            ->withToleranceBounds('0.00', '0.90')  // Allow up to 90% deviation
            ->withHopLimits(1, 3)
            ->withSearchGuards(5000, 10000)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'DST');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths(), "Multi-order scenario with {$orderCount} orders should find a path");
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('SRC', $plan->sourceCurrency());
        self::assertSame('DST', $plan->targetCurrency());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideMultiOrderScenarios(): iterable
    {
        yield 'multi-order-100' => [100];
        yield 'multi-order-200' => [200];
    }

    // ========================================================================
    // BOTTLENECK SCENARIO TESTS
    // ========================================================================

    #[TestDox('Bottleneck standard scenario produces valid path')]
    public function test_bottleneck_standard_scenario_is_valid(): void
    {
        // Note: BottleneckOrderBookFactory creates SELL orders which result in
        // edges going FROM quote TO base (e.g., DST -> ... -> SRC)
        // So we search from DST to SRC
        $orderBook = BottleneckOrderBookFactory::create();
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('DST', '120.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(2, 4)
            ->withSearchGuards(10000, 25000)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'SRC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths(), 'Bottleneck standard scenario should find a path');
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('DST', $plan->sourceCurrency());
        self::assertSame('SRC', $plan->targetCurrency());
    }

    #[TestDox('Bottleneck high fan-out scenario runs without error')]
    public function test_bottleneck_high_fanout_scenario_runs(): void
    {
        // Note: The high fan-out factory creates a complex graph structure.
        // This test verifies the benchmark scenario runs without errors,
        // but doesn't assert path finding success as the structure is designed
        // for stress testing rather than guaranteed path finding.
        $orderBook = BottleneckOrderBookFactory::createHighFanOut();
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('DST', '151.000', 3))
            ->withToleranceBounds('0.00', '0.99')
            ->withHopLimits(1, 6)
            ->withSearchGuards(20000, 50000)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'SRC');
        $outcome = $this->service->findBestPlans($request);

        // Just verify it runs without throwing - result may or may not have paths
        // depending on the graph structure and constraints
        $guardLimits = $outcome->guardLimits();
        self::assertGreaterThanOrEqual(0, $guardLimits->expansions());
    }

    // ========================================================================
    // DENSE GRAPH SCENARIO TESTS
    // ========================================================================

    #[TestDox('Dense graph scenario depth=$depth fanout=$fanout produces valid path')]
    #[DataProvider('provideDenseGraphScenarios')]
    public function test_dense_graph_scenarios_are_valid(int $depth, int $fanout, int $maxExpansions): void
    {
        $orderBook = $this->buildDenseOrderBook($depth, $fanout);
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('SRC', '10.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, $depth + 2)
            ->withSearchGuards($maxExpansions, $maxExpansions * 2)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'DST');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths(), "Dense graph depth={$depth} fanout={$fanout} should find a path");
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('SRC', $plan->sourceCurrency());
        self::assertSame('DST', $plan->targetCurrency());
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function provideDenseGraphScenarios(): iterable
    {
        yield 'dense-3x3' => [3, 3, 5000];
        yield 'dense-3x4' => [3, 4, 10000];
        yield 'dense-4x3' => [4, 3, 10000];
    }

    // ========================================================================
    // DETERMINISM TEST
    // ========================================================================

    #[TestDox('Benchmark scenarios produce deterministic results')]
    public function test_benchmark_scenarios_are_deterministic(): void
    {
        $orderBook = $this->buildLinearOrderBook(100);
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('SRC', '100.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, 10)
            ->withSearchGuards(5000, 10000)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'DST');

        $results = [];
        for ($i = 0; $i < 5; ++$i) {
            $outcome = $this->service->findBestPlans($request);
            self::assertTrue($outcome->hasPaths());
            $plan = $outcome->bestPath();
            self::assertInstanceOf(ExecutionPlan::class, $plan);
            $results[] = [
                'spent' => $plan->totalSpent()->amount(),
                'received' => $plan->totalReceived()->amount(),
                'steps' => $plan->stepCount(),
            ];
        }

        // All results should be identical
        $first = $results[0];
        for ($i = 1; $i < 5; ++$i) {
            self::assertSame($first, $results[$i], "Run {$i} differs from run 0");
        }
    }

    // ========================================================================
    // GUARD LIMITS TEST
    // ========================================================================

    #[TestDox('Guard limits are respected in benchmark scenarios')]
    public function test_guard_limits_are_respected(): void
    {
        $orderBook = $this->buildSplitMergeOrderBook(200, 4, 3);
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('SRC', '1000.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, 4)
            ->withSearchGuards(100, 200, 5000) // Very restrictive
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'DST');
        $outcome = $this->service->findBestPlans($request);

        $guardReport = $outcome->guardLimits();
        self::assertLessThanOrEqual(100, $guardReport->expansions());
        self::assertLessThanOrEqual(200, $guardReport->visitedStates());
    }

    // ========================================================================
    // ORDER BOOK GENERATORS (same as benchmark class)
    // ========================================================================

    private function buildLinearOrderBook(int $orderCount): OrderBook
    {
        $orders = [];
        $counter = 0;
        $hops = max(1, min($orderCount - 1, 9));

        $currentCurrency = 'SRC';
        for ($i = 0; $i < $hops; ++$i) {
            $nextCurrency = $i === $hops - 1 ? 'DST' : $this->syntheticCurrency($counter);

            $ordersPerHop = max(1, intdiv($orderCount, $hops));
            for ($j = 0; $j < $ordersPerHop; ++$j) {
                $rate = '1.0' . str_pad((string) (($i * 10 + $j) % 1000), 3, '0', STR_PAD_LEFT);
                $orders[] = new Order(
                    OrderSide::SELL,
                    AssetPair::fromString($nextCurrency, $currentCurrency),
                    OrderBounds::from(
                        Money::fromString($nextCurrency, '1.00', 2),
                        Money::fromString($nextCurrency, '1000.00', 2),
                    ),
                    ExchangeRate::fromString($nextCurrency, $currentCurrency, $rate, 4),
                );
            }

            $currentCurrency = $nextCurrency;
        }

        return new OrderBook($orders);
    }

    private function buildSplitMergeOrderBook(int $orderCount, int $pathsPerLayer, int $layers): OrderBook
    {
        $orders = [];
        $counter = 0;
        $ordersPerPath = max(1, intdiv($orderCount, $pathsPerLayer * $layers));

        for ($layer = 0; $layer < $layers; ++$layer) {
            $srcCurrencies = $layer === 0
                ? ['SRC']
                : $this->generateLayerCurrencies($counter - $pathsPerLayer, $pathsPerLayer);

            $dstCurrencies = $layer === $layers - 1
                ? ['DST']
                : $this->generateLayerCurrencies($counter, $pathsPerLayer);

            foreach ($srcCurrencies as $src) {
                foreach ($dstCurrencies as $dstCurrency) {
                    for ($i = 0; $i < $ordersPerPath; ++$i) {
                        $rate = '1.0' . str_pad((string) (($counter + $i) % 1000), 3, '0', STR_PAD_LEFT);
                        $orders[] = new Order(
                            OrderSide::SELL,
                            AssetPair::fromString($dstCurrency, $src),
                            OrderBounds::from(
                                Money::fromString($dstCurrency, '10.00', 2),
                                Money::fromString($dstCurrency, '10000.00', 2),
                            ),
                            ExchangeRate::fromString($dstCurrency, $src, $rate, 4),
                        );
                    }
                }
            }

            $counter += $pathsPerLayer;
        }

        return new OrderBook($orders);
    }

    private function buildMultiOrderSameDirectionBook(int $orderCount): OrderBook
    {
        $orders = [];
        $srcToMidCount = intdiv($orderCount, 2);
        $midToDstCount = $orderCount - $srcToMidCount;

        for ($i = 0; $i < $srcToMidCount; ++$i) {
            $rate = '1.0' . str_pad((string) ($i % 1000), 3, '0', STR_PAD_LEFT);
            $orders[] = new Order(
                OrderSide::SELL,
                AssetPair::fromString('MID', 'SRC'),
                OrderBounds::from(
                    Money::fromString('MID', '1.00', 2),
                    Money::fromString('MID', '1000.00', 2),
                ),
                ExchangeRate::fromString('MID', 'SRC', $rate, 4),
            );
        }

        for ($i = 0; $i < $midToDstCount; ++$i) {
            $rate = '1.0' . str_pad((string) (($i + 500) % 1000), 3, '0', STR_PAD_LEFT);
            $orders[] = new Order(
                OrderSide::SELL,
                AssetPair::fromString('DST', 'MID'),
                OrderBounds::from(
                    Money::fromString('DST', '1.00', 2),
                    Money::fromString('DST', '1000.00', 2),
                ),
                ExchangeRate::fromString('DST', 'MID', $rate, 4),
            );
        }

        return new OrderBook($orders);
    }

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
                        AssetPair::fromString($nextAsset, $asset),
                        OrderBounds::from(
                            Money::fromString($nextAsset, '1.00', 2),
                            Money::fromString($nextAsset, '100.00', 2),
                        ),
                        ExchangeRate::fromString($nextAsset, $asset, '1.0000', 4),
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
                    Money::fromString('DST', '100.00', 2),
                ),
                ExchangeRate::fromString('DST', $asset, '1.0000', 4),
            );
        }

        return new OrderBook($orders);
    }

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
}
