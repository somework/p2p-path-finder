<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\SearchStateQueue;

final class SearchStateQueueTest extends TestCase
{
    public function test_insert_accepts_prepackaged_entries(): void
    {
        $queue = new SearchStateQueue(18);

        $stateA = ['node' => 'A'];
        $prepackaged = [
            'state' => $stateA,
            'priority' => ['cost' => '0.100000000000000000', 'order' => 1],
        ];

        $queue->insert($stateA, $prepackaged);

        $stateB = ['node' => 'B'];
        $queue->insert($stateB, ['cost' => '0.200000000000000000', 'order' => 2]);

        self::assertSame($stateA, $queue->extract());
        self::assertSame($stateB, $queue->extract());
    }

    public function test_compare_prefers_lower_cost_and_follows_fifo_on_ties(): void
    {
        $queue = new SearchStateQueue(18);

        $lowerCost = [
            'state' => ['node' => 'L'],
            'priority' => ['cost' => '0.010000000000000000', 'order' => 0],
        ];
        $higherCost = [
            'state' => ['node' => 'H'],
            'priority' => ['cost' => '0.020000000000000000', 'order' => 1],
        ];

        self::assertSame(1, $queue->compare($lowerCost, $higherCost));
        self::assertSame(-1, $queue->compare($higherCost, $lowerCost));

        $earlier = [
            'state' => ['node' => 'E'],
            'priority' => ['cost' => '0.030000000000000000', 'order' => 1],
        ];
        $later = [
            'state' => ['node' => 'L'],
            'priority' => ['cost' => '0.030000000000000000', 'order' => 0],
        ];

        self::assertSame(-1, $queue->compare($earlier, $later));
        self::assertSame(1, $queue->compare($later, $earlier));
    }
}
