<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendRange;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

/**
 * Tests for SpendConstraints edge cases to ensure correct behavior at boundaries.
 *
 * SpendConstraints define the min/max spend amounts for path finding, typically derived
 * from a tolerance window. These tests verify constraint handling in edge cases.
 *
 * @internal
 */
#[CoversClass(SpendConstraints::class)]
#[CoversClass(SpendRange::class)]
final class SpendConstraintsEdgeCasesTest extends TestCase
{
    /**
     * @testdox Desired amount outside min/max bounds is handled correctly
     */
    public function testDesiredAmountOutsideBounds(): void
    {
        // Case 1: Desired < Min (should clamp to min during path finding)
        $constraints1 = SpendConstraints::fromScalars('USD', '100.000', '200.000', '50.000');
        self::assertSame('50.000000000000000000', $constraints1->desired()?->amount());
        self::assertSame('100.000000000000000000', $constraints1->min()->amount());
        self::assertSame('200.000000000000000000', $constraints1->max()->amount());

        // Case 2: Desired > Max (should clamp to max during path finding)
        $constraints2 = SpendConstraints::fromScalars('USD', '100.000', '200.000', '300.000');
        self::assertSame('300.000000000000000000', $constraints2->desired()?->amount());
        self::assertSame('100.000000000000000000', $constraints2->min()->amount());
        self::assertSame('200.000000000000000000', $constraints2->max()->amount());

        // Constraints themselves don't enforce desired ∈ [min, max]
        // PathFinder will clamp desired to feasible range during search
    }

    /**
     * @testdox Min = Max constraint represents single valid spend amount
     */
    public function testMinEqualsMaxSpendConstraint(): void
    {
        // Single valid spend amount: exactly 100 USD
        $constraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        self::assertSame('100.000000000000000000', $constraints->min()->amount());
        self::assertSame('100.000000000000000000', $constraints->max()->amount());
        self::assertSame('100.000000000000000000', $constraints->desired()?->amount());

        // Verify PathFinder can use this constraint
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '1.200', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $constraints, null);
        $paths = $result->paths()->toArray();

