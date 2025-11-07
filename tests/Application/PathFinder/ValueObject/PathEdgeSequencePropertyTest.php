<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\ValueObject;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Tests\Application\Support\Generator\PathEdgeSequenceGenerator;

use function count;

/**
 * Property-based tests for {@see PathEdgeSequence} adjacency guarantees.
 */
final class PathEdgeSequencePropertyTest extends TestCase
{
    /**
     * @return iterable<string, array{PathEdgeSequence, list<PathEdge>}>
     */
    public static function provideRandomSequences(): iterable
    {
        for ($seed = 0; $seed < 64; ++$seed) {
            $generator = new PathEdgeSequenceGenerator(new Randomizer(new Mt19937($seed)));
            $edges = $generator->edges(8);

            yield 'seed-'.$seed => [PathEdgeSequence::fromList($edges), $edges];
        }
    }

    /**
     * @dataProvider provideRandomSequences
     *
     * @param list<PathEdge> $edges
     */
    public function test_sequences_form_continuous_chains(PathEdgeSequence $sequence, array $edges): void
    {
        self::assertSame($edges, $sequence->toList());
        self::assertSame(count($edges), $sequence->count());

        $rebuilt = PathEdgeSequence::empty();
        foreach ($edges as $edge) {
            $rebuilt = $rebuilt->append($edge);
        }

        self::assertSame($edges, $rebuilt->toList());

        $previous = null;
        foreach ($edges as $edge) {
            if ($previous instanceof PathEdge) {
                self::assertSame(
                    $previous->to(),
                    $edge->from(),
                    'Path edges must share endpoints between hops.',
                );
            }

            $order = $edge->order();
            $pair = $order->assetPair();

            if (OrderSide::BUY === $edge->orderSide()) {
                self::assertSame($pair->base(), $edge->from());
                self::assertSame($pair->quote(), $edge->to());
            } else {
                self::assertSame($pair->quote(), $edge->from());
                self::assertSame($pair->base(), $edge->to());
            }

            $previous = $edge;
        }
    }
}
