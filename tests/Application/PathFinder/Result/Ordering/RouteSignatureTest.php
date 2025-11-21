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

    public function test_very_long_route_signature(): void
    {
        $nodes = ['USD', 'EUR', 'GBP', 'JPY', 'CHF', 'AUD', 'CAD', 'NZD', 'SEK', 'NOK', 'DKK', 'PLN'];
        $signature = RouteSignature::fromNodes($nodes);

        self::assertSame($nodes, $signature->nodes());
        self::assertSame('USD->EUR->GBP->JPY->CHF->AUD->CAD->NZD->SEK->NOK->DKK->PLN', $signature->value());
        self::assertCount(12, $signature->nodes());
    }

    public function test_single_node_route(): void
    {
        $signature = RouteSignature::fromNodes(['SINGLE']);

        self::assertSame(['SINGLE'], $signature->nodes());
        self::assertSame('SINGLE', $signature->value());
    }

    public function test_nodes_with_numbers_and_underscores(): void
    {
        $nodes = ['TOKEN_1', 'TOKEN_2', 'ASSET_123', 'COIN_999'];
        $signature = RouteSignature::fromNodes($nodes);

        self::assertSame($nodes, $signature->nodes());
        self::assertSame('TOKEN_1->TOKEN_2->ASSET_123->COIN_999', $signature->value());
    }

    public function test_case_sensitivity_in_comparison(): void
    {
        $upper = RouteSignature::fromNodes(['USD', 'EUR']);
        $lower = RouteSignature::fromNodes(['usd', 'eur']);
        $mixed = RouteSignature::fromNodes(['Usd', 'Eur']);

        // All should be different due to case sensitivity
        self::assertFalse($upper->equals($lower));
        self::assertFalse($upper->equals($mixed));
        self::assertFalse($lower->equals($mixed));

        // Comparison should be lexicographic and case-sensitive
        self::assertLessThan(0, $upper->compare($lower)); // 'USD' < 'usd' (uppercase first)
        self::assertGreaterThan(0, $lower->compare($upper));
    }

    public function test_lexicographic_ordering_with_common_prefix(): void
    {
        $short = RouteSignature::fromNodes(['USD', 'EUR']);
        $long = RouteSignature::fromNodes(['USD', 'EUR', 'GBP']);

        // Shorter path should come first lexicographically
        self::assertLessThan(0, $short->compare($long));
        self::assertGreaterThan(0, $long->compare($short));
    }

    public function test_nodes_are_preserved_exactly(): void
    {
        $nodesWithSpaces = ['  ASSET_A  ', ' ASSET_B ', 'ASSET_C   '];
        $signature = RouteSignature::fromNodes($nodesWithSpaces);

        // Nodes should be trimmed
        self::assertSame(['ASSET_A', 'ASSET_B', 'ASSET_C'], $signature->nodes());
    }

    public function test_to_string_returns_value(): void
    {
        $signature = RouteSignature::fromNodes(['A', 'B', 'C']);

        self::assertSame('A->B->C', (string) $signature);
        self::assertSame($signature->value(), (string) $signature);
    }

    public function test_rejects_empty_node_at_start(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Route signature nodes cannot be empty (index 0).');

        RouteSignature::fromNodes(['', 'A', 'B']);
    }

    public function test_rejects_empty_node_in_middle(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Route signature nodes cannot be empty (index 2).');

        RouteSignature::fromNodes(['A', 'B', '', 'C']);
    }
}
