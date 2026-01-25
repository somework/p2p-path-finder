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
use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;
use SomeWork\P2PPathFinder\Domain\Money\AssetPair;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\OrderBounds;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;

use function str_pad;
use function strlen;

use const STR_PAD_LEFT;

/**
 * Comparison benchmarks between legacy PathSearchService and new ExecutionPlanService.
 *
 * This benchmark helps measure:
 * - Performance difference between legacy and new implementations
 * - Memory usage comparison
 * - Behavior on linear path scenarios (where both should produce equivalent results)
 *
 * Target: ExecutionPlanService should be < 2x slower than legacy on linear paths
 * Acceptable: ExecutionPlanService should be < 5x slower than legacy on linear paths
 *
 * Note: PathSearchService is deprecated since 2.0. This benchmark exists to validate
 * that the new service doesn't regress significantly on linear path performance.
 */
#[BeforeMethods('setUp')]
class LegacyComparisonBench
{
    private PathSearchService $legacyService;
    private ExecutionPlanService $newService;

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
     * Pre-built order books for comparison scenarios.
     *
     * @var array<string, OrderBook>
     */
    private array $orderBooks = [];

    /**
     * Pre-built configs for comparison scenarios.
     *
     * @var array<string, PathSearchConfig>
     */
    private array $configs = [];

    public function setUp(): void
    {
        $graphBuilder = new GraphBuilder();
        $this->legacyService = new PathSearchService($graphBuilder);
        $this->newService = new ExecutionPlanService($graphBuilder);

        // Build comparison scenarios (linear paths only, since legacy doesn't support split/merge)
        $this->buildComparisonScenarios();
    }

    // ========================================================================
    // LEGACY SERVICE BENCHMARKS
    // ========================================================================

    /**
     * @param array{scenario: string} $params
     */
    #[Revs(10)]
    #[Iterations(5)]
    #[Groups(['comparison', 'legacy'])]
    #[ParamProviders('provideComparisonScenarios')]
    public function benchFindLegacyPathSearchService(array $params): void
    {
        $scenario = $params['scenario'];
        $orderBook = $this->orderBooks[$scenario];
        $config = $this->configs[$scenario];

        $request = new PathSearchRequest($orderBook, $config, 'DST');

        // Suppress deprecation warning for benchmark purposes
        @$this->legacyService->findBestPaths($request);
    }

    // ========================================================================
    // NEW SERVICE BENCHMARKS
    // ========================================================================

    /**
     * @param array{scenario: string} $params
     */
    #[Revs(10)]
    #[Iterations(5)]
    #[Groups(['comparison', 'new'])]
    #[ParamProviders('provideComparisonScenarios')]
    public function benchFindNewExecutionPlanService(array $params): void
    {
        $scenario = $params['scenario'];
        $orderBook = $this->orderBooks[$scenario];
        $config = $this->configs[$scenario];

        $request = new PathSearchRequest($orderBook, $config, 'DST');
        $this->newService->findBestPlans($request);
    }

    /**
     * @return iterable<string, array{scenario: string}>
     */
    public function provideComparisonScenarios(): iterable
    {
        yield 'linear-50-orders' => ['scenario' => 'linear_50'];
        yield 'linear-100-orders' => ['scenario' => 'linear_100'];
        yield 'linear-200-orders' => ['scenario' => 'linear_200'];
        yield 'linear-500-orders' => ['scenario' => 'linear_500'];
        yield 'two-hop-100-orders' => ['scenario' => 'two_hop_100'];
        yield 'two-hop-500-orders' => ['scenario' => 'two_hop_500'];
        yield 'three-hop-100-orders' => ['scenario' => 'three_hop_100'];
    }

    // ========================================================================
    // MEMORY COMPARISON BENCHMARKS
    // ========================================================================

    /**
     * @param array{scenario: string} $params
     */
    #[Revs(1)]
    #[Iterations(3)]
    #[Groups(['memory', 'legacy'])]
    #[ParamProviders('provideMemoryScenarios')]
    public function benchFindLegacyMemory(array $params): void
    {
        $scenario = $params['scenario'];
        $orderBook = $this->orderBooks[$scenario];
        $config = $this->configs[$scenario];

        $request = new PathSearchRequest($orderBook, $config, 'DST');

        @$this->legacyService->findBestPaths($request);
    }

