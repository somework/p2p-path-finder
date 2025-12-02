<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Helpers;

use Brick\Math\BigDecimal;
use ReflectionMethod;
use ReflectionProperty;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\PathSearchEngine;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Queue\StatePriorityQueue;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchBootstrap;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchQueueEntry;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchState;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchStatePriority;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchStatePriorityQueue;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdgeSequence;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function count;

/**
 * Utility harness asserting the deterministic ordering of search queue tie-breakers.
 */
final class SearchQueueTieBreakHarness
{
    private const SCALE = 18;

    /**
     * @return list<array{signature: string, hops: int}>
     */
    public static function extractOrdering(PathSearchEngine $finder): array
    {
        $bootstrap = self::initializeSearch($finder);
        $queue = $bootstrap->queue();
        $insertionOrder = $bootstrap->insertionOrder();

        // Drop the bootstrap state so only seeded candidates affect the ordering under test.
        self::extractQueueEntry($queue);

        $tieCost = DecimalFactory::decimal('0.5', self::SCALE);

        $states = [
            self::buildStateForRoute(['SRC', 'ALPHA', 'OMEGA'], $tieCost),
            self::buildStateForRoute(['SRC', 'BETA', 'OMEGA'], $tieCost),
            self::buildStateForRoute(['SRC', 'ALPHA', 'MID', 'OMEGA'], $tieCost),
            // Intentionally repeat the alpha route to verify discovery-order (FIFO) tie-breaking
            // when cost, hops, and signature are identical.
            self::buildStateForRoute(['SRC', 'ALPHA', 'OMEGA'], $tieCost),
        ];

        foreach ($states as $state) {
            $signature = self::routeSignature($finder, $state->path());

            $queue->push(
                new SearchQueueEntry(
                    $state,
                    new SearchStatePriority(
                        new PathCost($state->costDecimal()),
                        $state->hops(),
                        $signature,
                        $insertionOrder->next(),
                    ),
                ),
            );
        }

        $extracted = [];
        while (!$queue->isEmpty()) {
            $entry = self::extractQueueEntry($queue);
            $state = $entry->state();
            $signature = self::routeSignature($finder, $state->path());

            $extracted[] = [
                'signature' => (string) $signature,
                'hops' => $state->hops(),
            ];
        }

        return $extracted;
    }

    private static function initializeSearch(PathSearchEngine $finder): SearchBootstrap
    {
        $reflection = new ReflectionMethod(PathSearchEngine::class, 'initializeSearchStructures');
        $reflection->setAccessible(true);

        /** @var SearchBootstrap $bootstrap */
        $bootstrap = $reflection->invoke($finder, 'SRC', null, null);

        return $bootstrap;
    }

    /**
     * @param non-empty-list<non-empty-string> $nodes
     */
    private static function buildStateForRoute(array $nodes, BigDecimal $cost): SearchState
    {
        $unit = DecimalFactory::decimal('1', self::SCALE);

        $state = SearchState::bootstrap($nodes[0], $unit, null, null);

        $count = count($nodes);
        for ($index = 1; $index < $count; ++$index) {
            $from = $nodes[$index - 1];
            $to = $nodes[$index];
            $edge = self::buildPathEdge($from, $to);

            $state = $state->transition($to, $cost, $cost, $edge, null, null);
        }

        return $state;
    }

    private static function buildPathEdge(string $from, string $to): PathEdge
    {
        $order = OrderFactory::buy($from, $to, '1.000', '1.000', '1.000', 3, 3);

        return PathEdge::create(
            $from,
            $to,
            $order,
            $order->effectiveRate(),
            OrderSide::BUY,
            DecimalFactory::decimal('1.000000000000000000', self::SCALE),
        );
    }

    private static function routeSignature(PathSearchEngine $finder, PathEdgeSequence $path): RouteSignature
    {
        $reflection = new ReflectionMethod(PathSearchEngine::class, 'routeSignature');
        $reflection->setAccessible(true);

        /** @var RouteSignature $signature */
        $signature = $reflection->invoke($finder, $path);

        return $signature;
    }

    private static function extractQueueEntry(StatePriorityQueue $queue): SearchQueueEntry
    {
        $reflection = new ReflectionProperty(StatePriorityQueue::class, 'queue');
        $reflection->setAccessible(true);

        /** @var SearchStatePriorityQueue $priorityQueue */
        $priorityQueue = $reflection->getValue($queue);

        /** @var SearchQueueEntry $entry */
        $entry = $priorityQueue->extract();

        return $entry;
    }
}
