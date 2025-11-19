<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Result\Ordering;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class RouteSignatureTest extends TestCase
{
    public function test_it_trims_and_joins_nodes(): void
    {
        $signature = RouteSignature::fromNodes(['  SRC ', 'MID', ' DST  ']);

        self::assertSame(['SRC', 'MID', 'DST'], $signature->nodes());
        self::assertSame('SRC->MID->DST', $signature->value());
    }

    public function test_it_rejects_blank_nodes(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Route signature nodes cannot be empty (index 1).');

        RouteSignature::fromNodes(['SRC', '   ']);
    }

    public function test_it_can_be_created_from_path_edge_sequence(): void
    {
        $buyOrder = OrderFactory::buy('SRC', 'MID', '1.000', '1.000', '1.000', 3, 3);
        $secondOrder = OrderFactory::buy('MID', 'DST', '1.000', '1.000', '1.000', 3, 3);

        $sequence = PathEdgeSequence::fromList([
            PathEdge::create('SRC', 'MID', $buyOrder, $buyOrder->effectiveRate(), OrderSide::BUY, BigDecimal::of('1.000000000000000000')),
            PathEdge::create('MID', 'DST', $secondOrder, $secondOrder->effectiveRate(), OrderSide::BUY, BigDecimal::of('1.000000000000000000')),
        ]);

        $signature = RouteSignature::fromPathEdgeSequence($sequence);

        self::assertSame(['SRC', 'MID', 'DST'], $signature->nodes());
        self::assertSame('SRC->MID->DST', $signature->value());
    }

    public function test_it_handles_empty_sequences(): void
    {
        $signature = RouteSignature::fromNodes([]);

        self::assertSame([], $signature->nodes());
        self::assertSame('', $signature->value());
    }

    public function test_equals_and_compare_use_normalized_value(): void
    {
        $alpha = RouteSignature::fromNodes(['SRC', 'DST']);
        $beta = RouteSignature::fromNodes(['SRC', 'dst']);
        $gamma = RouteSignature::fromNodes(['SRC', 'BET']);

        self::assertTrue($alpha->equals(RouteSignature::fromNodes(['SRC', 'DST'])));
        self::assertSame(0, $alpha->compare(RouteSignature::fromNodes(['SRC', 'DST'])));
        self::assertSame(1, $alpha->compare($gamma));
        self::assertSame(-1, $gamma->compare($alpha));
        self::assertFalse($alpha->equals($beta));
    }
}
