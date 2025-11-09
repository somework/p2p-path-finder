<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Search;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStatePriority;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class SearchStatePriorityTest extends TestCase
{
    public function test_it_exposes_components(): void
    {
        $cost = new PathCost('0.100000000000000000');
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

        new SearchStatePriority(new PathCost('0.1'), -1, RouteSignature::fromNodes([]), 0);
    }

    public function test_constructor_rejects_negative_insertion_order(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Queue priorities require a non-negative insertion order.');

        new SearchStatePriority(new PathCost('0.1'), 0, RouteSignature::fromNodes([]), -5);
    }

    public function test_compare_applies_tie_breakers(): void
    {
        $cheap = new SearchStatePriority(new PathCost('0.100000000000000000'), 2, RouteSignature::fromNodes(['SRC', 'A']), 3);
        $expensive = new SearchStatePriority(new PathCost('0.200000000000000000'), 2, RouteSignature::fromNodes(['SRC', 'A']), 4);
        $moreHops = new SearchStatePriority(new PathCost('0.100000000000000000'), 5, RouteSignature::fromNodes(['SRC', 'A']), 6);
        $lexicographicallyLater = new SearchStatePriority(new PathCost('0.100000000000000000'), 2, RouteSignature::fromNodes(['SRC', 'Z']), 7);
        $laterInsertion = new SearchStatePriority(new PathCost('0.100000000000000000'), 2, RouteSignature::fromNodes(['SRC', 'A']), 8);

        self::assertSame(1, $cheap->compare($expensive, 18));
        self::assertSame(1, $cheap->compare($moreHops, 18));
        self::assertSame(1, $cheap->compare($lexicographicallyLater, 18));
        self::assertSame(1, $cheap->compare($laterInsertion, 18));
    }

    public function test_compare_rejects_negative_scale(): void
    {
        $priority = new SearchStatePriority(new PathCost('0.1'), 0, RouteSignature::fromNodes([]), 0);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Scale cannot be negative.');

        $priority->compare(new SearchStatePriority(new PathCost('0.1'), 0, RouteSignature::fromNodes([]), 1), -1);
    }
}
