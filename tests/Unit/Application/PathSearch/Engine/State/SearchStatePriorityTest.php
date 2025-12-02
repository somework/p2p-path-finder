<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine\State;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchStatePriority;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalFactory;

use const PHP_INT_MAX;

#[CoversClass(SearchStatePriority::class)]
final class SearchStatePriorityTest extends TestCase
{
    public function test_it_exposes_components(): void
    {
        $cost = new PathCost(DecimalFactory::decimal('0.100000000000000000'));
        $signature = RouteSignature::fromNodes(['SRC', 'DST']);

        $priority = new SearchStatePriority($cost, 2, $signature, 5);

        self::assertSame($cost, $priority->cost());
        self::assertSame(2, $priority->hops());
        self::assertSame($signature, $priority->routeSignature());
        self::assertSame(5, $priority->order());
    }

    public function test_constructor_rejects_negative_hops(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Queue priorities require a non-negative hop count.');

        new SearchStatePriority(new PathCost(DecimalFactory::decimal('0.1')), -1, RouteSignature::fromNodes([]), 0);
    }

    public function test_constructor_rejects_negative_insertion_order(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Queue priorities require a non-negative insertion order.');

        new SearchStatePriority(new PathCost(DecimalFactory::decimal('0.1')), 0, RouteSignature::fromNodes([]), -5);
    }

    public function test_constructor_accepts_boundary_values(): void
    {
        // Test with maximum reasonable values for hops and order
        $cost = new PathCost(DecimalFactory::decimal('1.0'));
        $signature = RouteSignature::fromNodes(['A', 'B']);

        $priority = new SearchStatePriority($cost, PHP_INT_MAX, $signature, PHP_INT_MAX);

        self::assertSame(PHP_INT_MAX, $priority->hops());
        self::assertSame(PHP_INT_MAX, $priority->order());
    }

    public function test_compare_applies_tie_breakers(): void
    {
        $cheap = new SearchStatePriority(
            new PathCost(DecimalFactory::decimal('0.100000000000000000')),
            2,
            RouteSignature::fromNodes(['SRC', 'A']),
            3
        );
        $expensive = new SearchStatePriority(
            new PathCost(DecimalFactory::decimal('0.200000000000000000')),
            2,
            RouteSignature::fromNodes(['SRC', 'A']),
            4
        );
        $moreHops = new SearchStatePriority(
            new PathCost(DecimalFactory::decimal('0.100000000000000000')),
            5,
            RouteSignature::fromNodes(['SRC', 'A']),
            6
        );
        $lexicographicallyLater = new SearchStatePriority(
            new PathCost(DecimalFactory::decimal('0.100000000000000000')),
            2,
            RouteSignature::fromNodes(['SRC', 'Z']),
            7
        );
        $laterInsertion = new SearchStatePriority(
            new PathCost(DecimalFactory::decimal('0.100000000000000000')),
            2,
            RouteSignature::fromNodes(['SRC', 'A']),
            8
        );

        self::assertSame(1, $cheap->compare($expensive, 18));
        self::assertSame(1, $cheap->compare($moreHops, 18));
        self::assertSame(1, $cheap->compare($lexicographicallyLater, 18));
        self::assertSame(1, $cheap->compare($laterInsertion, 18));
    }