    /**
     * @param array{scenario: string} $params
     */
    #[Revs(1)]
    #[Iterations(3)]
    #[Groups(['memory', 'new'])]
    #[ParamProviders('provideMemoryScenarios')]
    public function benchFindNewServiceMemory(array $params): void
    {
        $scenario = $params['scenario'];
        $orderBook = $this->orderBooks[$scenario];
        $config = $this->configs[$scenario];

        $request = new PathSearchRequest($orderBook, $config, 'DST');
        $this->newService->findBestPlans($request);
    }

    /**
     * @return iterable<string, array{scenario: string}>
     */
    public function provideMemoryScenarios(): iterable
    {
        yield 'memory-linear-500' => ['scenario' => 'linear_500'];
        yield 'memory-linear-1000' => ['scenario' => 'linear_1000'];
    }

    // ========================================================================
    // SCENARIO BUILDERS
    // ========================================================================

    private function buildComparisonScenarios(): void
    {
        // Linear chains with varying order counts
        $this->orderBooks['linear_50'] = $this->buildLinearOrderBook(50);
        $this->configs['linear_50'] = $this->buildLinearConfig(50);

        $this->orderBooks['linear_100'] = $this->buildLinearOrderBook(100);
        $this->configs['linear_100'] = $this->buildLinearConfig(100);

        $this->orderBooks['linear_200'] = $this->buildLinearOrderBook(200);
        $this->configs['linear_200'] = $this->buildLinearConfig(200);

        $this->orderBooks['linear_500'] = $this->buildLinearOrderBook(500);
        $this->configs['linear_500'] = $this->buildLinearConfig(500);

        $this->orderBooks['linear_1000'] = $this->buildLinearOrderBook(1000);
        $this->configs['linear_1000'] = $this->buildLinearConfig(1000);

        // Two-hop scenarios (SRC → MID → DST) with multiple orders per hop
        $this->orderBooks['two_hop_100'] = $this->buildTwoHopOrderBook(100);
        $this->configs['two_hop_100'] = $this->buildTwoHopConfig();

        $this->orderBooks['two_hop_500'] = $this->buildTwoHopOrderBook(500);
        $this->configs['two_hop_500'] = $this->buildTwoHopConfig();

        // Three-hop scenarios
        $this->orderBooks['three_hop_100'] = $this->buildThreeHopOrderBook(100);
        $this->configs['three_hop_100'] = $this->buildThreeHopConfig();
    }

