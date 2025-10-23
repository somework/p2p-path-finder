<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service\PathFinder;

use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\Graph;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\GuardLimitStatus;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchOutcome;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function array_pad;
use function count;
use function is_array;

/**
 * @covers \SomeWork\P2PPathFinder\Application\Service\PathFinderService
 *
 * @group acceptance
 */
final class PathFinderServiceGuardsTest extends PathFinderServiceTestCase
{
    public function test_it_rejects_candidates_that_do_not_meet_minimum_hops(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(2, 3)
            ->build();

        $result = $this->makeService()->findBestPaths($orderBook, $config, 'USD');

        self::assertSame([], $result->paths());
        self::assertFalse($result->guardLimits()->expansionsReached());
        self::assertFalse($result->guardLimits()->visitedStatesReached());
        self::assertFalse($result->guardLimits()->timeBudgetReached());
    }

    public function test_it_ignores_candidates_without_initial_seed_resolution(): void
    {
        $orderBook = $this->orderBook(
            OrderFactory::sell(
                base: 'BTC',
                quote: 'USD',
                minAmount: '0.500',
                maxAmount: '0.750',
                rate: '100.00',
                amountScale: 3,
                rateScale: 2,
                feePolicy: FeePolicyFactory::baseAndQuoteSurcharge('0.000000', '0.50', 3),
            ),
        );

        $service = new PathFinderService(new GraphBuilder());

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 3)
            ->build();

        $result = $service->findBestPaths($orderBook, $config, 'BTC');

