<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Model;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdgeSequence;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalMath;
use SomeWork\P2PPathFinder\Tests\Helpers\Generator\PathEdgeSequenceGenerator;

use function count;

#[CoversClass(PathEdgeSequence::class)]
final class PathEdgeSequenceTest extends TestCase
{
    public function test_empty_creates_empty_sequence(): void
    {
        $sequence = PathEdgeSequence::empty();

        self::assertTrue($sequence->isEmpty());
        self::assertSame(0, $sequence->count());
        self::assertNull($sequence->first());
        self::assertNull($sequence->last());
        self::assertSame([], $sequence->toList());
    }

    public function test_from_iterable_creates_sequence(): void
    {
        $edges = $this->createValidEdgeChain(2);
        $sequence = PathEdgeSequence::fromIterable($edges);

        self::assertFalse($sequence->isEmpty());
        self::assertSame(2, $sequence->count());
        self::assertSame($edges, $sequence->toList());
    }

    public function test_from_iterable_accepts_empty_iterable(): void
    {
        $sequence = PathEdgeSequence::fromIterable([]);

        self::assertTrue($sequence->isEmpty());
        self::assertSame(0, $sequence->count());
    }

    public function test_from_iterable_accepts_generator(): void
    {
        $generator = function (): iterable {
            yield $this->createValidEdge('AAA', 'BBB');
            yield $this->createValidEdge('BBB', 'CCC');
        };

        $sequence = PathEdgeSequence::fromIterable($generator());

        self::assertFalse($sequence->isEmpty());
        self::assertSame(2, $sequence->count());
        self::assertSame('AAA', $sequence->first()?->from());
        self::assertSame('CCC', $sequence->last()?->to());
    }

    public function test_from_iterable_rejects_generator_with_non_path_edges(): void
    {
        $generator = function (): iterable {
            yield $this->createValidEdge('AAA', 'BBB');
            yield 'not-an-edge';
        };

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge sequence elements must be PathEdge instances.');

        PathEdgeSequence::fromIterable($generator());
    }

    public function test_from_iterable_rejects_generator_with_broken_chain(): void
    {
        $generator = function (): iterable {
            yield $this->createValidEdge('AAA', 'BBB');
            yield $this->createValidEdge('XXX', 'YYY'); // Doesn't connect to BBB
        };

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge sequences must form a continuous chain. Expected "BBB" to lead into "XXX".');

        PathEdgeSequence::fromIterable($generator());
    }

    public function test_from_iterable_rejects_non_path_edges(): void
    {
        $invalid = [
            $this->createValidEdge('AAA', 'BBB'),
            'not-an-edge',
        ];

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge sequence elements must be PathEdge instances.');

        PathEdgeSequence::fromIterable($invalid);
    }

    public function test_from_list_rejects_non_path_edges(): void
    {
        $invalid = [
            $this->createValidEdge('AAA', 'BBB'),
            'not-an-edge',
        ];

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge sequence elements must be PathEdge instances.');

        PathEdgeSequence::fromList($invalid);
    }

    public function test_getters_work_correctly(): void
    {
        $edges = $this->createValidEdgeChain(3);
        $sequence = PathEdgeSequence::fromList($edges);

        self::assertFalse($sequence->isEmpty());
        self::assertSame(3, $sequence->count());
        self::assertSame($edges[0], $sequence->first());
        self::assertSame($edges[2], $sequence->last());
        self::assertSame($edges, $sequence->toList());
    }

    public function test_single_edge_sequence_is_valid(): void
    {
        $edge = $this->createValidEdge('AAA', 'BBB');
        $sequence = PathEdgeSequence::fromList([$edge]);

        self::assertFalse($sequence->isEmpty());
        self::assertSame(1, $sequence->count());
        self::assertSame($edge, $sequence->first());
        self::assertSame($edge, $sequence->last());
        self::assertSame([$edge], $sequence->toList());
    }

