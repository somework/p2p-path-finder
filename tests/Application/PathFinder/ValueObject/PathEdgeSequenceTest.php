<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\ValueObject;

use Generator;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class PathEdgeSequenceTest extends TestCase
{
    private const SCALE = 18;

    public function test_it_creates_sequence_from_iterable(): void
    {
        $firstOrder = OrderFactory::buy('AAA', 'BBB', '1.000', '2.000', '1.000', 3, 3);
        $secondOrder = OrderFactory::sell('CCC', 'BBB', '1.000', '2.000', '1.000', 3, 3);

        $edges = (static function () use ($firstOrder, $secondOrder): Generator {
            yield PathEdge::create(
                'AAA',
                'BBB',
                $firstOrder,
                $firstOrder->effectiveRate(),
                OrderSide::BUY,
                BcMath::normalize('1.100000000000000000', self::SCALE),
            );

            yield PathEdge::create(
                'BBB',
                'CCC',
                $secondOrder,
                $secondOrder->effectiveRate(),
                OrderSide::SELL,
                BcMath::normalize('0.900000000000000000', self::SCALE),
            );
        })();

        $sequence = PathEdgeSequence::fromIterable($edges);

        self::assertSame(2, $sequence->count());
        self::assertSame('AAA', $sequence->first()?->from());
        self::assertSame('CCC', $sequence->last()?->to());
    }

    public function test_from_iterable_rejects_non_path_edges(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge sequence elements must be PathEdge instances.');

        PathEdgeSequence::fromIterable((static function (): Generator {
            yield 'not-an-edge';
        })());
    }

    public function test_from_iterable_rejects_broken_chain(): void
    {
        $firstOrder = OrderFactory::buy('AAA', 'BBB', '1.000', '2.000', '1.000', 3, 3);
        $secondOrder = OrderFactory::buy('CCC', 'DDD', '1.000', '2.000', '1.000', 3, 3);

        $edges = (static function () use ($firstOrder, $secondOrder): Generator {
            yield PathEdge::create(
                'AAA',
                'BBB',
                $firstOrder,
                $firstOrder->effectiveRate(),
                OrderSide::BUY,
                BcMath::normalize('1.000000000000000000', self::SCALE),
            );

            yield PathEdge::create(
                'CCC',
                'DDD',
                $secondOrder,
                $secondOrder->effectiveRate(),
                OrderSide::BUY,
                BcMath::normalize('1.000000000000000000', self::SCALE),
            );
        })();

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessageMatches('/^Path edge sequences must form a continuous chain\./');

        PathEdgeSequence::fromIterable($edges);
    }

    public function test_append_rejects_broken_chain(): void
    {
        $firstOrder = OrderFactory::buy('AAA', 'BBB', '1.000', '2.000', '1.000', 3, 3);
        $secondOrder = OrderFactory::buy('CCC', 'DDD', '1.000', '2.000', '1.000', 3, 3);

        $sequence = PathEdgeSequence::fromList([
            PathEdge::create(
                'AAA',
                'BBB',
                $firstOrder,
                $firstOrder->effectiveRate(),
                OrderSide::BUY,
                BcMath::normalize('1.000000000000000000', self::SCALE),
            ),
        ]);

        $invalidEdge = PathEdge::create(
            'CCC',
            'DDD',
            $secondOrder,
            $secondOrder->effectiveRate(),
            OrderSide::BUY,
            BcMath::normalize('1.000000000000000000', self::SCALE),
        );

        $this->expectException(InvalidInput::class);

        $sequence->append($invalidEdge);
    }

    public function test_append_returns_new_sequence_with_edge(): void
    {
        $firstOrder = OrderFactory::buy('AAA', 'BBB', '1.000', '2.000', '1.000', 3, 3);
        $secondOrder = OrderFactory::sell('CCC', 'BBB', '1.000', '2.000', '1.000', 3, 3);

        $original = PathEdgeSequence::fromList([
            PathEdge::create(
                'AAA',
                'BBB',
                $firstOrder,
                $firstOrder->effectiveRate(),
                OrderSide::BUY,
                BcMath::normalize('1.100000000000000000', self::SCALE),
            ),
        ]);

        $appended = $original->append(
            PathEdge::create(
                'BBB',
                'CCC',
                $secondOrder,
                $secondOrder->effectiveRate(),
                OrderSide::SELL,
                BcMath::normalize('0.900000000000000000', self::SCALE),
            ),
        );

        self::assertSame(1, $original->count());
        self::assertSame(2, $appended->count());
        self::assertSame('AAA', $appended->first()?->from());
        self::assertSame('CCC', $appended->last()?->to());
    }

    public function test_it_rejects_edges_with_mismatched_exchange_rate(): void
    {
        $order = OrderFactory::buy('AAA', 'BBB', '1.000', '1.000', '1.000', 3, 3);

        $edge = PathEdge::create(
            'AAA',
            'BBB',
            $order,
            ExchangeRate::fromString('BBB', 'AAA', '1.000', 3),
            OrderSide::BUY,
            BcMath::normalize('1.000000000000000000', self::SCALE),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge exchange rate currencies must match the order asset pair.');

        PathEdgeSequence::fromList([$edge]);
    }
}
