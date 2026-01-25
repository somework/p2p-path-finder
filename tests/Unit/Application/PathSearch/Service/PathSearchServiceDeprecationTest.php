<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Response\SearchOutcome;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionStep;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionStepCollection;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\Path;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use const E_USER_DEPRECATED;

#[CoversClass(PathSearchService::class)]
#[CoversClass(InvalidInput::class)]
final class PathSearchServiceDeprecationTest extends TestCase
{
    private PathSearchService $service;

    protected function setUp(): void
    {
        $this->service = new PathSearchService(new GraphBuilder());
    }

    public function test_deprecated_service_still_works(): void
    {
        $order = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');

        // Suppress deprecation notice for this test
        $previousHandler = set_error_handler(static fn () => true, E_USER_DEPRECATED);

        try {
            $outcome = $this->service->findBestPaths($request);

            self::assertInstanceOf(SearchOutcome::class, $outcome);
            self::assertTrue($outcome->hasPaths());

            $bestPath = $outcome->bestPath();
            self::assertInstanceOf(Path::class, $bestPath);
            self::assertSame('USD', $bestPath->totalSpent()->currency());
            self::assertSame('BTC', $bestPath->totalReceived()->currency());
        } finally {
            restore_error_handler();
        }
    }

    public function test_returns_same_results_as_before_for_linear_paths(): void
    {
        // Multi-hop linear path: USD -> USDT -> BTC
        $order1 = OrderFactory::buy('USD', 'USDT', '10.00', '1000.00', '1.00', 2, 2);
        $order2 = OrderFactory::buy('USDT', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order1, $order2]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');

        // Suppress deprecation notice for this test
        $previousHandler = set_error_handler(static fn () => true, E_USER_DEPRECATED);

        try {
            $outcome = $this->service->findBestPaths($request);

            self::assertTrue($outcome->hasPaths());
            $bestPath = $outcome->bestPath();
            self::assertInstanceOf(Path::class, $bestPath);

            // Verify it's a 2-hop path
            $hops = $bestPath->hops();
            self::assertCount(2, $hops);

            // Verify the path structure
            self::assertSame('USD', $hops->at(0)->from());
            self::assertSame('USDT', $hops->at(0)->to());
            self::assertSame('USDT', $hops->at(1)->from());
            self::assertSame('BTC', $hops->at(1)->to());
        } finally {
            restore_error_handler();
        }
    }

    public function test_returns_only_linear_paths(): void
    {
        // Single linear order
        $order = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');

        // Suppress deprecation notice for this test
        $previousHandler = set_error_handler(static fn () => true, E_USER_DEPRECATED);

        try {
            $outcome = $this->service->findBestPaths($request);

            self::assertTrue($outcome->hasPaths());

            // All returned results should be Path objects (linear)
            foreach ($outcome->paths()->toArray() as $path) {
                self::assertInstanceOf(Path::class, $path);
            }
        } finally {
            restore_error_handler();
        }
    }

    public function test_plan_to_path_conversion_works(): void
    {
        // Create a linear ExecutionPlan and convert it
        $order = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);

        $step = new ExecutionStep(
            order: $order,
            from: 'USD',
            to: 'BTC',
            spent: Money::fromString('USD', '100.00', 2),
            received: Money::fromString('BTC', '0.00200000', 8),
            fees: MoneyMap::empty(),
            sequenceNumber: 1,
        );

        $steps = ExecutionStepCollection::fromList([$step]);
        $plan = ExecutionPlan::fromSteps(
            $steps,
            'USD',
            'BTC',
            DecimalTolerance::zero(),
        );

        // Verify plan is linear
        self::assertTrue($plan->isLinear());

        // Convert to Path
        $path = PathSearchService::planToPath($plan);

