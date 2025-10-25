<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\ValueObject;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class CandidatePathTest extends TestCase
{
    private const SCALE = 18;

    public function test_it_rejects_mismatched_hop_count(): void
    {
        $order = OrderFactory::buy('AAA', 'BBB', '1.000', '1.000', '1.000', 3, 3);
        $edge = PathEdge::create(
            'AAA',
            'BBB',
            $order,
            $order->effectiveRate(),
            OrderSide::BUY,
            BcMath::normalize('1.000000000000000000', 18),
        );

        $sequence = PathEdgeSequence::fromList([$edge]);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Hop count must match the number of edges in the candidate path.');

        CandidatePath::from(
            BcMath::normalize('1.000000000000000000', 18),
            BcMath::normalize('1.000000000000000000', 18),
            0,
            $sequence,
        );
    }

    public function test_it_rejects_invalid_edge_structure(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge sequence elements must be PathEdge instances.');

        /** @var list<PathEdge> $invalid */
        $invalid = [
            /* @psalm-suppress InvalidArrayOffset */
            [
                'from' => 'AAA',
                'to' => 'BBB',
                'order' => OrderFactory::buy('AAA', 'BBB', '1.000', '1.000', '1.000', 3, 3),
                'rate' => ExchangeRate::fromString('AAA', 'BBB', '1.000', 3),
                'orderSide' => OrderSide::BUY,
                'conversionRate' => BcMath::normalize('1.000000000000000000', 18),
            ],
        ];

        PathEdgeSequence::fromList($invalid);
    }

    public function test_it_rejects_broken_edge_chains(): void
    {
        $firstOrder = OrderFactory::buy('AAA', 'BBB', '1.000', '1.000', '1.000', 3, 3);
        $secondOrder = OrderFactory::buy('CCC', 'DDD', '1.000', '1.000', '1.000', 3, 3);

        $edges = [
            PathEdge::create(
                'AAA',
                'BBB',
                $firstOrder,
                $firstOrder->effectiveRate(),
                OrderSide::BUY,
                BcMath::normalize('1.000000000000000000', self::SCALE),
            ),
            PathEdge::create(
                'CCC',
                'DDD',
                $secondOrder,
                $secondOrder->effectiveRate(),
                OrderSide::BUY,
                BcMath::normalize('1.000000000000000000', self::SCALE),
            ),
        ];

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge sequences must form a continuous chain.');

        PathEdgeSequence::fromList($edges);
    }

    public function test_it_rejects_edges_with_mismatched_order_side(): void
    {
        $order = OrderFactory::buy('AAA', 'BBB', '1.000', '1.000', '1.000', 3, 3);

        $edge = PathEdge::create(
            'BBB',
            'AAA',
            $order,
            $order->effectiveRate(),
            OrderSide::SELL,
            BcMath::normalize('1.000000000000000000', self::SCALE),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge order side must match the underlying order.');

        PathEdgeSequence::fromList([$edge]);
    }

    public function test_it_rejects_edges_with_misaligned_endpoints(): void
    {
        $order = OrderFactory::sell('CCC', 'BBB', '1.000', '1.000', '1.000', 3, 3);

        // Tamper with the expected endpoints by flipping them.
        $misaligned = PathEdge::create(
            'CCC',
            'BBB',
            $order,
            $order->effectiveRate(),
            OrderSide::SELL,
            BcMath::normalize('1.000000000000000000', self::SCALE),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge endpoints must align with the underlying order asset pair and side.');

        PathEdgeSequence::fromList([$misaligned]);
    }

    public function test_it_serializes_to_array(): void
    {
        $cost = BcMath::normalize('1.234500000000000000', self::SCALE);
        $product = BcMath::normalize('0.987650000000000000', self::SCALE);

        $firstOrder = OrderFactory::buy('AAA', 'BBB', '1.000', '1.000', '1.000', 3, 3);
        $secondOrder = OrderFactory::sell('CCC', 'BBB', '1.000', '1.000', '1.000', 3, 3);

        $edges = PathEdgeSequence::fromList([
            PathEdge::create(
                'AAA',
                'BBB',
                $firstOrder,
                $firstOrder->effectiveRate(),
                OrderSide::BUY,
                BcMath::normalize('1.100000000000000000', self::SCALE),
            ),
            PathEdge::create(
                'BBB',
                'CCC',
                $secondOrder,
                $secondOrder->effectiveRate(),
                OrderSide::SELL,
                BcMath::normalize('0.900000000000000000', self::SCALE),
            ),
        ]);

        $min = Money::fromString('USD', '1.000', 3);
        $max = Money::fromString('USD', '5.000', 3);
        $desired = Money::fromString('USD', '2.500', 3);
        $constraints = SpendConstraints::from($min, $max, $desired);

        $candidate = CandidatePath::from($cost, $product, 2, $edges, $constraints);

        $payload = $candidate->toArray();

        self::assertSame($cost, $payload['cost']);
        self::assertSame($product, $payload['product']);
        self::assertSame(2, $payload['hops']);
        self::assertCount(2, $payload['edges']);
        self::assertSame('AAA', $payload['edges'][0]['from']);
        self::assertSame('BBB', $payload['edges'][0]['to']);
        self::assertSame('BBB', $payload['edges'][1]['from']);
        self::assertSame('CCC', $payload['edges'][1]['to']);

        $range = $constraints->toRange();
        self::assertNotNull($payload['amountRange']);
        self::assertSame($range['min'], $payload['amountRange']['min']);
        self::assertSame($range['max'], $payload['amountRange']['max']);
        self::assertSame($constraints->desired(), $payload['desiredAmount']);
    }
}