        // Should find path since 100 is within [50, 150] capacity
        self::assertGreaterThan(0, count($paths), 'Should find path with min=max constraint within capacity');
    }

    /**
     * @testdox Very wide tolerance window creates broad spend range
     */
    public function testWideToleranceSpendConstraints(): void
    {
        // 99% tolerance: desired ± 99%
        // Desired: 100, Range: [1, 199]
        $constraints = SpendConstraints::fromScalars(
            'USD',
            '1.000',     // 100 * 0.01
            '199.000',   // 100 * 1.99
            '100.000'
        );

        self::assertSame('1.000000000000000000', $constraints->min()->amount());
        self::assertSame('199.000000000000000000', $constraints->max()->amount());
        self::assertSame('100.000000000000000000', $constraints->desired()?->amount());

        // Verify PathFinder handles wide range
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '10.000', '150.000', '1.200', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $constraints, null);
        $paths = $result->paths()->toArray();

        // Should find path with feasible range [10, 150] (intersection of [1, 199] and [10, 150])
        self::assertGreaterThan(0, count($paths), 'Should find path with wide tolerance');
    }

    /**
     * @testdox Constraint violation detection works at boundaries
     */
    public function testConstraintViolationDetection(): void
    {
        // Case 1: Requested range entirely above capacity
        $orderBook1 = new OrderBook();
        $orderBook1->add(OrderFactory::buy('USD', 'EUR', '50.000', '100.000', '1.200', 3, 3));

        $graph1 = (new GraphBuilder())->build(iterator_to_array($orderBook1));
        $constraints1 = SpendConstraints::fromScalars('USD', '150.000', '200.000'); // > capacity max

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result1 = $pathFinder->findBestPaths($graph1, 'USD', 'EUR', $constraints1, null);
        self::assertCount(0, $result1->paths()->toArray(), 'Should find no paths when requestedMin > capacityMax');

        // Case 2: Requested range entirely below capacity
        $orderBook2 = new OrderBook();
        $orderBook2->add(OrderFactory::buy('USD', 'EUR', '100.000', '200.000', '1.200', 3, 3));

        $graph2 = (new GraphBuilder())->build(iterator_to_array($orderBook2));
        $constraints2 = SpendConstraints::fromScalars('USD', '50.000', '80.000'); // < capacity min

        $result2 = $pathFinder->findBestPaths($graph2, 'USD', 'EUR', $constraints2, null);
        self::assertCount(0, $result2->paths()->toArray(), 'Should find no paths when requestedMax < capacityMin');

        // Case 3: Exact boundary match (requested max == capacity min)
        $orderBook3 = new OrderBook();
        $orderBook3->add(OrderFactory::buy('USD', 'EUR', '100.000', '200.000', '1.200', 3, 3));

        $graph3 = (new GraphBuilder())->build(iterator_to_array($orderBook3));
        $constraints3 = SpendConstraints::fromScalars('USD', '90.000', '100.000'); // max == capacity min

        $result3 = $pathFinder->findBestPaths($graph3, 'USD', 'EUR', $constraints3, null);
        self::assertGreaterThan(0, count($result3->paths()->toArray()), 'Should find path when requestedMax == capacityMin (inclusive)');
    }

    /**
     * @testdox SpendRange intersection logic works correctly at boundaries
     */
    public function testSpendRangeIntersectionBoundaries(): void
    {
        // Test the clamp method which is used for intersection
        $range = SpendRange::fromBounds(
            Money::fromString('USD', '100.000', 3),
            Money::fromString('USD', '200.000', 3),
        );

        // Value below range
        $below = $range->clamp(Money::fromString('USD', '50.000', 3));
        self::assertSame('100.000', $below->amount(), 'Clamp should return min when value < min');

        // Value above range
        $above = $range->clamp(Money::fromString('USD', '250.000', 3));
        self::assertSame('200.000', $above->amount(), 'Clamp should return max when value > max');

        // Value at lower boundary
        $atMin = $range->clamp(Money::fromString('USD', '100.000', 3));
        self::assertSame('100.000', $atMin->amount(), 'Clamp should return value when value == min');

        // Value at upper boundary
        $atMax = $range->clamp(Money::fromString('USD', '200.000', 3));
        self::assertSame('200.000', $atMax->amount(), 'Clamp should return value when value == max');

        // Value inside range
        $inside = $range->clamp(Money::fromString('USD', '150.000', 3));
        self::assertSame('150.000', $inside->amount(), 'Clamp should return value when min < value < max');
    }

    /**
     * @testdox Multi-hop constraint propagation narrows range correctly
     */
    public function testMultiHopConstraintPropagation(): void
    {
        // Create a 3-hop path with progressively narrower capacities
        $orderBook = new OrderBook();

        // USD -> GBP: capacity [80, 120]
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '80.000', '120.000', '0.800', 3, 3));

        // GBP -> CHF: capacity [70, 110] (will narrow the range)
        $orderBook->add(OrderFactory::buy('GBP', 'CHF', '70.000', '110.000', '1.200', 3, 3));

        // CHF -> EUR: capacity [90, 130] (will narrow further)
        $orderBook->add(OrderFactory::buy('CHF', 'EUR', '90.000', '130.000', '0.900', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        // Start with wide constraint: [50, 200]
        $constraints = SpendConstraints::fromScalars('USD', '50.000', '200.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $constraints, null);
        $paths = $result->paths()->toArray();

        // Should find path with constraints narrowed at each hop:
        // USD [50, 200] ∩ [80, 120] = [80, 120]
        // GBP [64, 96] ∩ [70, 110] = [70, 96]
        // CHF [84, 115.2] ∩ [90, 130] = [90, 115.2]
        // EUR [81, 103.68]
        self::assertGreaterThan(0, count($paths), 'Should find path through multi-hop with narrowing constraints');
    }

    /**
     * @testdox Zero-width range (min = max) propagates correctly
     */
    public function testZeroWidthRangePropagation(): void
    {
        $range = SpendRange::fromBounds(
            Money::fromString('USD', '100.000', 3),
            Money::fromString('USD', '100.000', 3),
        );

        self::assertSame('100.000', $range->min()->amount());
        self::assertSame('100.000', $range->max()->amount());

        // Clamping should preserve the single value
        $clamped = $range->clamp(Money::fromString('USD', '150.000', 3));
        self::assertSame('100.000', $clamped->amount(), 'Clamp with zero-width range should always return that value');
    }

    /**
     * @testdox Constraint with null desired amount is handled correctly
     */
    public function testConstraintWithNullDesired(): void
    {
        $constraints = SpendConstraints::fromScalars('USD', '50.000', '150.000', null);

        self::assertSame('50.000000000000000000', $constraints->min()->amount());
        self::assertSame('150.000000000000000000', $constraints->max()->amount());
        self::assertNull($constraints->desired(), 'Desired should be null when not provided');

        // PathFinder should work without desired amount
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '1.200', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $constraints, null);
        self::assertGreaterThan(0, count($result->paths()->toArray()), 'Should find paths without desired amount');
    }

    /**
     * @testdox Currency mismatch in constraint construction is rejected
     */
    public function testCurrencyMismatchRejection(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Desired spend must use the same currency');

        SpendConstraints::from(
            Money::fromString('USD', '50', 0),
            Money::fromString('USD', '150', 0),
            Money::fromString('EUR', '100', 0)  // Different currency
        );
    }

    /**
     * @testdox Negative spend constraints are rejected
     */
    public function testNegativeConstraintsRejection(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money amount cannot be negative');

        SpendConstraints::fromScalars('USD', '-50.000', '150.000');
    }

    /**
     * @testdox Scale normalization works across constraint bounds
     */
    public function testScaleNormalizationAcrossBounds(): void
    {
        // Different scales for min (2), max (3), desired (4)
        $constraints = SpendConstraints::from(
            Money::fromString('USD', '50.00', 2),
            Money::fromString('USD', '150.000', 3),
            Money::fromString('USD', '100.0000', 4),
        );

        // All should be normalized to max scale (4)
        self::assertSame(4, $constraints->min()->scale());
        self::assertSame(4, $constraints->max()->scale());
        self::assertSame(4, $constraints->desired()?->scale());

        self::assertSame('50.0000', $constraints->min()->amount());
        self::assertSame('150.0000', $constraints->max()->amount());
        self::assertSame('100.0000', $constraints->desired()?->amount());
    }
}