    public function test_compare_rejects_negative_scale(): void
    {
        $priority = new SearchStatePriority(
            new PathCost(DecimalFactory::decimal('0.1')),
            0,
            RouteSignature::fromNodes([]),
            0
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Scale must be a non-negative integer.');

        $priority->compare(
            new SearchStatePriority(new PathCost(DecimalFactory::decimal('0.1')), 0, RouteSignature::fromNodes([]), 1),
            -1
        );
    }

    public function test_compare_returns_zero_for_identical_priorities(): void
    {
        $cost = new PathCost(DecimalFactory::decimal('0.123456789012345678'));
        $signature = RouteSignature::fromNodes(['SRC', 'MID', 'DST']);

        $priority1 = new SearchStatePriority($cost, 3, $signature, 42);
        $priority2 = new SearchStatePriority($cost, 3, $signature, 42);

        self::assertSame(0, $priority1->compare($priority2, 18));
    }

    public function test_compare_scale_affects_cost_comparison(): void
    {
        // Costs that differ only in lower decimal places
        $cost1 = new PathCost(DecimalFactory::decimal('1.123456789012345678')); // More precise
        $cost2 = new PathCost(DecimalFactory::decimal('1.123456789012345679')); // Slightly higher

        $signature = RouteSignature::fromNodes(['A', 'B']);
        $priority1 = new SearchStatePriority($cost1, 1, $signature, 1);
        $priority2 = new SearchStatePriority($cost2, 1, $signature, 1);

        // At full precision (18), they should be different
        self::assertSame(1, $priority1->compare($priority2, 18)); // priority1 has lower cost, higher priority

        // At lower precision (0), they should be equal (rounded to 1 vs 1)
        self::assertSame(0, $priority1->compare($priority2, 0));

        // At precision 17, they should be equal due to rounding (both round to same value)
        self::assertSame(0, $priority1->compare($priority2, 17));

        // At maximum scale, they should be different
        self::assertSame(1, $priority1->compare($priority2, 50));
    }

    public function test_compare_with_empty_route_signatures(): void
    {
        $cost = new PathCost(DecimalFactory::decimal('1.0'));

        $emptySignature = RouteSignature::fromNodes([]);
        $nonEmptySignature = RouteSignature::fromNodes(['A']);

        $priorityWithEmpty = new SearchStatePriority($cost, 1, $emptySignature, 1);
        $priorityWithNonEmpty = new SearchStatePriority($cost, 1, $nonEmptySignature, 1);

        // Empty signature should compare as "less than" non-empty (lexicographically)
        // Empty string "" vs "A" -> "" < "A", so empty has higher priority
        self::assertSame(1, $priorityWithEmpty->compare($priorityWithNonEmpty, 18));
        self::assertSame(-1, $priorityWithNonEmpty->compare($priorityWithEmpty, 18));
    }

    public function test_compare_with_single_node_signatures(): void
    {
        $cost = new PathCost(DecimalFactory::decimal('1.0'));

        $signatureA = RouteSignature::fromNodes(['A']);
        $signatureB = RouteSignature::fromNodes(['B']);
        $signatureZ = RouteSignature::fromNodes(['Z']);

        $priorityA = new SearchStatePriority($cost, 1, $signatureA, 1);
        $priorityB = new SearchStatePriority($cost, 1, $signatureB, 1);
        $priorityZ = new SearchStatePriority($cost, 1, $signatureZ, 1);

        // Test lexicographic ordering: A < B < Z
        self::assertSame(1, $priorityA->compare($priorityB, 18)); // A has higher priority than B
        self::assertSame(1, $priorityA->compare($priorityZ, 18)); // A has higher priority than Z
        self::assertSame(1, $priorityB->compare($priorityZ, 18)); // B has higher priority than Z

        self::assertSame(-1, $priorityZ->compare($priorityA, 18)); // Z has lower priority than A
    }

    public function test_compare_with_different_length_signatures(): void
    {
        $cost = new PathCost(DecimalFactory::decimal('1.0'));

        $shortSignature = RouteSignature::fromNodes(['A', 'B']); // "A->B"
        $longSignature = RouteSignature::fromNodes(['A', 'B', 'C']); // "A->B->C"

        $priorityShort = new SearchStatePriority($cost, 1, $shortSignature, 1);
        $priorityLong = new SearchStatePriority($cost, 1, $longSignature, 1);

        // "A->B" vs "A->B->C" -> "A->B" < "A->B->C" lexicographically
        // So shorter signature has higher priority
        self::assertSame(1, $priorityShort->compare($priorityLong, 18));
        self::assertSame(-1, $priorityLong->compare($priorityShort, 18));
    }

    public function test_compare_rejects_scale_exceeding_maximum(): void
    {
        $priority = new SearchStatePriority(
            new PathCost(DecimalFactory::decimal('0.1')),
            0,
            RouteSignature::fromNodes([]),
            0
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Scale cannot exceed 50 decimal places.');

        $priority->compare(
            new SearchStatePriority(new PathCost(DecimalFactory::decimal('0.1')), 0, RouteSignature::fromNodes([]), 1),
            51
        );
    }
}