    public function test_append_to_single_edge_sequence(): void
    {
        $edge1 = $this->createValidEdge('AAA', 'BBB');
        $sequence = PathEdgeSequence::fromList([$edge1]);

        $edge2 = $this->createValidEdge('BBB', 'CCC');
        $appended = $sequence->append($edge2);

        self::assertSame(1, $sequence->count()); // Original unchanged
        self::assertSame(2, $appended->count()); // New has 2 edges

        $expectedEdges = [$edge1, $edge2];
        self::assertSame($expectedEdges, $appended->toList());
    }

    public function test_at_and_has_work_correctly(): void
    {
        $edges = $this->createValidEdgeChain(3);
        $sequence = PathEdgeSequence::fromList($edges);

        self::assertTrue($sequence->has(0));
        self::assertTrue($sequence->has(1));
        self::assertTrue($sequence->has(2));
        self::assertFalse($sequence->has(3));
        self::assertFalse($sequence->has(-1));

        self::assertSame($edges[0], $sequence->at(0));
        self::assertSame($edges[1], $sequence->at(1));
        self::assertSame($edges[2], $sequence->at(2));
    }

    public function test_at_throws_for_invalid_index(): void
    {
        $sequence = PathEdgeSequence::empty();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Undefined path edge at offset 0.');

        $sequence->at(0);
    }

    public function test_at_handles_very_large_index(): void
    {
        $sequence = PathEdgeSequence::fromList($this->createValidEdgeChain(3));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Undefined path edge at offset 999999.');

        $sequence->at(999999);
    }

    public function test_at_handles_negative_index(): void
    {
        $sequence = PathEdgeSequence::fromList($this->createValidEdgeChain(3));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Undefined path edge at offset -1.');

        $sequence->at(-1);
    }

    public function test_has_handles_edge_cases(): void
    {
        $sequence = PathEdgeSequence::fromList($this->createValidEdgeChain(3));

        self::assertFalse($sequence->has(-1));
        self::assertTrue($sequence->has(0));
        self::assertTrue($sequence->has(1));
        self::assertTrue($sequence->has(2));
        self::assertFalse($sequence->has(3));
        self::assertFalse($sequence->has(999999));
    }

    public function test_iterator_works_correctly(): void
    {
        $edges = $this->createValidEdgeChain(3);
        $sequence = PathEdgeSequence::fromList($edges);

        $iterated = [];
        foreach ($sequence as $index => $edge) {
            $iterated[$index] = $edge;
        }

        self::assertSame($edges, $iterated);
    }

    public function test_to_array_converts_correctly(): void
    {
        $edges = $this->createValidEdgeChain(2);
        $sequence = PathEdgeSequence::fromList($edges);

        $array = $sequence->toArray();

        self::assertIsArray($array);
        self::assertCount(2, $array);
        self::assertSame($edges[0]->toArray(), $array[0]);
        self::assertSame($edges[1]->toArray(), $array[1]);
    }

    public function test_append_to_empty_sequence(): void
    {
        $edge = $this->createValidEdge('AAA', 'BBB');
        $sequence = PathEdgeSequence::empty()->append($edge);

        self::assertFalse($sequence->isEmpty());
        self::assertSame(1, $sequence->count());
        self::assertSame($edge, $sequence->first());
        self::assertSame($edge, $sequence->last());
        self::assertSame([$edge], $sequence->toList());
    }

    public function test_append_to_existing_sequence(): void
    {
        $initialEdges = $this->createValidEdgeChain(2); // AAA->BBB, BBB->CCC
        $sequence = PathEdgeSequence::fromList($initialEdges);

        $newEdge = $this->createValidEdge('CCC', 'DDD');
        $appended = $sequence->append($newEdge);

        self::assertSame(2, $sequence->count()); // Original unchanged
        self::assertSame(3, $appended->count()); // New has 3 edges

        $expectedEdges = [...$initialEdges, $newEdge];
        self::assertSame($expectedEdges, $appended->toList());
    }

