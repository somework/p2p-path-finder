<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;

final class PathFinderConstructorTest extends TestCase
{
    public function test_it_requires_maximum_hops_to_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum hops must be at least one.');

        new PathFinder(0);
    }

    public function test_it_requires_result_limit_to_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Result limit must be at least one.');

        new PathFinder(maxHops: 2, tolerance: 0.0, topK: 0);
    }

    public function test_it_requires_maximum_expansions_to_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum expansions must be at least one.');

        new PathFinder(maxHops: 2, tolerance: 0.0, topK: 1, maxExpansions: 0);
    }

    public function test_it_requires_maximum_visited_states_to_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum visited states must be at least one.');

        new PathFinder(maxHops: 2, tolerance: 0.0, topK: 1, maxExpansions: 10, maxVisitedStates: 0);
    }
}
