<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderStrategy;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\PathSearchEngine;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Queue\CandidateResultHeap;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdgeSequence;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalMath;
use SomeWork\P2PPathFinder\Tests\Helpers\SearchQueueTieBreakHarness;

#[CoversClass(PathSearchEngine::class)]
final class PathSearchEngineTest extends TestCase
{
    private const SCALE = 18;

    /**
     * @dataProvider provideInvalidMaxHops
     */
    public function test_it_requires_positive_max_hops(int $invalidMaxHops): void
    {
        $this->expectException(InvalidInput::class);

        new PathSearchEngine($invalidMaxHops, '0.0');
    }

    public function test_it_requires_positive_result_limit(): void
    {
        $this->expectException(InvalidInput::class);

        new PathSearchEngine(maxHops: 1, tolerance: '0.0', topK: 0);
    }

    public function test_it_requires_positive_expansion_guard(): void
    {
        $this->expectException(InvalidInput::class);

        new PathSearchEngine(maxHops: 1, tolerance: '0.0', topK: 1, maxExpansions: 0);
    }

    public function test_it_requires_positive_visited_state_guard(): void
    {
        $this->expectException(InvalidInput::class);

        new PathSearchEngine(maxHops: 1, tolerance: '0.0', topK: 1, maxExpansions: 1, maxVisitedStates: 0);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideInvalidMaxHops(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
    }

    /**
     * @dataProvider provideInvalidTolerances
     */
    public function test_it_requires_tolerance_within_expected_range(string $invalidTolerance): void
    {
        $this->expectException(InvalidInput::class);

        new PathSearchEngine(1, $invalidTolerance);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidTolerances(): iterable
    {
        yield 'negative' => ['-0.001'];
        yield 'greater_than_or_equal_to_one' => ['1.0'];
        yield 'string_out_of_range' => ['1.0000000000000001'];
    }

    /**
     * @dataProvider provideHighPrecisionTolerances
     */
    public function test_it_accepts_high_precision_string_tolerance(string $tolerance, string $expectedAmplifier): void
    {
        $finder = new PathSearchEngine(maxHops: 1, tolerance: $tolerance);

        $property = new ReflectionProperty(PathSearchEngine::class, 'toleranceAmplifier');
        $property->setAccessible(true);

        $amplifier = $property->getValue($finder);

        self::assertInstanceOf(BigDecimal::class, $amplifier);
        self::assertTrue(
            $amplifier->isEqualTo(BigDecimal::of($expectedAmplifier)),
            'Tolerance amplifier should match the expected normalized string.',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideHighPrecisionTolerances(): iterable
    {
        $normalized = DecimalMath::normalize('0.9999999999999999', self::SCALE);
        $complement = DecimalMath::sub('1', $normalized, self::SCALE);
        yield 'sixteen_nines' => [
            '0.9999999999999999',
            DecimalMath::div('1', $complement, self::SCALE),
        ];

        $tiny = DecimalMath::normalize('0.0000000000000001', self::SCALE);
        $tinyComplement = DecimalMath::sub('1', $tiny, self::SCALE);
        yield 'tiny_fraction' => [
            '0.0000000000000001',
            DecimalMath::div('1', $tinyComplement, self::SCALE),
        ];
    }

    public function test_it_orders_equal_cost_paths_by_hops_signature_and_discovery(): void
    {
        $finder = new PathSearchEngine(maxHops: 4, tolerance: '0.0');

        $extracted = SearchQueueTieBreakHarness::extractOrdering($finder);

        self::assertSame(
            [
                ['signature' => 'SRC->ALPHA->OMEGA', 'hops' => 2],
                ['signature' => 'SRC->ALPHA->OMEGA', 'hops' => 2],
                ['signature' => 'SRC->BETA->OMEGA', 'hops' => 2],
                ['signature' => 'SRC->ALPHA->MID->OMEGA', 'hops' => 3],
            ],
            $extracted,
        );
    }

    public function test_finalize_results_orders_candidates_by_cost_hops_and_signature(): void
    {
        $finder = new PathSearchEngine(maxHops: 3, tolerance: '0.0', topK: 3);

        $heapFactory = new ReflectionMethod(PathSearchEngine::class, 'createResultHeap');
        $heapFactory->setAccessible(true);
        /** @var CandidateResultHeap $heap */
        $heap = $heapFactory->invoke($finder);

        $recordResult = new ReflectionMethod(PathSearchEngine::class, 'recordResult');
        $recordResult->setAccessible(true);

        $candidates = [
            CandidatePath::from(
                BigDecimal::of('0.100000000000000000'),
                BigDecimal::of('10.000000000000000000'),
                2,
                PathEdgeSequence::fromList([
                    self::stubCandidateEdge('SRC', 'BET'),
                    self::stubCandidateEdge('BET', 'TRG'),
                ]),
            ),
            CandidatePath::from(
                BigDecimal::of('0.100000000000000000'),
                BigDecimal::of('10.000000000000000000'),
                2,
                PathEdgeSequence::fromList([
                    self::stubCandidateEdge('SRC', 'ALP'),
                    self::stubCandidateEdge('ALP', 'TRG'),
                ]),
            ),
            CandidatePath::from(
                BigDecimal::of('0.100000000000000000'),
                BigDecimal::of('10.000000000000000000'),
                1,
                PathEdgeSequence::fromList([
                    self::stubCandidateEdge('SRC', 'TRG'),
                ]),
            ),
        ];

        foreach ($candidates as $index => $candidate) {
            $recordResult->invoke($finder, $heap, $candidate, $index);
        }

        $finalize = new ReflectionMethod(PathSearchEngine::class, 'finalizeResults');
        $finalize->setAccessible(true);
        $finalizedSet = $finalize->invoke($finder, $heap);

        self::assertCount(3, $finalizedSet);

        $finalized = $finalizedSet->toArray();

        $expectedHops = [1, 2, 2];
        foreach ($finalized as $index => $candidate) {
            self::assertSame($expectedHops[$index], $candidate->hops());
            self::assertSame('0.100000000000000000', $candidate->cost());
        }

        $signatures = array_map(
            static fn (CandidatePath $candidate): string => self::routeSignatureFromEdges($candidate->edges()),
            $finalized,
        );

        self::assertSame(
            ['SRC->TRG', 'SRC->ALP->TRG', 'SRC->BET->TRG'],
            $signatures,
        );
    }

    public function test_finalize_results_honours_custom_ordering_strategy(): void
    {
        $strategy = new class implements PathOrderStrategy {
            public function compare(PathOrderKey $left, PathOrderKey $right): int
            {
                $comparison = $right->routeSignature()->compare($left->routeSignature());
                if (0 !== $comparison) {
                    return $comparison;
                }

                return $right->insertionOrder() <=> $left->insertionOrder();
            }
        };

        $finder = new PathSearchEngine(maxHops: 3, tolerance: '0.0', topK: 3, orderingStrategy: $strategy);

        $heapFactory = new ReflectionMethod(PathSearchEngine::class, 'createResultHeap');
        $heapFactory->setAccessible(true);
        /** @var CandidateResultHeap $heap */
        $heap = $heapFactory->invoke($finder);

        $recordResult = new ReflectionMethod(PathSearchEngine::class, 'recordResult');
        $recordResult->setAccessible(true);

        $candidates = [
            CandidatePath::from(
                BigDecimal::of('0.100000000000000000'),
                BigDecimal::of('10.000000000000000000'),
                2,
                PathEdgeSequence::fromList([
                    self::stubCandidateEdge('SRC', 'BET'),
                    self::stubCandidateEdge('BET', 'TRG'),
                ]),
            ),
            CandidatePath::from(
                BigDecimal::of('0.100000000000000000'),
                BigDecimal::of('10.000000000000000000'),
                2,
                PathEdgeSequence::fromList([
                    self::stubCandidateEdge('SRC', 'ALP'),
                    self::stubCandidateEdge('ALP', 'TRG'),
                ]),
            ),
            CandidatePath::from(
                BigDecimal::of('0.100000000000000000'),
                BigDecimal::of('10.000000000000000000'),
                1,
                PathEdgeSequence::fromList([
                    self::stubCandidateEdge('SRC', 'TRG'),
                ]),
            ),
        ];

        foreach ($candidates as $index => $candidate) {
            $recordResult->invoke($finder, $heap, $candidate, $index);
        }

        $finalize = new ReflectionMethod(PathSearchEngine::class, 'finalizeResults');
        $finalize->setAccessible(true);
        $finalized = $finalize->invoke($finder, $heap);

        $signatures = array_map(
            static fn (CandidatePath $candidate): string => self::routeSignatureFromEdges($candidate->edges()),
            $finalized->toArray(),
        );

        self::assertSame(
            ['SRC->TRG', 'SRC->BET->TRG', 'SRC->ALP->TRG'],
            $signatures,
        );
    }

    private static function routeSignatureFromEdges(PathEdgeSequence $edges): string
    {
        return RouteSignature::fromPathEdgeSequence($edges)->value();
    }

    private static function stubCandidateEdge(string $from, string $to): PathEdge
    {
        /** @var numeric-string $rate */
        $rate = '1.000000000000000000';

        return PathEdge::create(
            $from,
            $to,
            OrderFactory::createOrder(
                OrderSide::BUY,
                $from,
                $to,
                '1.000000000000000000',
                '1.000000000000000000',
                $rate,
                amountScale: self::SCALE,
                rateScale: self::SCALE,
            ),
            ExchangeRate::fromString($from, $to, $rate, self::SCALE),
            OrderSide::BUY,
            BigDecimal::of($rate),
        );
    }
}
