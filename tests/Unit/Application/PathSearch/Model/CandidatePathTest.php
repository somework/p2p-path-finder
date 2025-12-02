<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Model;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdgeSequence;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\SpendConstraints;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalMath;

final class CandidatePathTest extends TestCase
{
    private const SCALE = 18;

    public function test_it_rejects_negative_hop_count(): void
    {
        $sequence = PathEdgeSequence::fromList([]);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Hop count cannot be negative.');

        CandidatePath::from(
            DecimalMath::decimal('1.000000000000000000', 18),
            DecimalMath::decimal('1.000000000000000000', 18),
            -1,
            $sequence,
        );
    }

    public function test_it_rejects_mismatched_hop_count(): void
    {
        $order = OrderFactory::buy('AAA', 'BBB', '1.000', '1.000', '1.000', 3, 3);
        $edge = PathEdge::create(
            'AAA',
            'BBB',
            $order,
            $order->effectiveRate(),
            OrderSide::BUY,
            DecimalMath::decimal('1.000000000000000000', 18),
        );

        $sequence = PathEdgeSequence::fromList([$edge]);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Hop count must match the number of edges in the candidate path.');

        CandidatePath::from(
            DecimalMath::decimal('1.000000000000000000', 18),
            DecimalMath::decimal('1.000000000000000000', 18),
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
                'conversionRate' => DecimalMath::normalize('1.000000000000000000', 18),
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
                DecimalMath::decimal('1.000000000000000000', self::SCALE),
            ),
            PathEdge::create(
                'CCC',
                'DDD',
                $secondOrder,
                $secondOrder->effectiveRate(),
                OrderSide::BUY,
                DecimalMath::decimal('1.000000000000000000', self::SCALE),
            ),
        ];

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessageMatches('/^Path edge sequences must form a continuous chain\b/');

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
            DecimalMath::decimal('1.000000000000000000', self::SCALE),
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
            DecimalMath::decimal('1.000000000000000000', self::SCALE),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path edge endpoints must align with the underlying order asset pair and side.');

        PathEdgeSequence::fromList([$misaligned]);
    }

    public function test_it_provides_access_to_all_properties(): void
    {
        $cost = DecimalMath::decimal('1.234567890123456789', 18);
        $product = DecimalMath::decimal('0.987654321098765432', 18);

        $order = OrderFactory::buy('AAA', 'BBB', '1.000', '1.000', '1.000', 3, 3);
        $edge = PathEdge::create(
            'AAA',
            'BBB',
            $order,
            $order->effectiveRate(),
            OrderSide::BUY,
            DecimalMath::decimal('1.000000000000000000', 18),
        );

        $edges = PathEdgeSequence::fromList([$edge]);
        $constraints = SpendConstraints::from(
            Money::fromString('USD', '1.000', 3),
            Money::fromString('USD', '5.000', 3),
        );

        $candidate = CandidatePath::from($cost, $product, 1, $edges, $constraints);

        self::assertSame('1.234567890123456789', $candidate->cost());
        self::assertTrue($candidate->costDecimal()->isEqualTo($cost));
        self::assertSame('0.987654321098765432', $candidate->product());
        self::assertTrue($candidate->productDecimal()->isEqualTo($product));
        self::assertSame(1, $candidate->hops());
        self::assertSame($edges, $candidate->edges());
        self::assertSame($constraints, $candidate->range());
    }

    public function test_it_handles_zero_hop_paths(): void
    {
        $cost = DecimalMath::decimal('1.000000000000000000', 18);
        $product = DecimalMath::decimal('1.000000000000000000', 18);
        $edges = PathEdgeSequence::fromList([]);

        $candidate = CandidatePath::from($cost, $product, 0, $edges);

        self::assertSame('1.000000000000000000', $candidate->cost());
        self::assertSame('1.000000000000000000', $candidate->product());
        self::assertSame(0, $candidate->hops());
        self::assertSame($edges, $candidate->edges());
        self::assertNull($candidate->range());
    }

    public function test_it_handles_paths_without_constraints(): void
    {
        $cost = DecimalMath::decimal('2.500000000000000000', 18);
        $product = DecimalMath::decimal('0.400000000000000000', 18);

        $order = OrderFactory::sell('CCC', 'BBB', '1.000', '1.000', '1.000', 3, 3);
        $edge = PathEdge::create(
            'BBB',
            'CCC',
            $order,
            $order->effectiveRate(),
            OrderSide::SELL,
            DecimalMath::decimal('1.000000000000000000', 18),
        );

        $edges = PathEdgeSequence::fromList([$edge]);

        $candidate = CandidatePath::from($cost, $product, 1, $edges);

        self::assertSame('2.500000000000000000', $candidate->cost());
        self::assertSame('0.400000000000000000', $candidate->product());
        self::assertSame(1, $candidate->hops());
        self::assertSame($edges, $candidate->edges());
        self::assertNull($candidate->range());
    }

    public function test_number_formatting_in_getters(): void
    {
        // Test rounding behavior for cost and product
        $cost = DecimalMath::decimal('1.234567890123456789123456789', 27); // More precision than 18
        $product = DecimalMath::decimal('0.987654321098765432109876543', 27);

        $edges = PathEdgeSequence::fromList([]);
        $candidate = CandidatePath::from($cost, $product, 0, $edges);

        // Should be rounded to 18 decimal places with HALF_UP rounding
        self::assertSame('1.234567890123456789', $candidate->cost());
        self::assertSame('0.987654321098765432', $candidate->product());

        // Decimal getters should return original precision
        self::assertTrue($candidate->costDecimal()->isEqualTo($cost));
        self::assertTrue($candidate->productDecimal()->isEqualTo($product));
    }
}
