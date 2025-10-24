<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\ValueObject;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class CandidatePathTest extends TestCase
{
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
}