    public function test_append_rejects_broken_chain(): void
    {
        $edge = $this->createValidEdge('AAA', 'BBB');
        $sequence = PathEdgeSequence::fromList([$edge]);

        $brokenEdge = $this->createValidEdge('XXX', 'YYY'); // Doesn't connect to BBB

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge sequences must form a continuous chain. Expected "BBB" to lead into "XXX".');

        $sequence->append($brokenEdge);
    }

    public function test_append_rejects_edge_with_wrong_order_side(): void
    {
        $sequence = PathEdgeSequence::empty();
        $order = OrderFactory::buy('BBB', 'CCC', '1.000', '1.000', '1.000', 3, 3);

        $invalidEdge = PathEdge::create(
            'BBB',
            'CCC',
            $order,
            $order->effectiveRate(),
            OrderSide::SELL, // Wrong order side
            DecimalMath::decimal('1.000000000000000000', 18),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge order side must match the underlying order.');

        $sequence->append($invalidEdge);
    }

    public function test_append_rejects_edge_with_wrong_exchange_rate_currencies(): void
    {
        $sequence = PathEdgeSequence::empty();
        $order = OrderFactory::buy('BBB', 'CCC', '1.000', '1.000', '1.000', 3, 3);

        $invalidEdge = PathEdge::create(
            'BBB',
            'CCC',
            $order,
            ExchangeRate::fromString('CCC', 'BBB', '1.000', 3), // Wrong order
            OrderSide::BUY,
            DecimalMath::decimal('1.000000000000000000', 18),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge exchange rate currencies must match the order asset pair.');

        $sequence->append($invalidEdge);
    }

    public function test_append_rejects_buy_edge_with_wrong_endpoints(): void
    {
        $sequence = PathEdgeSequence::empty();
        $order = OrderFactory::buy('BBB', 'CCC', '1.000', '1.000', '1.000', 3, 3);

        $invalidEdge = PathEdge::create(
            'CCC', // Wrong: should be BBB
            'BBB', // Wrong: should be CCC
            $order,
            $order->effectiveRate(),
            OrderSide::BUY,
            DecimalMath::decimal('1.000000000000000000', 18),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge endpoints must align with the underlying order asset pair and side.');

        $sequence->append($invalidEdge);
    }

    public function test_append_rejects_sell_edge_with_wrong_endpoints(): void
    {
        $sequence = PathEdgeSequence::empty();
        $order = OrderFactory::sell('BBB', 'CCC', '1.000', '1.000', '1.000', 3, 3);

        $invalidEdge = PathEdge::create(
            'BBB', // Wrong: should be CCC (quote currency for SELL)
            'CCC', // Wrong: should be BBB (base currency for SELL)
            $order,
            $order->effectiveRate(),
            OrderSide::SELL,
            DecimalMath::decimal('1.000000000000000000', 18),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge endpoints must align with the underlying order asset pair and side.');

        $sequence->append($invalidEdge);
    }

    public function test_immutability_append_returns_new_instance(): void
    {
        $original = PathEdgeSequence::empty();
        $edge1 = $this->createValidEdge('AAA', 'BBB');
        $edge2 = $this->createValidEdge('BBB', 'CCC');

        $appended1 = $original->append($edge1);
        $appended2 = $appended1->append($edge2);

        self::assertTrue($original->isEmpty());
        self::assertSame(1, $appended1->count());
        self::assertSame(2, $appended2->count());

        self::assertNotSame($original, $appended1);
        self::assertNotSame($original, $appended2);
        self::assertNotSame($appended1, $appended2);
    }

    public function test_immutability_factory_methods(): void
    {
        $edges1 = $this->createValidEdgeChain(2);
        $edges2 = $this->createValidEdgeChain(3);

        $sequence1 = PathEdgeSequence::fromList($edges1);
        $sequence2 = PathEdgeSequence::fromList($edges2);

        self::assertNotSame($sequence1, $sequence2);
        self::assertSame(2, $sequence1->count());
        self::assertSame(3, $sequence2->count());
    }

    public function test_it_rejects_edges_with_mismatched_exchange_rate(): void
    {
        $order = OrderFactory::buy('AAA', 'BBB', '1.000', '1.000', '1.000', 3, 3);

        $edge = PathEdge::create(
            'AAA',
            'BBB',
            $order,
            ExchangeRate::fromString('BBB', 'AAA', '1.000', 3), // Wrong order
            OrderSide::BUY,
            DecimalMath::decimal('1.000000000000000000', 18),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge exchange rate currencies must match the order asset pair.');

        PathEdgeSequence::fromList([$edge]);
    }

    public function test_it_rejects_edges_with_wrong_order_side(): void
    {
        $order = OrderFactory::buy('AAA', 'BBB', '1.000', '1.000', '1.000', 3, 3);

        $edge = PathEdge::create(
            'AAA',
            'BBB',
            $order,
            $order->effectiveRate(),
            OrderSide::SELL, // Wrong order side (order is BUY)
            DecimalMath::decimal('1.000000000000000000', 18),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge order side must match the underlying order.');

        PathEdgeSequence::fromList([$edge]);
    }

    public function test_it_rejects_buy_edges_with_wrong_from_currency(): void
    {
        $order = OrderFactory::buy('AAA', 'BBB', '1.000', '1.000', '1.000', 3, 3);

        $edge = PathEdge::create(
            'BBB', // Wrong: should be AAA (base currency)
            'BBB',
            $order,
            $order->effectiveRate(),
            OrderSide::BUY,
            DecimalMath::decimal('1.000000000000000000', 18),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge endpoints must align with the underlying order asset pair and side.');

        PathEdgeSequence::fromList([$edge]);
    }

    public function test_it_rejects_buy_edges_with_wrong_to_currency(): void
    {
        $order = OrderFactory::buy('AAA', 'BBB', '1.000', '1.000', '1.000', 3, 3);

        $edge = PathEdge::create(
            'AAA',
            'AAA', // Wrong: should be BBB (quote currency)
            $order,
            $order->effectiveRate(),
            OrderSide::BUY,
            DecimalMath::decimal('1.000000000000000000', 18),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge endpoints must align with the underlying order asset pair and side.');

        PathEdgeSequence::fromList([$edge]);
    }

    public function test_it_rejects_sell_edges_with_wrong_from_currency(): void
    {
        $order = OrderFactory::sell('AAA', 'BBB', '1.000', '1.000', '1.000', 3, 3);

        $edge = PathEdge::create(
            'AAA', // Wrong: should be BBB (quote currency for SELL)
            'AAA',
            $order,
            $order->effectiveRate(),
            OrderSide::SELL,
            DecimalMath::decimal('1.000000000000000000', 18),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge endpoints must align with the underlying order asset pair and side.');

        PathEdgeSequence::fromList([$edge]);
    }

    public function test_it_rejects_sell_edges_with_wrong_to_currency(): void
    {
        $order = OrderFactory::sell('AAA', 'BBB', '1.000', '1.000', '1.000', 3, 3);

        $edge = PathEdge::create(
            'BBB',
            'BBB', // Wrong: should be AAA (base currency for SELL)
            $order,
            $order->effectiveRate(),
            OrderSide::SELL,
            DecimalMath::decimal('1.000000000000000000', 18),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge endpoints must align with the underlying order asset pair and side.');

        PathEdgeSequence::fromList([$edge]);
    }

    private function createValidEdge(string $from, string $to): PathEdge
    {
        $order = OrderFactory::buy($from, $to, '1.000', '1.000', '1.000', 3, 3);

        return PathEdge::create(
            $from,
            $to,
            $order,
            $order->effectiveRate(),
            OrderSide::BUY,
            DecimalMath::decimal('1.000000000000000000', 18),
        );
    }

    private function createValidEdgeChain(int $length): array
    {
        $currencies = ['AAA', 'BBB', 'CCC', 'DDD', 'EEE', 'FFF'];
        $edges = [];

        for ($i = 0; $i < $length; ++$i) {
            $from = $currencies[$i];
            $to = $currencies[$i + 1];
            $edges[] = $this->createValidEdge($from, $to);
        }

        return $edges;
    }

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
