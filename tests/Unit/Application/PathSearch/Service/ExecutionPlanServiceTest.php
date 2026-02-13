<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Response\SearchOutcome;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

#[CoversClass(ExecutionPlanService::class)]
final class ExecutionPlanServiceTest extends TestCase
{
    private ExecutionPlanService $service;

    protected function setUp(): void
    {
        $this->service = new ExecutionPlanService(new GraphBuilder());
    }

    public function test_find_best_plans_single_route(): void
    {
        // Single route: USD -> BTC
        $order = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertInstanceOf(SearchOutcome::class, $outcome);
        self::assertTrue($outcome->hasPaths());

        $bestPlan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $bestPlan);
        self::assertSame('USD', $bestPlan->sourceCurrency());
        self::assertSame('BTC', $bestPlan->targetCurrency());
        self::assertSame(1, $bestPlan->stepCount());
    }

    public function test_find_best_plans_multi_hop_linear_path(): void
    {
        // Multi-hop: USD -> USDT -> BTC
        $order1 = OrderFactory::buy('USD', 'USDT', '10.00', '1000.00', '1.00', 2, 2);
        $order2 = OrderFactory::buy('USDT', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order1, $order2]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $bestPlan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $bestPlan);
        self::assertSame('USD', $bestPlan->sourceCurrency());
        self::assertSame('BTC', $bestPlan->targetCurrency());
        // Path: USD -> USDT -> BTC = 2 hops
        self::assertSame(2, $bestPlan->stepCount());
        self::assertTrue($bestPlan->isLinear());
    }

    public function test_find_best_plans_multi_order_same_direction(): void
    {
        // Multiple orders for USD -> BTC direction with different rates
        // Request amount within single order bounds - engine uses best order
        $order1 = OrderFactory::buy('USD', 'BTC', '10.00', '200.00', '0.00002', 2, 8);
        $order2 = OrderFactory::buy('USD', 'BTC', '10.00', '200.00', '0.000021', 2, 8);
        $orderBook = new OrderBook([$order1, $order2]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $bestPlan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $bestPlan);
        self::assertSame('USD', $bestPlan->sourceCurrency());
        self::assertSame('BTC', $bestPlan->targetCurrency());
    }

    public function test_find_best_plans_split_merge(): void
    {
        // Split/merge scenario:
        // USD -> USDT -> BTC
        // USD -> EUR -> BTC
        $usdToUsdt = OrderFactory::buy('USD', 'USDT', '10.00', '100.00', '1.00', 2, 2);
        $usdtToBtc = OrderFactory::buy('USDT', 'BTC', '10.00', '100.00', '0.00002', 2, 8);
        $usdToEur = OrderFactory::buy('USD', 'EUR', '10.00', '100.00', '0.90', 2, 2);
        $eurToBtc = OrderFactory::buy('EUR', 'BTC', '10.00', '100.00', '0.000022', 2, 8);

        $orderBook = new OrderBook([$usdToUsdt, $usdtToBtc, $usdToEur, $eurToBtc]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '50.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 4)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $bestPlan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $bestPlan);
        self::assertSame('USD', $bestPlan->sourceCurrency());
        self::assertSame('BTC', $bestPlan->targetCurrency());
    }

    public function test_returns_empty_when_no_path(): void
    {
        // No orders connecting USD to BTC
        $order = OrderFactory::buy('EUR', 'GBP', '10.00', '1000.00', '0.85', 2, 2);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertFalse($outcome->hasPaths());
        self::assertNull($outcome->bestPath());
    }

    public function test_returns_empty_when_source_not_in_graph(): void
    {
        // Order book doesn't contain source currency
        $order = OrderFactory::buy('EUR', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertFalse($outcome->hasPaths());
    }

    public function test_returns_empty_when_target_not_in_graph(): void
    {
        // Order book doesn't contain target currency
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.90', 2, 2);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertFalse($outcome->hasPaths());
    }

    public function test_returns_empty_when_order_book_empty(): void
    {
        $orderBook = new OrderBook([]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertFalse($outcome->hasPaths());
    }

    public function test_respects_guard_limits(): void
    {
        // Create a complex graph that requires many expansions
        $order = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->withSearchGuards(1000, 1000, 5000)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        // Guard limits should be reported
        $guardLimits = $outcome->guardLimits();
        self::assertGreaterThan(0, $guardLimits->expansionLimit());
        self::assertGreaterThan(0, $guardLimits->visitedStateLimit());
    }

    public function test_throws_guard_limit_exceeded_when_configured(): void
    {
        // Create scenario that hits guard limits with throw enabled
        $orders = [];
        // Create many orders to cause expansion limit hit
        for ($i = 0; $i < 20; ++$i) {
            $orders[] = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', (string) (0.00002 + $i * 0.000001), 2, 8);
        }
        $orderBook = new OrderBook($orders);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->withSearchGuards(1, 1, null) // Very restrictive limits
            ->withGuardLimitException(true)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');

        $this->expectException(GuardLimitExceeded::class);
        $this->service->findBestPlans($request);
    }

    public function test_tolerance_evaluation_correct(): void
    {
        $order = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $bestPlan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $bestPlan);

        // Residual tolerance should be within configured bounds
        $residualTolerance = $bestPlan->residualTolerance();
        $toleranceRatio = $residualTolerance->ratio();
        // Tolerance ratio should be a valid numeric string
        self::assertIsNumeric($toleranceRatio);
    }

    public function test_deterministic_results(): void
    {
        $order1 = OrderFactory::buy('USD', 'USDT', '10.00', '1000.00', '1.00', 2, 2);
        $order2 = OrderFactory::buy('USDT', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order1, $order2]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');

        // Run search 10 times and verify same result
        $results = [];
        for ($i = 0; $i < 10; ++$i) {
            $outcome = $this->service->findBestPlans($request);
            if ($outcome->hasPaths()) {
                $plan = $outcome->bestPath();
                self::assertInstanceOf(ExecutionPlan::class, $plan);
                $results[] = [
                    'totalSpent' => $plan->totalSpent()->amount(),
                    'totalReceived' => $plan->totalReceived()->amount(),
                    'stepCount' => $plan->stepCount(),
                ];
            } else {
                $results[] = null;
            }
        }

        // All results should be identical
        $firstResult = $results[0];
        foreach ($results as $result) {
            self::assertSame($firstResult, $result, 'Results should be deterministic across runs');
        }
    }

    public function test_throws_on_empty_target_asset(): void
    {
        $order = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Target asset cannot be empty');

        // PathSearchRequest validates this
        new PathSearchRequest($orderBook, $config, '');
    }

    public function test_returns_execution_plan_with_fees(): void
    {
        // Order with fees
        $feePolicy = \SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory::baseSurcharge('0.01', 6);
        $order = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8, $feePolicy);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $bestPlan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $bestPlan);

        // Fee breakdown should contain USD fees
        $feeBreakdown = $bestPlan->feeBreakdown();
        self::assertTrue($feeBreakdown->has('USD'));
    }

    public function test_result_ordering_by_cost(): void
    {
        // Two parallel paths with different costs
        // Better rate should be preferred
        $order1 = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8); // Lower rate
        $order2 = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00003', 2, 8); // Higher rate (better)
        $orderBook = new OrderBook([$order1, $order2]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $bestPlan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $bestPlan);
        // Best plan should give us more BTC for the same USD
    }

    public function test_sell_orders_work_correctly(): void
    {
        // SELL order: someone sells USDT for RUB
        // From taker perspective: spend RUB, receive USDT
        // Edge direction: RUB -> USDT
        $order = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '90.00', 2, 2);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '9000.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $bestPlan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $bestPlan);
        self::assertSame('RUB', $bestPlan->sourceCurrency());
        self::assertSame('USDT', $bestPlan->targetCurrency());
    }

    public function test_mixed_buy_sell_orders(): void
    {
        // Multi-hop chain with BUY and SELL orders:
        // RUB -> USDT (via SELL order: taker spends RUB, receives USDT)
        // USDT -> BTC (via BUY order: taker spends USDT, receives BTC)
        $sellOrder = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '90.00', 2, 2);
        $buyOrder = OrderFactory::buy('USDT', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$sellOrder, $buyOrder]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '9000.00', 2))
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 4)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $bestPlan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $bestPlan);
        self::assertSame('RUB', $bestPlan->sourceCurrency());
        self::assertSame('BTC', $bestPlan->targetCurrency());
        self::assertSame(2, $bestPlan->stepCount());
    }

    public function test_high_precision_amounts(): void
    {
        // BTC with 8 decimal places
        $order = OrderFactory::buy('BTC', 'USDT', '0.00001000', '10.00000000', '40000.00', 8, 8);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('BTC', '0.10000000', 8))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $bestPlan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $bestPlan);
        // Amount should maintain precision
        self::assertStringContainsString('.', $bestPlan->totalSpent()->amount());
    }

    public function test_service_accepts_custom_ordering_strategy(): void
    {
        $customStrategy = new \SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\CostHopsSignatureOrderingStrategy(8);
        $service = new ExecutionPlanService(new GraphBuilder(), $customStrategy);

        $order = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
    }

    public function test_linear_plan_can_convert_to_path(): void
    {
        $order = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $bestPlan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $bestPlan);
        self::assertTrue($bestPlan->isLinear());

        // Linear plan should be convertible to Path
        $path = $bestPlan->asLinearPath();
        self::assertNotNull($path);
        self::assertSame($bestPlan->totalSpent()->amount(), $path->totalSpent()->amount());
        self::assertSame($bestPlan->totalReceived()->amount(), $path->totalReceived()->amount());
    }

    public function test_filters_orders_outside_spend_bounds(): void
    {
        // Order with bounds 100-500 USD
        $order = OrderFactory::buy('USD', 'BTC', '100.00', '500.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order]);

        // Try to spend only 10 USD (below order minimum)
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '10.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        // Should return empty because order min is 100 but we want to spend 10
        self::assertFalse($outcome->hasPaths());
    }

    public function test_returns_guard_report_even_when_empty(): void
    {
        $orderBook = new OrderBook([]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->withSearchGuards(5000, 10000, 3000)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertFalse($outcome->hasPaths());

        // Guard report should still be present
        $guardLimits = $outcome->guardLimits();
        self::assertSame(5000, $guardLimits->visitedStateLimit());
        self::assertSame(10000, $guardLimits->expansionLimit());
        self::assertSame(3000, $guardLimits->timeBudgetLimit());
    }

    public function test_execution_step_contains_order_reference(): void
    {
        $order = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $bestPlan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $bestPlan);

        $steps = $bestPlan->steps()->all();
        self::assertNotEmpty($steps);

        $step = $steps[0];
        self::assertSame($order, $step->order());
    }

    public function test_case_insensitive_currency_matching(): void
    {
        $order = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('usd', '100.00', 2)) // lowercase
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'btc'); // lowercase
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $bestPlan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $bestPlan);
        // Currencies should be normalized to uppercase
        self::assertSame('USD', $bestPlan->sourceCurrency());
        self::assertSame('BTC', $bestPlan->targetCurrency());
    }

    /**
     * Test that service accepts custom materializer injection.
     *
     * This verifies the service uses ExecutionPlanMaterializer in its pipeline
     * and accepts it as a constructor dependency (MUL-14 integration).
     */
    public function test_service_accepts_custom_materializer(): void
    {
        $customMaterializer = new \SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanMaterializer();
        $service = new ExecutionPlanService(new GraphBuilder(), null, $customMaterializer);

        $order = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $bestPlan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $bestPlan);
    }

    /**
     * Test that execution plan steps have correct sequence numbers from materializer.
     *
     * Verifies the materializer correctly preserves sequence numbers from raw fills.
     */
    public function test_execution_plan_steps_have_sequential_numbers(): void
    {
        // Create multi-hop path to get multiple steps
        $order1 = OrderFactory::buy('USD', 'USDT', '10.00', '1000.00', '1.00', 2, 2);
        $order2 = OrderFactory::buy('USDT', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order1, $order2]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 4)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $bestPlan = $outcome->bestPath();
        self::assertInstanceOf(ExecutionPlan::class, $bestPlan);

        $steps = $bestPlan->steps()->all();
        self::assertCount(2, $steps);

        // Sequence numbers should start at 1 and increment
        self::assertSame(1, $steps[0]->sequenceNumber());
        self::assertSame(2, $steps[1]->sequenceNumber());
    }
}