        self::assertInstanceOf(Path::class, $path);
        self::assertSame($plan->totalSpent()->amount(), $path->totalSpent()->amount());
        self::assertSame($plan->totalReceived()->amount(), $path->totalReceived()->amount());
        self::assertSame('USD', $path->totalSpent()->currency());
        self::assertSame('BTC', $path->totalReceived()->currency());
    }

    public function test_plan_to_path_conversion_works_for_multi_hop(): void
    {
        // Create a multi-hop linear ExecutionPlan
        $order1 = OrderFactory::buy('USD', 'USDT', '10.00', '1000.00', '1.00', 2, 2);
        $order2 = OrderFactory::buy('USDT', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);

        $step1 = new ExecutionStep(
            order: $order1,
            from: 'USD',
            to: 'USDT',
            spent: Money::fromString('USD', '100.00', 2),
            received: Money::fromString('USDT', '100.00', 2),
            fees: MoneyMap::empty(),
            sequenceNumber: 1,
        );

        $step2 = new ExecutionStep(
            order: $order2,
            from: 'USDT',
            to: 'BTC',
            spent: Money::fromString('USDT', '100.00', 2),
            received: Money::fromString('BTC', '0.00200000', 8),
            fees: MoneyMap::empty(),
            sequenceNumber: 2,
        );

        $steps = ExecutionStepCollection::fromList([$step1, $step2]);
        $plan = ExecutionPlan::fromSteps(
            $steps,
            'USD',
            'BTC',
            DecimalTolerance::zero(),
        );

        // Verify plan is linear
        self::assertTrue($plan->isLinear());

        // Convert to Path
        $path = PathSearchService::planToPath($plan);

        self::assertInstanceOf(Path::class, $path);
        self::assertCount(2, $path->hops());

        $hops = $path->hops();
        self::assertSame('USD', $hops->at(0)->from());
        self::assertSame('USDT', $hops->at(0)->to());
        self::assertSame('USDT', $hops->at(1)->from());
        self::assertSame('BTC', $hops->at(1)->to());
    }

    public function test_plan_to_path_throws_for_non_linear_plan(): void
    {
        // Create a non-linear ExecutionPlan (split scenario)
        // USD -> BTC via two parallel orders
        $order1 = OrderFactory::buy('USD', 'BTC', '10.00', '100.00', '0.00002', 2, 8);
        $order2 = OrderFactory::buy('USD', 'BTC', '10.00', '100.00', '0.000021', 2, 8);

        $step1 = new ExecutionStep(
            order: $order1,
            from: 'USD',
            to: 'BTC',
            spent: Money::fromString('USD', '50.00', 2),
            received: Money::fromString('BTC', '0.00100000', 8),
            fees: MoneyMap::empty(),
            sequenceNumber: 1,
        );

        $step2 = new ExecutionStep(
            order: $order2,
            from: 'USD',
            to: 'BTC',
            spent: Money::fromString('USD', '50.00', 2),
            received: Money::fromString('BTC', '0.00105000', 8),
            fees: MoneyMap::empty(),
            sequenceNumber: 2,
        );

        // This creates a non-linear plan: two steps both spending USD
        $steps = ExecutionStepCollection::fromList([$step1, $step2]);
        $plan = ExecutionPlan::fromSteps(
            $steps,
            'USD',
            'BTC',
            DecimalTolerance::zero(),
        );

        // Verify plan is not linear (two steps from same source)
        self::assertFalse($plan->isLinear());

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Cannot convert non-linear execution plan to Path');

        PathSearchService::planToPath($plan);
    }

    public function test_deprecation_notice_is_triggered(): void
    {
        $order = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');

        $deprecationTriggered = false;
        $deprecationMessage = '';

        set_error_handler(static function (int $errno, string $errstr) use (&$deprecationTriggered, &$deprecationMessage): bool {
            if (E_USER_DEPRECATED === $errno) {
                $deprecationTriggered = true;
                $deprecationMessage = $errstr;
            }

            return true;
        }, E_USER_DEPRECATED);

        try {
            $this->service->findBestPaths($request);

            self::assertTrue($deprecationTriggered, 'Deprecation notice should be triggered');
            self::assertStringContainsString('PathSearchService::findBestPaths()', $deprecationMessage);
            self::assertStringContainsString('ExecutionPlanService::findBestPlans()', $deprecationMessage);
            self::assertStringContainsString('deprecated', $deprecationMessage);
        } finally {
            restore_error_handler();
        }
    }

    public function test_returns_empty_when_no_paths_exist(): void
    {
        // Order book with no path from USD to BTC
        $order = OrderFactory::buy('EUR', 'GBP', '10.00', '1000.00', '0.85', 2, 2);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');

        // Suppress deprecation notice
        set_error_handler(static fn () => true, E_USER_DEPRECATED);

        try {
            $outcome = $this->service->findBestPaths($request);

            self::assertFalse($outcome->hasPaths());
            self::assertNull($outcome->bestPath());
        } finally {
            restore_error_handler();
        }
    }

    public function test_guard_limits_are_reported(): void
    {
        $order = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->withSearchGuards(5000, 10000, 3000)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');

        // Suppress deprecation notice
        set_error_handler(static fn () => true, E_USER_DEPRECATED);

        try {
            $outcome = $this->service->findBestPaths($request);

            $guardLimits = $outcome->guardLimits();
            self::assertSame(5000, $guardLimits->visitedStateLimit());
            self::assertSame(10000, $guardLimits->expansionLimit());
            self::assertSame(3000, $guardLimits->timeBudgetLimit());
        } finally {
            restore_error_handler();
        }
    }

    public function test_comparison_with_execution_plan_service(): void
    {
        // Run the same request through both services and verify linear plans match
        $order = OrderFactory::buy('USD', 'BTC', '10.00', '1000.00', '0.00002', 2, 8);
        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');

        // Run through deprecated service
        set_error_handler(static fn () => true, E_USER_DEPRECATED);

        try {
            $pathOutcome = $this->service->findBestPaths($request);
        } finally {
            restore_error_handler();
        }

        // Run through new service
        $executionPlanService = new ExecutionPlanService(new GraphBuilder());
        $planOutcome = $executionPlanService->findBestPlans($request);

        // Both should find results
        self::assertTrue($pathOutcome->hasPaths());
        self::assertTrue($planOutcome->hasPaths());

        $path = $pathOutcome->bestPath();
        $plan = $planOutcome->bestPath();

        self::assertInstanceOf(Path::class, $path);
        self::assertInstanceOf(ExecutionPlan::class, $plan);

        // If plan is linear, the converted path should match
        if ($plan->isLinear()) {
            $convertedPath = $plan->asLinearPath();
            self::assertNotNull($convertedPath);

            // Both should have same number of hops/steps
            self::assertSame($path->hops()->count(), $convertedPath->hops()->count());

            // Both should target BTC
            self::assertSame('BTC', $path->totalReceived()->currency());
            self::assertSame('BTC', $convertedPath->totalReceived()->currency());
        }
    }
}