    private function buildLinearConfig(int $orderCount): PathSearchConfig
    {
        $maxExpansions = $orderCount < 100 ? 5000 : ($orderCount < 500 ? 10000 : 20000);
        $maxVisited = $maxExpansions * 2;

        return PathSearchConfig::builder()
            ->withSpendAmount(self::money('SRC', '100.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, min($orderCount, 10))
            ->withSearchGuards($maxExpansions, $maxVisited)
            ->build();
    }

    private function buildTwoHopConfig(): PathSearchConfig
    {
        return PathSearchConfig::builder()
            ->withSpendAmount(self::money('SRC', '100.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, 3)
            ->withSearchGuards(10000, 25000)
            ->build();
    }

    private function buildThreeHopConfig(): PathSearchConfig
    {
        return PathSearchConfig::builder()
            ->withSpendAmount(self::money('SRC', '100.00', 2))
            ->withToleranceBounds('0.00', '0.50')
            ->withHopLimits(1, 4)
            ->withSearchGuards(10000, 25000)
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
        $hops = max(1, min($orderCount - 1, 9)); // Limit to 10 hops max for reasonable comparison

        // Create chain
        $currentCurrency = 'SRC';
        for ($i = 0; $i < $hops; ++$i) {
            $nextCurrency = $i === $hops - 1 ? 'DST' : $this->syntheticCurrency($counter);

            // Create multiple orders per hop to reach order count
            $ordersPerHop = max(1, intdiv($orderCount, $hops));
            for ($j = 0; $j < $ordersPerHop; ++$j) {
                $rate = '1.0' . str_pad((string) (($i * 10 + $j) % 1000), 3, '0', STR_PAD_LEFT);
                $orders[] = new Order(
                    OrderSide::SELL,
                    self::assetPair($nextCurrency, $currentCurrency),
                    OrderBounds::from(
                        self::money($nextCurrency, '1.00', 2),
                        self::money($nextCurrency, '1000.00', 2),
                    ),
                    self::exchangeRate($nextCurrency, $currentCurrency, $rate, 4),
                );
            }

            $currentCurrency = $nextCurrency;
        }

        return new OrderBook($orders);
    }

    /**
     * Builds a two-hop order book: SRC → MID (many orders) → DST (many orders)
     */
    private function buildTwoHopOrderBook(int $orderCount): OrderBook
    {
        $orders = [];
        $srcToMidCount = intdiv($orderCount, 2);
        $midToDstCount = $orderCount - $srcToMidCount;

        // SRC → MID orders
        for ($i = 0; $i < $srcToMidCount; ++$i) {
            $rate = '1.0' . str_pad((string) ($i % 1000), 3, '0', STR_PAD_LEFT);
            $orders[] = new Order(
                OrderSide::SELL,
                self::assetPair('MID', 'SRC'),
                OrderBounds::from(
                    self::money('MID', '1.00', 2),
                    self::money('MID', '1000.00', 2),
                ),
                self::exchangeRate('MID', 'SRC', $rate, 4),
            );
        }

        // MID → DST orders
        for ($i = 0; $i < $midToDstCount; ++$i) {
            $rate = '1.0' . str_pad((string) (($i + 500) % 1000), 3, '0', STR_PAD_LEFT);
            $orders[] = new Order(
                OrderSide::SELL,
                self::assetPair('DST', 'MID'),
                OrderBounds::from(
                    self::money('DST', '1.00', 2),
                    self::money('DST', '1000.00', 2),
                ),
                self::exchangeRate('DST', 'MID', $rate, 4),
            );
        }

        return new OrderBook($orders);
    }

    /**
     * Builds a three-hop order book: SRC → HUB1 → HUB2 → DST
     */
    private function buildThreeHopOrderBook(int $orderCount): OrderBook
    {
        $orders = [];
        $ordersPerHop = intdiv($orderCount, 3);

        // SRC → HUB1
        for ($i = 0; $i < $ordersPerHop; ++$i) {
            $rate = '1.0' . str_pad((string) ($i % 1000), 3, '0', STR_PAD_LEFT);
            $orders[] = new Order(
                OrderSide::SELL,
                self::assetPair('HUB1', 'SRC'),
                OrderBounds::from(
                    self::money('HUB1', '1.00', 2),
                    self::money('HUB1', '1000.00', 2),
                ),
                self::exchangeRate('HUB1', 'SRC', $rate, 4),
            );
        }

        // HUB1 → HUB2
        for ($i = 0; $i < $ordersPerHop; ++$i) {
            $rate = '1.0' . str_pad((string) (($i + 333) % 1000), 3, '0', STR_PAD_LEFT);
            $orders[] = new Order(
                OrderSide::SELL,
                self::assetPair('HUB2', 'HUB1'),
                OrderBounds::from(
                    self::money('HUB2', '1.00', 2),
                    self::money('HUB2', '1000.00', 2),
                ),
                self::exchangeRate('HUB2', 'HUB1', $rate, 4),
            );
        }

        // HUB2 → DST
        for ($i = 0; $i < $ordersPerHop; ++$i) {
            $rate = '1.0' . str_pad((string) (($i + 666) % 1000), 3, '0', STR_PAD_LEFT);
            $orders[] = new Order(
                OrderSide::SELL,
                self::assetPair('DST', 'HUB2'),
                OrderBounds::from(
                    self::money('DST', '1.00', 2),
                    self::money('DST', '1000.00', 2),
                ),
                self::exchangeRate('DST', 'HUB2', $rate, 4),
            );
        }

        return new OrderBook($orders);
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

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

            if ('SRC' === $candidate || 'DST' === $candidate || 'MID' === $candidate || 'HUB' === substr($candidate, 0, 3)) {
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
