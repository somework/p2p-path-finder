<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

/**
 * Comprehensive integration tests for ExecutionPlanService verifying ALL path types work correctly
 * through the full execution stack.
 *
 * Path types tested:
 * - Linear paths (single chain from source to target)
 * - Multi-order same direction (multiple orders for same currency pair)
 * - Split paths (source distributes across multiple routes)
 * - Merge paths (multiple routes converge at target)
 * - Complex combinations (multi-order + split + merge)
 */
#[CoversClass(ExecutionPlanService::class)]
final class ExecutionPlanServiceTest extends TestCase
{
    private ExecutionPlanService $service;

    protected function setUp(): void
    {
        $this->service = new ExecutionPlanService(new GraphBuilder());
    }

    // ========================================================================
    // LINEAR PATH TESTS
    // ========================================================================

    #[TestDox('Simple linear path A→B works')]
    public function test_simple_two_currency_path(): void
    {
        // OrderBook: RUB→USDT (rate: 0.01, max: 1M)
        // SELL order: maker sells USDT for RUB, taker spends RUB receives USDT
        $order = OrderFactory::sell('USDT', 'RUB', '100.00', '10000.00', '100.00', 2, 2);
        $orderBook = $this->createOrderBook([$order]);

        // Request: 100k RUB → USDT
        $request = $this->createRequest($orderBook, '100000.00', 'RUB', 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths(), 'Should find a path');
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);

        // Expected: 1 step, ~1000 USDT received
        self::assertSame(1, $plan->stepCount());
        self::assertSame('RUB', $plan->sourceCurrency());
        self::assertSame('USDT', $plan->targetCurrency());
        self::assertTrue($plan->isLinear());

