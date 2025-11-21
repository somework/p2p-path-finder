<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service\PathFinder;

use Brick\Math\BigDecimal;
use Closure;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\Graph;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchOutcome;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Application\Service\PathSearchRequest;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Tests\Application\Support\DecimalFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function chr;
use function count;
use function is_array;
use function sprintf;

/**
 * @covers \SomeWork\P2PPathFinder\Application\Service\PathFinderService
 *
 * @group acceptance
 */
final class PathFinderServiceGuardsTest extends PathFinderServiceTestCase
{
    private const SCALE = 18;

    public function test_it_rejects_candidates_that_do_not_meet_minimum_hops(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(2, 3)
            ->build();

        $request = $this->makeRequest($orderBook, $config, 'USD');
        $result = $this->makeService()->findBestPaths($request);

        self::assertSame([], $result->paths()->toArray());
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

        $request = $this->makeRequest($orderBook, $config, 'BTC');
        $result = $service->findBestPaths($request);

        self::assertSame([], $result->paths()->toArray());
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

        $request = $this->makeRequest($orderBook, $config, 'USD');
        $result = $this->makeService()->findBestPaths($request);

        self::assertSame([], $result->paths()->toArray());
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
                'cost' => DecimalFactory::decimal('1.000000000000000000', self::SCALE),
                'product' => DecimalFactory::decimal('1.000000000000000000', self::SCALE),
                'hops' => 0,
                'edges' => [],
                'amountRange' => null,
                'desiredAmount' => null,
            ],
        ]);

        $service = $this->makeServiceWithFactory($factory);

        $request = $this->makeRequest($orderBook, $config, 'USD');
        $result = $service->findBestPaths($request);

        self::assertSame([], $result->paths()->toArray());
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
                'cost' => DecimalFactory::decimal('1.000000000000000000', self::SCALE),
                'product' => DecimalFactory::decimal('1.000000000000000000', self::SCALE),
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

        $request = $this->makeRequest($orderBook, $config, 'USD');
        $result = $service->findBestPaths($request);

        self::assertSame([], $result->paths()->toArray());
    }

    public function test_it_maintains_insertion_order_for_equal_cost_results(): void
    {
        $orders = [
            OrderFactory::sell('USD', 'EUR', '10.000', '500.000', '0.950', 3, 3),
            OrderFactory::sell('USD', 'EUR', '10.000', '500.000', '0.850', 3, 3),
        ];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.000', 3))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 2)
            ->build();

        $graph = (new GraphBuilder())->build($orders);
        $edgeA = $this->edge($graph, 'EUR', 0);
        $edgeB = $this->edge($graph, 'EUR', 1);

        $factory = $this->pathFinderFactoryForCandidates([
            [
                'cost' => DecimalFactory::decimal('1.000000000000000000', self::SCALE),
                'product' => DecimalFactory::decimal('1.000000000000000000', self::SCALE),
                'hops' => 1,
                'edges' => [$edgeA],
                'amountRange' => null,
                'desiredAmount' => null,
            ],
            [
                'cost' => DecimalFactory::decimal('1.000000000000000000', self::SCALE),
                'product' => DecimalFactory::decimal('1.000000000000000000', self::SCALE),
                'hops' => 1,
                'edges' => [$edgeB],
                'amountRange' => null,
                'desiredAmount' => null,
            ],
        ]);

        $service = $this->makeServiceWithFactory($factory);
        $orderBook = $this->orderBookFromArray($orders);
        $request = $this->makeRequest($orderBook, $config, 'USD');
        $result = $service->findBestPaths($request);

        $paths = $result->paths()->toArray();
        self::assertCount(1, $paths);
        self::assertSame('117.700', $paths[0]->totalReceived()->amount());
    }

    public function test_it_reports_guard_limits_via_metadata_by_default(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();
        $guardStatus = new SearchGuardReport(true, false, false, 10, 0, 0.0, 10, 25, null);
        $factory = $this->pathFinderFactoryForCandidates([], $guardStatus);
        $service = $this->makeServiceWithFactory($factory);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 2)
            ->build();

        $request = $this->makeRequest($orderBook, $config, 'USD');
        $outcome = $service->findBestPaths($request);

        self::assertSame([], $outcome->paths()->toArray());
        self::assertTrue($outcome->guardLimits()->expansionsReached());
        self::assertFalse($outcome->guardLimits()->visitedStatesReached());
        self::assertFalse($outcome->guardLimits()->timeBudgetReached());
    }

    public function test_it_can_escalate_guard_limit_breaches_to_exception(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();
        $guardStatus = new SearchGuardReport(true, true, false, 200, 100, 0.0, 200, 100, null);
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
        $this->expectExceptionMessage('Search guard limit exceeded: expansions 200/200 and visited states 100/100.');

        $request = $this->makeRequest($orderBook, $config, 'USD');
        $service->findBestPaths($request);
    }

    public function test_it_describes_single_guard_limit_breach_when_throwing_exception(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();
        $guardStatus = new SearchGuardReport(true, false, false, 200, 0, 0.0, 200, 50, null);
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
        $this->expectExceptionMessage('Search guard limit exceeded: expansions 200/200.');

        $request = $this->makeRequest($orderBook, $config, 'USD');
        $service->findBestPaths($request);
    }

    public function test_it_describes_visited_states_guard_limit_when_throwing_exception(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();
        $guardStatus = new SearchGuardReport(false, true, false, 0, 25, 0.0, 50, 25, null);
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
        $this->expectExceptionMessage('Search guard limit exceeded: visited states 25/25.');

        $request = $this->makeRequest($orderBook, $config, 'USD');
        $service->findBestPaths($request);
    }

    public function test_it_reports_time_budget_guard_via_metadata(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();
        $guardStatus = new SearchGuardReport(false, false, true, 0, 0, 25.0, 10, 25, 1);
        $factory = $this->pathFinderFactoryForCandidates([], $guardStatus);
        $service = $this->makeServiceWithFactory($factory);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 2)
            ->withSearchTimeBudget(1)
            ->build();

        $request = $this->makeRequest($orderBook, $config, 'USD');
        $outcome = $service->findBestPaths($request);

        $report = $outcome->guardLimits();
        self::assertTrue($report->timeBudgetReached());
        self::assertFalse($report->expansionsReached());
        self::assertFalse($report->visitedStatesReached());
        self::assertSame(1, $report->timeBudgetLimit());
        self::assertSame(25.0, $report->elapsedMilliseconds());
    }

    public function test_it_includes_time_budget_guard_in_exception_message(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();
        $guardStatus = new SearchGuardReport(false, false, true, 0, 0, 25.0, 10, 25, 2);
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
        $this->expectExceptionMessage('Search guard limit exceeded: elapsed 25.000ms/2ms.');

        $request = $this->makeRequest($orderBook, $config, 'USD');
        $service->findBestPaths($request);
    }

    private function simpleEuroToUsdOrderBook(): OrderBook
    {
        return $this->orderBook(
            OrderFactory::sell('USD', 'EUR', '10.000', '200.000', '0.900', 3),
        );
    }

    /**
     * @param list<CandidatePath|array{
     *     cost: BigDecimal|numeric-string,
     *     product: BigDecimal|numeric-string,
     *     hops: int,
     *     edges: list<array{
     *         from?: string,
     *         to?: string,
     *         order?: Order,
     *         rate?: ExchangeRate,
     *         orderSide?: OrderSide,
     *         conversionRate?: BigDecimal|numeric-string,
     *     }>,
     *     amountRange: mixed,
     *     desiredAmount: mixed,
     * }> $candidates
     *
     * @return Closure(PathSearchRequest):(Closure(Graph, callable(CandidatePath):bool):SearchOutcome<CandidatePath>)
     */
    private function pathFinderFactoryForCandidates(array $candidates, ?SearchGuardReport $guardLimits = null): Closure
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
                    self::normalizeDecimal($candidate['cost']),
                    self::normalizeDecimal($candidate['product']),
                    $candidate['hops'],
                    self::normalizeEdges($candidate['edges'], $candidate['hops']),
                    $range,
                );
            },
            $candidates,
        );

        return static function (PathSearchRequest $request) use ($normalized, $guardLimits): Closure {
            return static function (Graph $graph, callable $callback) use ($normalized, $guardLimits): SearchOutcome {
                foreach ($normalized as $candidate) {
                    $callback($candidate);
                }

                return new SearchOutcome(PathResultSet::empty(), $guardLimits ?? SearchGuardReport::none());
            };
        };
    }

    private static function normalizeEdges(array $edges, int $hops): PathEdgeSequence
    {
        if ([] === $edges && 0 === $hops) {
            return PathEdgeSequence::empty();
        }

        $normalized = array_map(
            static function ($edge): array {
                if ($edge instanceof PathEdge) {
                    return $edge->toArray();
                }

                if ($edge instanceof GraphEdge) {
                    return [
                        'from' => $edge->from(),
                        'to' => $edge->to(),
                        'order' => $edge->order(),
                        'rate' => $edge->rate(),
                        'orderSide' => $edge->orderSide(),
                        'conversionRate' => self::unitConversionRate(),
                    ];
                }

                return $edge;
            },
            $edges,
        );

        $normalizedEdges = [];
        $currentFrom = null;

        foreach ($normalized as $index => $edge) {
            $from = $edge['from'] ?? ($currentFrom ?? 'SRC');
            $to = $edge['to'] ?? sprintf('TMP%s', chr(65 + ($index % 26)));
            $orderSide = $edge['orderSide'] ?? OrderSide::SELL;

            $order = $edge['order'] ?? match ($orderSide) {
                OrderSide::BUY => OrderFactory::buy($from, $to, '1.000', '1.000', '1.000', 3, 3),
                OrderSide::SELL => OrderFactory::sell($to, $from, '1.000', '1.000', '1.000', 3, 3),
            };

            $normalizedEdges[] = [
                'from' => $from,
                'to' => $to,
                'order' => $order,
                'rate' => $edge['rate'] ?? $order->effectiveRate(),
                'orderSide' => $orderSide,
                'conversionRate' => $edge['conversionRate'] ?? self::unitConversionRate(),
            ];

            $currentFrom = $to;
        }

        while (count($normalizedEdges) < $hops) {
            $index = count($normalizedEdges);
            $from = $currentFrom ?? 'SRC';
            $to = sprintf('TMP%s', chr(65 + ($index % 26)));
            $order = OrderFactory::sell($to, $from, '1.000', '1.000', '1.000', 3, 3);

            $normalizedEdges[] = [
                'from' => $from,
                'to' => $to,
                'order' => $order,
                'rate' => $order->effectiveRate(),
                'orderSide' => OrderSide::SELL,
                'conversionRate' => self::unitConversionRate(),
            ];

            $currentFrom = $to;
        }

        return PathEdgeSequence::fromList(array_map(
            static fn (array $edge): PathEdge => PathEdge::create(
                $edge['from'],
                $edge['to'],
                $edge['order'],
                $edge['rate'],
                $edge['orderSide'],
                self::normalizeDecimal($edge['conversionRate']),
            ),
            $normalizedEdges,
        ));
    }

    /**
     * @param BigDecimal|numeric-string $value
     */
    private static function normalizeDecimal(BigDecimal|string $value): BigDecimal
    {
        return $value instanceof BigDecimal ? $value : BigDecimal::of($value);
    }

    private static function unitConversionRate(): BigDecimal
    {
        return DecimalFactory::unit(self::SCALE);
    }

    /**
     * @return list<GraphEdge>
     */
    private function edges(Graph $graph, string $currency): array
    {
        $node = $graph->node($currency);
        self::assertNotNull($node, sprintf('Graph is missing node for currency "%s".', $currency));

        return $node->edges()->toArray();
    }

    private function edge(Graph $graph, string $currency, int $index): GraphEdge
    {
        $edges = $this->edges($graph, $currency);
        self::assertArrayHasKey($index, $edges);

        return $edges[$index];
    }
}