        self::assertSame([], $result->paths());
        self::assertFalse($result->guardLimits()->expansionsReached());
        self::assertFalse($result->guardLimits()->visitedStatesReached());
        self::assertFalse($result->guardLimits()->timeBudgetReached());
    }

    public function test_it_filters_candidates_that_exceed_tolerance_after_materialization(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(
                OrderSide::SELL,
                'USD',
                'EUR',
                '100.000',
                '200.000',
                '1.000',
                3,
                $this->percentageFeePolicy('0.10'),
            ),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(1, 1)
            ->build();

        $result = $this->makeService()->findBestPaths($orderBook, $config, 'USD');

        self::assertSame([], $result->paths());
        self::assertFalse($result->guardLimits()->expansionsReached());
        self::assertFalse($result->guardLimits()->visitedStatesReached());
        self::assertFalse($result->guardLimits()->timeBudgetReached());
    }

    public function test_it_skips_candidates_without_edges(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '50.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 2)
            ->build();

        $factory = $this->pathFinderFactoryForCandidates([
            [
                'cost' => '1.000000000000000000',
                'product' => '1.000000000000000000',
                'hops' => 0,
                'edges' => [],
                'amountRange' => null,
                'desiredAmount' => null,
            ],
        ]);

        $service = $this->makeServiceWithFactory($factory);

        $result = $service->findBestPaths($orderBook, $config, 'USD');

        self::assertSame([], $result->paths());
    }

    public function test_it_ignores_candidates_with_mismatched_source_currency(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '75.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 2)
            ->build();

        $factory = $this->pathFinderFactoryForCandidates([
            [
                'cost' => '1.000000000000000000',
                'product' => '1.000000000000000000',
                'hops' => 1,
                'edges' => [
                    [
                        'from' => 'USD',
                        'to' => 'EUR',
                    ],
                ],
                'amountRange' => null,
                'desiredAmount' => null,
            ],
        ]);

        $service = $this->makeServiceWithFactory($factory);

        $result = $service->findBestPaths($orderBook, $config, 'USD');

        self::assertSame([], $result->paths());
    }

    public function test_it_maintains_insertion_order_for_equal_cost_results(): void
    {
        $orders = [
            OrderFactory::sell('USD', 'EUR', '10.000', '500.000', '0.950', 3, 3),
            OrderFactory::sell('USD', 'EUR', '10.000', '500.000', '0.850', 3, 3),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $edgeA = $graph['EUR']['edges'][0];
        $edgeB = $graph['EUR']['edges'][1];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.000', 3))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 2)
            ->build();

        $factory = $this->pathFinderFactoryForCandidates([
            [
                'cost' => '1.000000000000000000',
                'product' => '1.000000000000000000',
                'hops' => 1,
                'edges' => [$edgeA],
                'amountRange' => null,
                'desiredAmount' => null,
            ],
            [
                'cost' => '1.000000000000000000',
                'product' => '1.000000000000000000',
                'hops' => 1,
                'edges' => [$edgeB],
                'amountRange' => null,
                'desiredAmount' => null,
            ],
        ]);

        $service = $this->makeServiceWithFactory($factory);

        $result = $service->findBestPaths($this->orderBookFromArray($orders), $config, 'USD');

        $paths = $result->paths();
        self::assertCount(2, $paths);
        self::assertSame('105.300', $paths[0]->totalReceived()->amount());
        self::assertSame('117.700', $paths[1]->totalReceived()->amount());
    }

    public function test_it_reports_guard_limits_via_metadata_by_default(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();
        $guardStatus = new GuardLimitStatus(true, false, false);
        $factory = $this->pathFinderFactoryForCandidates([], $guardStatus);
        $service = $this->makeServiceWithFactory($factory);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 2)
            ->build();

        $outcome = $service->findBestPaths($orderBook, $config, 'USD');

        self::assertSame([], $outcome->paths());
        self::assertTrue($outcome->guardLimits()->expansionsReached());
        self::assertFalse($outcome->guardLimits()->visitedStatesReached());
        self::assertFalse($outcome->guardLimits()->timeBudgetReached());
    }

    public function test_it_can_escalate_guard_limit_breaches_to_exception(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();
        $guardStatus = new GuardLimitStatus(true, true, false);
        $factory = $this->pathFinderFactoryForCandidates([], $guardStatus);
        $service = $this->makeServiceWithFactory($factory);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 2)
            ->withSearchGuards(100, 200)
            ->withGuardLimitException()
            ->build();

        $this->expectException(GuardLimitExceeded::class);
        $this->expectExceptionMessage('Search guard limit exceeded: expansions limit of 200 and visited states limit of 100.');

        $service->findBestPaths($orderBook, $config, 'USD');
    }

    public function test_it_describes_single_guard_limit_breach_when_throwing_exception(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();
        $guardStatus = new GuardLimitStatus(true, false, false);
        $factory = $this->pathFinderFactoryForCandidates([], $guardStatus);
        $service = $this->makeServiceWithFactory($factory);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 2)
            ->withSearchGuards(50, 200)
            ->withGuardLimitException()
            ->build();

        $this->expectException(GuardLimitExceeded::class);
        $this->expectExceptionMessage('Search guard limit exceeded: expansions limit of 200.');

        $service->findBestPaths($orderBook, $config, 'USD');
    }

    public function test_it_describes_visited_states_guard_limit_when_throwing_exception(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();
        $guardStatus = new GuardLimitStatus(false, true, false);
        $factory = $this->pathFinderFactoryForCandidates([], $guardStatus);
        $service = $this->makeServiceWithFactory($factory);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 2)
            ->withSearchGuards(25, 50)
            ->withGuardLimitException()
            ->build();

        $this->expectException(GuardLimitExceeded::class);
        $this->expectExceptionMessage('Search guard limit exceeded: visited states limit of 25.');

        $service->findBestPaths($orderBook, $config, 'USD');
    }

    public function test_it_reports_time_budget_guard_via_metadata(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();
        $guardStatus = new GuardLimitStatus(false, false, true);
        $factory = $this->pathFinderFactoryForCandidates([], $guardStatus);
        $service = $this->makeServiceWithFactory($factory);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 2)
            ->withSearchTimeBudget(1)
            ->build();

        $outcome = $service->findBestPaths($orderBook, $config, 'USD');

        self::assertTrue($outcome->guardLimits()->timeBudgetReached());
        self::assertFalse($outcome->guardLimits()->expansionsReached());
        self::assertFalse($outcome->guardLimits()->visitedStatesReached());
    }

    public function test_it_includes_time_budget_guard_in_exception_message(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();
        $guardStatus = new GuardLimitStatus(false, false, true);
        $factory = $this->pathFinderFactoryForCandidates([], $guardStatus);
        $service = $this->makeServiceWithFactory($factory);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 2)
            ->withSearchTimeBudget(2)
            ->withGuardLimitException()
            ->build();

        $this->expectException(GuardLimitExceeded::class);
        $this->expectExceptionMessage('Search guard limit exceeded: time budget of 2ms.');

        $service->findBestPaths($orderBook, $config, 'USD');
    }

    private function simpleEuroToUsdOrderBook(): OrderBook
    {
        return $this->orderBook(
            OrderFactory::sell('USD', 'EUR', '10.000', '200.000', '0.900', 3),
        );
    }

    /**
     * @param list<CandidatePath|array{cost: numeric-string, product: numeric-string, hops: int, edges: list<array>, amountRange: mixed, desiredAmount: mixed}> $candidates
     */
    private function pathFinderFactoryForCandidates(array $candidates, ?GuardLimitStatus $guardLimits = null): callable
    {
        $normalized = array_map(
            static function ($candidate): CandidatePath {
                if ($candidate instanceof CandidatePath) {
                    return $candidate;
                }

                $range = null;
                if (is_array($candidate['amountRange'])) {
                    $range = SpendConstraints::from(
                        $candidate['amountRange']['min'],
                        $candidate['amountRange']['max'],
                        $candidate['desiredAmount'],
                    );
                }

                return CandidatePath::from(
                    $candidate['cost'],
                    $candidate['product'],
                    $candidate['hops'],
                    self::normalizeEdges($candidate['edges'], $candidate['hops']),
                    $range,
                );
            },
            $candidates,
        );

        return static function (PathSearchConfig $config) use ($normalized, $guardLimits): callable {
            return static function (Graph $graph, string $source, string $target, ?SpendConstraints $range, callable $callback) use ($normalized, $guardLimits): SearchOutcome {
                foreach ($normalized as $candidate) {
                    $callback($candidate);
                }

                return new SearchOutcome([], $guardLimits ?? GuardLimitStatus::none());
            };
        };
    }

    /**
     * @param list<array> $edges
     *
     * @return list<array>
     */
    private static function normalizeEdges(array $edges, int $hops): array
    {
        if (count($edges) === $hops) {
            return $edges;
        }

        if ([] === $edges) {
            return [];
        }

        $order = OrderFactory::sell('SRC', 'DST', '1.000', '1.000', '1.000', 3, 3);
        $template = [
            'from' => 'SRC',
            'to' => 'DST',
            'order' => $order,
            'rate' => $order->effectiveRate(),
            'orderSide' => OrderSide::SELL,
            'conversionRate' => BcMath::normalize('1.000000000000000000', 18),
        ];

        return array_pad($edges, $hops, $template);
    }
}