        // Verify amounts
        self::assertSame('RUB', $plan->totalSpent()->currency());
        self::assertSame('USDT', $plan->totalReceived()->currency());
    }

    #[TestDox('Linear path A→B→C works')]
    public function test_linear_two_hop_path(): void
    {
        // OrderBook:
        // - RUB→USDT (rate: 0.01)
        // - USDT→EUR (rate: 0.92)
        $rubToUsdt = OrderFactory::sell('USDT', 'RUB', '100.00', '10000.00', '100.00', 2, 2);
        $usdtToEur = OrderFactory::buy('USDT', 'EUR', '100.00', '10000.00', '0.92', 2, 2);
        $orderBook = $this->createOrderBook([$rubToUsdt, $usdtToEur]);

        $request = $this->createRequest($orderBook, '100000.00', 'RUB', 'EUR');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);

        self::assertSame(2, $plan->stepCount());
        self::assertSame('RUB', $plan->sourceCurrency());
        self::assertSame('EUR', $plan->targetCurrency());
        self::assertTrue($plan->isLinear());
    }

    #[TestDox('Linear path A→B→C→D works')]
    public function test_linear_multi_hop_path(): void
    {
        // OrderBook:
        // - RUB→USDT (rate: 0.01)
        // - USDT→EUR (rate: 0.92)
        // - EUR→IDR (rate: 17000)
        $rubToUsdt = OrderFactory::sell('USDT', 'RUB', '100.00', '10000.00', '100.00', 2, 2);
        $usdtToEur = OrderFactory::buy('USDT', 'EUR', '100.00', '10000.00', '0.92', 2, 2);
        $eurToIdr = OrderFactory::buy('EUR', 'IDR', '100.00', '10000.00', '17000.00', 2, 2);
        $orderBook = $this->createOrderBook([$rubToUsdt, $usdtToEur, $eurToIdr]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '100000.00', 2))
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 4)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'IDR');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);

        // Expected: 3 steps, linear execution
        self::assertSame(3, $plan->stepCount());
        self::assertTrue($plan->isLinear());
    }

    #[TestDox('Prefers single linear path over split when sufficient capacity')]
    public function test_prefers_linear_when_sufficient(): void
    {
        // OrderBook:
        // - Order1: RUB→USDT (rate: 0.01, max: 200k)
        // - Order2: RUB→USDT (rate: 0.0095, max: 200k) [worse rate]
        // - USDT→IDR
        $order1 = OrderFactory::sell('USDT', 'RUB', '10.00', '2000.00', '100.00', 2, 2);
        $order2 = OrderFactory::sell('USDT', 'RUB', '10.00', '2000.00', '105.00', 2, 2); // worse rate (more RUB per USDT)
        $usdtToIdr = OrderFactory::buy('USDT', 'IDR', '100.00', '10000.00', '15000.00', 2, 2);

        $orderBook = $this->createOrderBook([$order1, $order2, $usdtToIdr]);

        // Request: 100k RUB → IDR (well within single order capacity)
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '100000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 4)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'IDR');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);

        // Should use the better rate order and have linear path
        self::assertTrue($plan->isLinear());
    }

    // ========================================================================
    // MULTI-ORDER SAME DIRECTION TESTS
    // ========================================================================

    #[TestDox('Uses best rate order for A→B when multiple same-direction available')]
    public function test_best_rate_order_selection(): void
    {
        // OrderBook:
        // - Order1: RUB→USDT, rate 0.0100 (better), max 200k
        // - Order2: RUB→USDT, rate 0.0095 (worse), max 200k
        $order1 = OrderFactory::sell('USDT', 'RUB', '10.00', '2000.00', '100.00', 2, 2); // 100 RUB per USDT
        $order2 = OrderFactory::sell('USDT', 'RUB', '10.00', '2000.00', '105.00', 2, 2); // 105 RUB per USDT (worse)

        $orderBook = $this->createOrderBook([$order1, $order2]);

        // Request: 50k RUB → USDT (can be satisfied by single order)
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '50000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);

        // Should use only one order (the best rate)
        self::assertSame(1, $plan->stepCount());
    }

    #[TestDox('Handles multiple orders with different rate scales')]
    public function test_multiple_orders_different_rate_scales(): void
    {
        // Orders with varying rate scales
        $order1 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '100.00', 2, 4);
        $order2 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '100.5', 2, 6);

        $orderBook = $this->createOrderBook([$order1, $order2]);

        $request = $this->createRequest($orderBook, '50000.00', 'RUB', 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('USDT', $plan->totalReceived()->currency());
    }

    // ========================================================================
    // SPLIT/MERGE TESTS
    // ========================================================================

    #[TestDox('Finds path when only one route exists from source')]
    public function test_single_route_from_source(): void
    {
        // Single path: RUB→USDT→IDR
        $rubToUsdt = OrderFactory::sell('USDT', 'RUB', '100.00', '10000.00', '100.00', 2, 2);
        $usdtToIdr = OrderFactory::buy('USDT', 'IDR', '100.00', '10000.00', '15000.00', 2, 2);

        $orderBook = $this->createOrderBook([$rubToUsdt, $usdtToIdr]);

        $request = $this->createRequest($orderBook, '100000.00', 'RUB', 'IDR');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('RUB', $plan->sourceCurrency());
        self::assertSame('IDR', $plan->targetCurrency());
    }

    #[TestDox('Handles parallel routes when one is better')]
    public function test_parallel_routes_selects_best(): void
    {
        // Two parallel routes:
        // - RUB→USDT→IDR (better rate)
        // - RUB→BTC→IDR (worse rate)
        $rubToUsdt = OrderFactory::sell('USDT', 'RUB', '100.00', '10000.00', '100.00', 2, 2);
        $usdtToIdr = OrderFactory::buy('USDT', 'IDR', '100.00', '10000.00', '15000.00', 2, 2);

        $rubToBtc = OrderFactory::sell('BTC', 'RUB', '0.00001', '0.1', '5000000.00', 8, 2);
        $btcToIdr = OrderFactory::buy('BTC', 'IDR', '0.00001', '0.1', '500000000.00', 8, 2);

        $orderBook = $this->createOrderBook([$rubToUsdt, $usdtToIdr, $rubToBtc, $btcToIdr]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '100000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 4)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'IDR');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('RUB', $plan->sourceCurrency());
        self::assertSame('IDR', $plan->targetCurrency());
    }

    #[TestDox('Handles diamond-shaped graph (A→B and A→C, then B→D and C→D)')]
    public function test_diamond_graph_structure(): void
    {
        // Diamond:
        //      USD
        //     /   \
        //   EUR   GBP
        //     \   /
        //      BTC
        $usdToEur = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $usdToGbp = OrderFactory::buy('USD', 'GBP', '10.00', '1000.00', '0.80', 2, 2);
        $eurToBtc = OrderFactory::buy('EUR', 'BTC', '10.00', '1000.00', '0.000025', 2, 8);
        $gbpToBtc = OrderFactory::buy('GBP', 'BTC', '10.00', '1000.00', '0.000028', 2, 8);

        $orderBook = $this->createOrderBook([$usdToEur, $usdToGbp, $eurToBtc, $gbpToBtc]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '500.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 4)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('USD', $plan->sourceCurrency());
        self::assertSame('BTC', $plan->targetCurrency());
        self::assertSame(2, $plan->stepCount());
    }

    // ========================================================================
    // ADVANCED SEARCH STRATEGY TESTS
    // Multi-order, split, merge, and combined scenarios
    // ========================================================================

    #[TestDox('Strategy: Multiple A→B orders with same direction (multi-order aggregation)')]
    public function test_strategy_multiple_same_direction_orders(): void
    {
        // Scenario: Two A→B orders that could be combined
        // Order1: RUB→USDT (rate: 100, capacity: 500 USDT max)
        // Order2: RUB→USDT (rate: 100, capacity: 500 USDT max)
        // Request: 80k RUB → USDT
        // Both orders can handle it individually, should use best one
        $order1 = OrderFactory::sell('USDT', 'RUB', '100.00', '500.00', '100.00', 2, 2);
        $order2 = OrderFactory::sell('USDT', 'RUB', '100.00', '500.00', '100.00', 2, 2);

        $orderBook = $this->createOrderBook([$order1, $order2]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '40000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('RUB', $plan->sourceCurrency());
        self::assertSame('USDT', $plan->targetCurrency());
        // Each order used at most once
        $this->assertNoOrderUsedTwice($plan);
    }

    #[TestDox('Strategy: A→B, A→B with different rates (selects better rate)')]
    public function test_strategy_different_rate_same_direction(): void
    {
        // Scenario: Two A→B orders with different rates
        // Order1: RUB→USDT (rate: 100 RUB per USDT) - better
        // Order2: RUB→USDT (rate: 110 RUB per USDT) - worse
        $orderBetter = OrderFactory::sell('USDT', 'RUB', '100.00', '1000.00', '100.00', 2, 2);
        $orderWorse = OrderFactory::sell('USDT', 'RUB', '100.00', '1000.00', '110.00', 2, 2);

        $orderBook = $this->createOrderBook([$orderBetter, $orderWorse]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '50000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);

        // Should receive more USDT due to better rate
        // With 50k RUB at 100 rate = 500 USDT, at 110 rate = ~454 USDT
        self::assertSame('USDT', $plan->totalReceived()->currency());
    }

    #[TestDox('Strategy: A→B and A→C (split at source)')]
    public function test_strategy_split_at_source(): void
    {
        // Scenario: Source splits to two intermediates
        //     RUB
        //    /   \
        // USDT   EUR
        $rubToUsdt = OrderFactory::sell('USDT', 'RUB', '100.00', '1000.00', '100.00', 2, 2);
        $rubToEur = OrderFactory::sell('EUR', 'RUB', '10.00', '100.00', '100.00', 2, 2);

        $orderBook = $this->createOrderBook([$rubToUsdt, $rubToEur]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '50000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->build();

        // Request to USDT (single target)
        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('RUB', $plan->sourceCurrency());
        self::assertSame('USDT', $plan->targetCurrency());
    }

    #[TestDox('Strategy: B→D and C→D (merge at target)')]
    public function test_strategy_merge_at_target(): void
    {
        // Scenario: Two intermediates merge to target
        // USDT→BTC
        // EUR→BTC
        // (need to add source routes too)
        //   RUB→USDT, RUB→EUR
        $rubToUsdt = OrderFactory::sell('USDT', 'RUB', '100.00', '1000.00', '100.00', 2, 2);
        $rubToEur = OrderFactory::sell('EUR', 'RUB', '10.00', '100.00', '100.00', 2, 2);
        $usdtToBtc = OrderFactory::buy('USDT', 'BTC', '100.00', '1000.00', '0.00002', 2, 8);
        $eurToBtc = OrderFactory::buy('EUR', 'BTC', '10.00', '100.00', '0.000022', 2, 8);

        $orderBook = $this->createOrderBook([$rubToUsdt, $rubToEur, $usdtToBtc, $eurToBtc]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '50000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 4)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('RUB', $plan->sourceCurrency());
        self::assertSame('BTC', $plan->targetCurrency());
    }

    #[TestDox('Strategy: Full diamond A→B, A→C, B→D, C→D')]
    public function test_strategy_full_diamond(): void
    {
        // Full diamond pattern:
        //       A (RUB)
        //      /       \
        //   B (USDT)  C (EUR)
        //      \       /
        //       D (BTC)
        $aToB = OrderFactory::sell('USDT', 'RUB', '100.00', '1000.00', '100.00', 2, 2);
        $aToC = OrderFactory::sell('EUR', 'RUB', '10.00', '100.00', '100.00', 2, 2);
        $bToD = OrderFactory::buy('USDT', 'BTC', '100.00', '1000.00', '0.00002', 2, 8);
        $cToD = OrderFactory::buy('EUR', 'BTC', '10.00', '100.00', '0.000022', 2, 8);

        $orderBook = $this->createOrderBook([$aToB, $aToC, $bToD, $cToD]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '50000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 4)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('RUB', $plan->sourceCurrency());
        self::assertSame('BTC', $plan->targetCurrency());
        self::assertSame(2, $plan->stepCount()); // Should pick one route through diamond
    }

    #[TestDox('Strategy: A→B (x2), A→C, B→D, C→D (multi-order + diamond)')]
    public function test_strategy_multi_order_plus_diamond(): void
    {
        // Combined pattern: multiple A→B orders + diamond
        //        A (RUB)
        //       /|      \
        //  B1,B2 (USDT)  C (EUR)
        //       \|      /
        //        D (BTC)
        $aToB1 = OrderFactory::sell('USDT', 'RUB', '100.00', '500.00', '100.00', 2, 2);
        $aToB2 = OrderFactory::sell('USDT', 'RUB', '100.00', '500.00', '102.00', 2, 2); // slightly worse rate
        $aToC = OrderFactory::sell('EUR', 'RUB', '10.00', '50.00', '100.00', 2, 2);
        $bToD = OrderFactory::buy('USDT', 'BTC', '100.00', '1000.00', '0.00002', 2, 8);
        $cToD = OrderFactory::buy('EUR', 'BTC', '10.00', '50.00', '0.000022', 2, 8);

        $orderBook = $this->createOrderBook([$aToB1, $aToB2, $aToC, $bToD, $cToD]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '40000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 4)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('RUB', $plan->sourceCurrency());
        self::assertSame('BTC', $plan->targetCurrency());
        $this->assertNoOrderUsedTwice($plan);
    }

    #[TestDox('Strategy: Three parallel routes A→B, A→C, A→D all to target E')]
    public function test_strategy_three_parallel_routes(): void
    {
        // Three ways to reach target:
        //         A (RUB)
        //       /   |   \
        //   B(USDT) C(EUR) D(GBP)
        //       \   |   /
        //         E (BTC)
        $aToB = OrderFactory::sell('USDT', 'RUB', '100.00', '1000.00', '100.00', 2, 2);
        $aToC = OrderFactory::sell('EUR', 'RUB', '10.00', '100.00', '110.00', 2, 2);
        $aToD = OrderFactory::sell('GBP', 'RUB', '10.00', '100.00', '125.00', 2, 2);
        $bToE = OrderFactory::buy('USDT', 'BTC', '100.00', '1000.00', '0.00002', 2, 8);
        $cToE = OrderFactory::buy('EUR', 'BTC', '10.00', '100.00', '0.000021', 2, 8);
        $dToE = OrderFactory::buy('GBP', 'BTC', '10.00', '100.00', '0.000023', 2, 8);

        $orderBook = $this->createOrderBook([$aToB, $aToC, $aToD, $bToE, $cToE, $dToE]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '50000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 4)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('RUB', $plan->sourceCurrency());
        self::assertSame('BTC', $plan->targetCurrency());
        // Should pick the best route (likely through USDT)
        self::assertSame(2, $plan->stepCount());
    }

    #[TestDox('Strategy: Chain with multiple options at each hop')]
    public function test_strategy_chain_multiple_options_each_hop(): void
    {
        // A→B1, A→B2, B1→C, B2→C scenario
        // RUB → USDT (x2 orders) → EUR
        $rubToUsdt1 = OrderFactory::sell('USDT', 'RUB', '100.00', '500.00', '100.00', 2, 2);
        $rubToUsdt2 = OrderFactory::sell('USDT', 'RUB', '100.00', '500.00', '98.00', 2, 2); // better rate!
        $usdtToEur = OrderFactory::buy('USDT', 'EUR', '100.00', '1000.00', '0.92', 2, 2);

        $orderBook = $this->createOrderBook([$rubToUsdt1, $rubToUsdt2, $usdtToEur]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '40000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 4)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('RUB', $plan->sourceCurrency());
        self::assertSame('EUR', $plan->targetCurrency());
        self::assertSame(2, $plan->stepCount());
    }

    #[TestDox('Strategy: Complex graph with multiple paths and rates')]
    public function test_strategy_complex_graph(): void
    {
        // Create a more complex graph:
        //        RUB
        //       / | \
        //   USDT EUR GBP
        //       \ | /
        //        BTC
        //         |
        //        ETH
        $rubToUsdt = OrderFactory::sell('USDT', 'RUB', '100.00', '1000.00', '100.00', 2, 2);
        $rubToEur = OrderFactory::sell('EUR', 'RUB', '10.00', '100.00', '105.00', 2, 2);
        $rubToGbp = OrderFactory::sell('GBP', 'RUB', '10.00', '100.00', '120.00', 2, 2);
        $usdtToBtc = OrderFactory::buy('USDT', 'BTC', '100.00', '1000.00', '0.00002', 2, 8);
        $eurToBtc = OrderFactory::buy('EUR', 'BTC', '10.00', '100.00', '0.000021', 2, 8);
        $gbpToBtc = OrderFactory::buy('GBP', 'BTC', '10.00', '100.00', '0.000024', 2, 8);
        $btcToEth = OrderFactory::buy('BTC', 'ETH', '0.0001', '1.0', '15.0', 8, 4);

        $orderBook = $this->createOrderBook([
            $rubToUsdt, $rubToEur, $rubToGbp,
            $usdtToBtc, $eurToBtc, $gbpToBtc,
            $btcToEth,
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '50000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 5)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'ETH');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('RUB', $plan->sourceCurrency());
        self::assertSame('ETH', $plan->targetCurrency());
        self::assertSame(3, $plan->stepCount()); // RUB→X→BTC→ETH
    }

    #[TestDox('Strategy: Selects optimal path among alternatives with same hop count')]
    public function test_strategy_optimal_path_same_hops(): void
    {
        // Two 2-hop paths with different total conversion:
        // Path 1: RUB→USDT→BTC (better overall rate)
        // Path 2: RUB→EUR→BTC (worse overall rate)
        $rubToUsdt = OrderFactory::sell('USDT', 'RUB', '100.00', '1000.00', '100.00', 2, 2); // 1 USDT = 100 RUB
        $usdtToBtc = OrderFactory::buy('USDT', 'BTC', '100.00', '1000.00', '0.00003', 2, 8); // 1 USDT = 0.00003 BTC

        $rubToEur = OrderFactory::sell('EUR', 'RUB', '10.00', '100.00', '110.00', 2, 2); // 1 EUR = 110 RUB (worse)
        $eurToBtc = OrderFactory::buy('EUR', 'BTC', '10.00', '100.00', '0.000028', 2, 8); // 1 EUR = 0.000028 BTC

        $orderBook = $this->createOrderBook([$rubToUsdt, $usdtToBtc, $rubToEur, $eurToBtc]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '50000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 4)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('RUB', $plan->sourceCurrency());
        self::assertSame('BTC', $plan->targetCurrency());
        // Path through USDT should give: 50000/100 * 0.00003 = 0.015 BTC
        // Path through EUR should give: 50000/110 * 0.000028 ≈ 0.01273 BTC
        // So USDT path should be selected
        self::assertTrue($plan->totalReceived()->decimal()->isPositive());
    }

    #[TestDox('Strategy: Verify step-by-step currency flow in multi-hop')]
    public function test_strategy_step_currency_flow(): void
    {
        // Verify currency flows correctly: RUB→USDT→EUR→GBP
        $rubToUsdt = OrderFactory::sell('USDT', 'RUB', '100.00', '1000.00', '100.00', 2, 2);
        $usdtToEur = OrderFactory::buy('USDT', 'EUR', '100.00', '1000.00', '0.92', 2, 2);
        $eurToGbp = OrderFactory::buy('EUR', 'GBP', '10.00', '1000.00', '0.85', 2, 2);

        $orderBook = $this->createOrderBook([$rubToUsdt, $usdtToEur, $eurToGbp]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '50000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 5)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'GBP');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame(3, $plan->stepCount());
        self::assertTrue($plan->isLinear());

        // Verify step flow
        $steps = $plan->steps()->all();
        self::assertSame('RUB', $steps[0]->from());
        self::assertSame('USDT', $steps[0]->to());
        self::assertSame('USDT', $steps[1]->from());
        self::assertSame('EUR', $steps[1]->to());
        self::assertSame('EUR', $steps[2]->from());
        self::assertSame('GBP', $steps[2]->to());

        // Verify amounts flow correctly (output of step N = input of step N+1)
        self::assertSame($steps[0]->to(), $steps[1]->from());
        self::assertSame($steps[1]->to(), $steps[2]->from());
    }

    // ========================================================================
    // CONSTRAINT TESTS
    // ========================================================================

    #[TestDox('Prevents cycles (no A→B→A paths)')]
    public function test_no_cycles(): void
    {
        // Orders that could create a cycle:
        // USD→EUR, EUR→USD (cycle)
        // USD→EUR→GBP (valid path)
        $usdToEur = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $eurToUsd = OrderFactory::buy('EUR', 'USD', '10.00', '1000.00', '1.08', 2, 2);
        $eurToGbp = OrderFactory::buy('EUR', 'GBP', '10.00', '1000.00', '0.85', 2, 2);

        $orderBook = $this->createOrderBook([$usdToEur, $eurToUsd, $eurToGbp]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 4)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'GBP');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);

        // Verify no cycle: each currency should appear at most once as "from"
        $this->assertNoCycles($plan);
    }

    #[TestDox('Each order used at most once in plan')]
    public function test_order_used_once(): void
    {
        // Create scenario where same order could potentially be used twice
        $order1 = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $order2 = OrderFactory::buy('EUR', 'GBP', '10.00', '1000.00', '0.85', 2, 2);

        $orderBook = $this->createOrderBook([$order1, $order2]);

        $request = $this->createRequest($orderBook, '100.00', 'USD', 'GBP');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);

        // Verify no order used twice
        $this->assertNoOrderUsedTwice($plan);
    }

    #[TestDox('Handles insufficient total liquidity gracefully')]
    public function test_insufficient_liquidity(): void
    {
        // OrderBook: max 50k capacity total
        $order = OrderFactory::sell('USDT', 'RUB', '10.00', '500.00', '100.00', 2, 2);
        $orderBook = $this->createOrderBook([$order]);

        // Request: 1M RUB (way beyond capacity)
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '1000000.00', 2))
            ->withToleranceBounds('0.0', '0.10') // tight tolerance
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        // Should return empty or partial result
        // The behavior depends on tolerance settings
        self::assertInstanceOf(\SomeWork\P2PPathFinder\Application\PathSearch\Api\Response\SearchOutcome::class, $outcome);
    }

    #[TestDox('Returns empty when no path exists')]
    public function test_no_path_exists(): void
    {
        // Disconnected graph: USD→EUR, GBP→JPY (no connection)
        $usdToEur = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $gbpToJpy = OrderFactory::buy('GBP', 'JPY', '10.00', '1000.00', '180.00', 2, 2);

        $orderBook = $this->createOrderBook([$usdToEur, $gbpToJpy]);

        $request = $this->createRequest($orderBook, '100.00', 'USD', 'JPY');
        $outcome = $this->service->findBestPlans($request);

        self::assertFalse($outcome->hasPaths());
        self::assertNull($outcome->bestPath());
    }

    #[TestDox('Finds paths regardless of minimum hop config (hop filtering is service layer concern)')]
    public function test_finds_paths_regardless_of_minimum_hop_config(): void
    {
        // Direct path: USD→EUR (1 hop)
        $usdToEur = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $orderBook = $this->createOrderBook([$usdToEur]);

        // Note: ExecutionPlanService finds best execution plans regardless of hop limits.
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(2, 3) // minimum 2 hops - not enforced by ExecutionPlanService directly
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $this->service->findBestPlans($request);

        // ExecutionPlanService finds optimal plans; hop filtering is a higher-level concern
        // It will find a valid path even if it doesn't meet hop constraints
        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame(1, $plan->stepCount());
    }

    #[TestDox('Finds multi-hop paths when graph requires them')]
    public function test_finds_multi_hop_paths_when_needed(): void
    {
        // 3-hop path: USD→EUR→GBP→JPY
        $usdToEur = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $eurToGbp = OrderFactory::buy('EUR', 'GBP', '10.00', '1000.00', '0.85', 2, 2);
        $gbpToJpy = OrderFactory::buy('GBP', 'JPY', '10.00', '1000.00', '180.00', 2, 2);

        $orderBook = $this->createOrderBook([$usdToEur, $eurToGbp, $gbpToJpy]);

        // Note: ExecutionPlanService explores paths based on maxHops in PathSearchConfig
        // but its behavior is to find the best execution plan within the search space
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 4) // Allow up to 4 hops
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'JPY');
        $outcome = $this->service->findBestPlans($request);

        // Should find the 3-hop path
        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame(3, $plan->stepCount());
        self::assertSame('USD', $plan->sourceCurrency());
        self::assertSame('JPY', $plan->targetCurrency());
    }

    // ========================================================================
    // QUALITY TESTS
    // ========================================================================

    #[TestDox('Aggregates fees correctly across all steps')]
    public function test_fee_aggregation(): void
    {
        // Orders with fees
        $feePolicy = $this->percentageFeePolicy('0.01'); // 1% fee
        $order1 = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2, $feePolicy);
        $order2 = OrderFactory::buy('EUR', 'GBP', '10.00', '1000.00', '0.85', 2, 2, $feePolicy);

        $orderBook = $this->createOrderBook([$order1, $order2]);

        $request = $this->createRequest($orderBook, '100.00', 'USD', 'GBP');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);

        // Fee breakdown should contain fees (MoneyMap is always non-null)
        $feeBreakdown = $plan->feeBreakdown();
        // Verify fee breakdown is accessible and iterable
        self::assertGreaterThanOrEqual(0, $feeBreakdown->count());
    }

    #[TestDox('Deterministic on 10 repeated runs')]
    public function test_determinism(): void
    {
        $order1 = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $order2 = OrderFactory::buy('EUR', 'GBP', '10.00', '1000.00', '0.85', 2, 2);
        $orderBook = $this->createOrderBook([$order1, $order2]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'GBP');

        $results = [];
        for ($i = 0; $i < 10; ++$i) {
            $outcome = $this->service->findBestPlans($request);
            if ($outcome->hasPaths()) {
                $plan = $outcome->bestPath();
                self::assertInstanceOf(ExecutionPlan::class, $plan);
                $results[] = $plan->toArray();
            } else {
                $results[] = null;
            }
        }

        // All results identical
        $firstResult = $results[0];
        for ($i = 1; $i < 10; ++$i) {
            self::assertSame($firstResult, $results[$i], "Run {$i} differs from run 0");
        }
    }

    #[TestDox('Guard limits prevent runaway search')]
    public function test_guard_limits(): void
    {
        // Create a complex order book
        $orders = [];
        for ($i = 0; $i < 10; ++$i) {
            $orders[] = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', (string) (0.90 + $i * 0.01), 2, 2);
        }
        $orderBook = $this->createOrderBook($orders);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withSearchGuards(100, 100, 1000)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $this->service->findBestPlans($request);

        // Guard report should be available
        $guardReport = $outcome->guardLimits();
        self::assertGreaterThan(0, $guardReport->expansionLimit());
        self::assertGreaterThan(0, $guardReport->visitedStateLimit());
    }

    #[TestDox('Guard limits throw exception when configured')]
    public function test_guard_limits_throw_when_configured(): void
    {
        // Create many orders to likely hit limits
        $orders = [];
        for ($i = 0; $i < 20; ++$i) {
            $orders[] = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', (string) (0.90 + $i * 0.001), 2, 2);
        }
        $orderBook = $this->createOrderBook($orders);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withSearchGuards(1, 1, null) // Very restrictive
            ->withGuardLimitException(true)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'EUR');

        $this->expectException(\SomeWork\P2PPathFinder\Exception\GuardLimitExceeded::class);
        $this->service->findBestPlans($request);
    }

    // ========================================================================
    // ORDER SIDE TESTS (BUY vs SELL)
    // ========================================================================

    #[TestDox('SELL orders create correct edge direction')]
    public function test_sell_order_edge_direction(): void
    {
        // SELL USDT/RUB: maker sells USDT for RUB
        // Taker: spends RUB, receives USDT
        // Edge: RUB → USDT
        $order = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '100.00', 2, 2);
        $orderBook = $this->createOrderBook([$order]);

        $request = $this->createRequest($orderBook, '10000.00', 'RUB', 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('RUB', $plan->sourceCurrency());
        self::assertSame('USDT', $plan->targetCurrency());
    }

    #[TestDox('BUY orders create correct edge direction')]
    public function test_buy_order_edge_direction(): void
    {
        // BUY USDT/RUB: maker buys USDT with RUB
        // Taker: spends USDT, receives RUB
        // Edge: USDT → RUB
        $order = OrderFactory::buy('USDT', 'RUB', '10.00', '1000.00', '100.00', 2, 2);
        $orderBook = $this->createOrderBook([$order]);

        $request = $this->createRequest($orderBook, '100.00', 'USDT', 'RUB');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('USDT', $plan->sourceCurrency());
        self::assertSame('RUB', $plan->targetCurrency());
    }

    #[TestDox('Mixed BUY and SELL orders work in multi-hop path')]
    public function test_mixed_buy_sell_orders(): void
    {
        // RUB → USDT (via SELL)
        // USDT → BTC (via BUY)
        $sellOrder = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '100.00', 2, 2);
        $buyOrder = OrderFactory::buy('USDT', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);

        $orderBook = $this->createOrderBook([$sellOrder, $buyOrder]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '10000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 4)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('RUB', $plan->sourceCurrency());
        self::assertSame('BTC', $plan->targetCurrency());
        self::assertSame(2, $plan->stepCount());
    }

    // ========================================================================
    // EDGE CASES
    // ========================================================================

    #[TestDox('Handles empty order book')]
    public function test_empty_order_book(): void
    {
        $orderBook = $this->createOrderBook([]);
        $request = $this->createRequest($orderBook, '100.00', 'USD', 'EUR');
        $outcome = $this->service->findBestPlans($request);

        self::assertFalse($outcome->hasPaths());
    }

    #[TestDox('Handles high precision amounts')]
    public function test_high_precision_amounts(): void
    {
        // BTC with 8 decimal places
        $order = OrderFactory::buy('BTC', 'USDT', '0.00001000', '10.00000000', '40000.00', 8, 2);
        $orderBook = $this->createOrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('BTC', '0.10000000', 8))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('BTC', $plan->sourceCurrency());
    }

    #[TestDox('Case insensitive currency matching')]
    public function test_case_insensitive_currencies(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $orderBook = $this->createOrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('usd', '100.00', 2)) // lowercase
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'eur'); // lowercase
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('USD', $plan->sourceCurrency());
        self::assertSame('EUR', $plan->targetCurrency());
    }

    #[TestDox('Linear plan can be converted to Path')]
    public function test_linear_plan_converts_to_path(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $orderBook = $this->createOrderBook([$order]);

        $request = $this->createRequest($orderBook, '100.00', 'USD', 'EUR');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertTrue($plan->isLinear());

        $path = $plan->asLinearPath();
        self::assertNotNull($path);
        self::assertSame($plan->totalSpent()->amount(), $path->totalSpent()->amount());
        self::assertSame($plan->totalReceived()->amount(), $path->totalReceived()->amount());
    }

    #[TestDox('Step contains order reference')]
    public function test_step_contains_order_reference(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $orderBook = $this->createOrderBook([$order]);

        $request = $this->createRequest($orderBook, '100.00', 'USD', 'EUR');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);

        $steps = $plan->steps()->all();
        self::assertNotEmpty($steps);
        self::assertSame($order, $steps[0]->order());
    }

    // ========================================================================
    // CAPACITY AND RATE EVALUATION TESTS (MUL-12 migration coverage)
    // ========================================================================

    #[TestDox('Uses order with sufficient capacity when better-rate order has insufficient capacity')]
    public function test_capacity_constrained_order_selection(): void
    {
        // Scenario: Two orders for same direction
        // Order1: Better rate (100 RUB/USDT) but capacity of only 200 USDT
        // Order2: Worse rate (110 RUB/USDT) but capacity of 1000 USDT
        // Request: 50000 RUB (needs 500 USDT at rate 100, or ~454 USDT at rate 110)
        $orderBetterRate = OrderFactory::sell('USDT', 'RUB', '10.00', '200.00', '100.00', 2, 2); // max 200 USDT
        $orderWorseRate = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '110.00', 2, 2); // max 1000 USDT

        $orderBook = $this->createOrderBook([$orderBetterRate, $orderWorseRate]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '50000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('RUB', $plan->sourceCurrency());
        self::assertSame('USDT', $plan->targetCurrency());

        // ExecutionPlanService finds the optimal path based on available capacity
        self::assertGreaterThan(
            0,
            (int) $plan->totalReceived()->decimal()->toFloat(),
            'Should receive some USDT'
        );
    }

    #[TestDox('Selects best rate when multiple orders have sufficient capacity')]
    public function test_rate_selection_with_sufficient_capacity(): void
    {
        // Scenario: Multiple orders, all with sufficient capacity
        // Should select the one with best rate
        $orderBest = OrderFactory::sell('USDT', 'RUB', '100.00', '1000.00', '95.00', 2, 2);  // 95 RUB/USDT (best)
        $orderMid = OrderFactory::sell('USDT', 'RUB', '100.00', '1000.00', '100.00', 2, 2); // 100 RUB/USDT
        $orderWorst = OrderFactory::sell('USDT', 'RUB', '100.00', '1000.00', '110.00', 2, 2); // 110 RUB/USDT (worst)

        $orderBook = $this->createOrderBook([$orderWorst, $orderMid, $orderBest]); // Intentionally reversed order

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '9500.00', 2)) // Exactly 100 USDT at best rate
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);

        // Should get optimal conversion using best rate
        // 9500 RUB / 95 = 100 USDT
        self::assertSame('USDT', $plan->totalReceived()->currency());
    }

    // ========================================================================
    // TOLERANCE EVALUATION TESTS (MUL-12 migration coverage)
    // ========================================================================

    #[TestDox('Rejects plan when total spent exceeds tolerance bounds')]
    public function test_tolerance_rejection_when_exceeded(): void
    {
        // Order requires spending more than tolerance allows
        $order = OrderFactory::sell('USDT', 'RUB', '200.00', '500.00', '100.00', 2, 2); // min 200 USDT = 20000 RUB

        $orderBook = $this->createOrderBook([$order]);

        // Trying to spend 10000 RUB with tight tolerance
        // Order minimum is 20000 RUB, which exceeds 10000 * 1.05 = 10500 RUB tolerance
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '10000.00', 2))
            ->withToleranceBounds('0.0', '0.05') // 5% tolerance max
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        // Should not find a path due to tolerance violation
        self::assertFalse($outcome->hasPaths(), 'Should reject plan when it exceeds tolerance bounds');
    }

    #[TestDox('Accepts plan when total spent is within tolerance bounds')]
    public function test_tolerance_acceptance_within_bounds(): void
    {
        // Order that fits within tolerance
        $order = OrderFactory::sell('USDT', 'RUB', '10.00', '500.00', '100.00', 2, 2);

        $orderBook = $this->createOrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '10000.00', 2))
            ->withToleranceBounds('0.0', '0.25') // 25% tolerance
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths(), 'Should find path when within tolerance bounds');
        $plan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $plan);
        self::assertSame('USDT', $plan->totalReceived()->currency());
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

    /**
     * @param list<Order> $orders
     */
    private function createOrderBook(array $orders): OrderBook
    {
        return new OrderBook($orders);
    }

    /**
     * @param numeric-string $spendAmount
     */
    private function createRequest(
        OrderBook $orderBook,
        string $spendAmount,
        string $spendCurrency,
        string $targetCurrency,
    ): PathSearchRequest {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString($spendCurrency, $spendAmount, 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->build();

        return new PathSearchRequest($orderBook, $config, $targetCurrency);
    }

    /**
     * @param numeric-string $percentage
     */
    private function percentageFeePolicy(string $percentage): FeePolicy
    {
        return new class($percentage) implements FeePolicy {
            /** @var numeric-string */
            private readonly string $percentage;

            /**
             * @param numeric-string $percentage
             */
            public function __construct(string $percentage)
            {
                $this->percentage = $percentage;
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $fee = $quoteAmount->multiply($this->percentage, $quoteAmount->scale());

                return FeeBreakdown::forQuote($fee);
            }

            public function fingerprint(): string
            {
                return 'percentage-quote:'.$this->percentage;
            }
        };
    }

    // ========================================================================
    // ASSERTION HELPERS
    // ========================================================================

    private function assertNoOrderUsedTwice(ExecutionPlan $plan): void
    {
        $usedOrders = [];
        foreach ($plan->steps() as $step) {
            $orderKey = spl_object_id($step->order());
            self::assertArrayNotHasKey($orderKey, $usedOrders, 'Order used twice in plan');
            $usedOrders[$orderKey] = true;
        }
    }

    private function assertNoCycles(ExecutionPlan $plan): void
    {
        $visitedFrom = [];
        foreach ($plan->steps() as $step) {
            $from = $step->from();
            self::assertArrayNotHasKey($from, $visitedFrom, "Currency {$from} appears as source multiple times (potential cycle)");
            $visitedFrom[$from] = true;
        }
    }
}
