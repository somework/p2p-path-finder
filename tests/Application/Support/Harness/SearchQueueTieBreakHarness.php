<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Support\Harness;

use ReflectionMethod;
use ReflectionProperty;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchBootstrap;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchQueueEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchState;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStatePriority;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStatePriorityQueue;
use SomeWork\P2PPathFinder\Application\PathFinder\SearchStateQueue;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
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
    public static function extractOrdering(PathFinder $finder): array
    {
        $bootstrap = self::initializeSearch($finder);
        $queue = $bootstrap->queue();
        $insertionOrder = $bootstrap->insertionOrder();

        // Drop the bootstrap state so only seeded candidates affect the ordering under test.
        self::extractQueueEntry($queue);

        $tieCost = BcMath::normalize('0.5', self::SCALE);

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
                        new PathCost($state->cost()),
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

    private static function initializeSearch(PathFinder $finder): SearchBootstrap
    {
        $reflection = new ReflectionMethod(PathFinder::class, 'initializeSearchStructures');
        $reflection->setAccessible(true);

        /** @var SearchBootstrap $bootstrap */
        $bootstrap = $reflection->invoke($finder, 'SRC', null, null);

        return $bootstrap;
    }

    /**
     * @param non-empty-list<non-empty-string> $nodes
     */
    private static function buildStateForRoute(array $nodes, string $cost): SearchState
    {
        $unit = BcMath::normalize('1', self::SCALE);
        $normalizedCost = BcMath::normalize($cost, self::SCALE);

        $state = SearchState::bootstrap($nodes[0], $unit, null, null);

        $count = count($nodes);
        for ($index = 1; $index < $count; ++$index) {
            $from = $nodes[$index - 1];
            $to = $nodes[$index];
            $edge = self::buildPathEdge($from, $to);

            $state = $state->transition($to, $normalizedCost, $normalizedCost, $edge, null, null);
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
            BcMath::normalize('1.000000000000000000', self::SCALE),
        );
    }

    private static function routeSignature(PathFinder $finder, PathEdgeSequence $path): RouteSignature
    {
        $reflection = new ReflectionMethod(PathFinder::class, 'routeSignature');
        $reflection->setAccessible(true);

        /** @var RouteSignature $signature */
        $signature = $reflection->invoke($finder, $path);

        return $signature;
    }

    private static function extractQueueEntry(SearchStateQueue $queue): SearchQueueEntry
    {
        $reflection = new ReflectionProperty(SearchStateQueue::class, 'queue');
        $reflection->setAccessible(true);

        /** @var SearchStatePriorityQueue $priorityQueue */
        $priorityQueue = $reflection->getValue($queue);

        /** @var SearchQueueEntry $entry */
        $entry = $priorityQueue->extract();

        return $entry;
    }
}
