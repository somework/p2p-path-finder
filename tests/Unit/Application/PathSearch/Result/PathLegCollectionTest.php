<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathLeg;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathLegCollection;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

#[CoversClass(PathLegCollection::class)]
final class PathLegCollectionTest extends TestCase
{
    public function test_it_orders_legs_monotonically(): void
    {
        $first = new PathLeg(
            'usd',
            'btc',
            Money::fromString('USD', '100', 2),
            Money::fromString('BTC', '2', 2),
        );
        $second = new PathLeg(
            'btc',
            'eth',
            Money::fromString('BTC', '2', 2),
            Money::fromString('ETH', '30', 2),
        );

        $collection = PathLegCollection::fromList([$second, $first]);
        $legs = $collection->all();

        self::assertSame('USD', $legs[0]->from());
        self::assertSame('BTC', $legs[0]->to());
        self::assertSame('BTC', $legs[1]->from());
        self::assertSame('ETH', $legs[1]->to());
    }

    public function test_it_rejects_duplicate_sources(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path legs must be unique.');

        PathLegCollection::fromList([
            new PathLeg(
                'usd',
                'btc',
                Money::fromString('USD', '1', 2),
                Money::fromString('BTC', '2', 2),
            ),
            new PathLeg(
                'usd',
                'eth',
                Money::fromString('USD', '1', 2),
                Money::fromString('ETH', '3', 2),
            ),
        ]);
    }

    public function test_it_rejects_non_monotonic_sequences(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path legs must form a monotonic sequence.');

        PathLegCollection::fromList([
            new PathLeg(
                'usd',
                'btc',
                Money::fromString('USD', '1', 2),
                Money::fromString('BTC', '2', 2),
            ),
            new PathLeg(
                'eur',
                'gbp',
                Money::fromString('EUR', '3', 2),
                Money::fromString('GBP', '4', 2),
            ),
        ]);
    }

    public function test_it_rejects_sequences_with_cycles(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path legs must form a monotonic sequence.');

        PathLegCollection::fromList([
            new PathLeg(
                'usd',
                'btc',
                Money::fromString('USD', '1', 2),
                Money::fromString('BTC', '2', 2),
            ),
            new PathLeg(
                'btc',
                'usd',
                Money::fromString('BTC', '2', 2),
                Money::fromString('USD', '1', 2),
            ),
        ]);
    }

    public function test_it_rejects_sequences_with_multiple_start_points(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path legs must form a monotonic sequence.');

        PathLegCollection::fromList([
            new PathLeg(
                'usd',
                'btc',
                Money::fromString('USD', '1', 2),
                Money::fromString('BTC', '2', 2),
            ),
            new PathLeg(
                'eur',
                'gbp',
                Money::fromString('EUR', '3', 2),
                Money::fromString('GBP', '4', 2),
            ),
        ]);
    }

    public function test_it_rejects_sequences_with_isolated_components(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path legs must form a monotonic sequence.');

        PathLegCollection::fromList([
            new PathLeg(
                'usd',
                'btc',
                Money::fromString('USD', '1', 2),
                Money::fromString('BTC', '2', 2),
            ),
            new PathLeg(
                'eth',
                'ada',
                Money::fromString('ETH', '3', 2),
                Money::fromString('ADA', '4', 2),
            ),
        ]);
    }

    public function test_it_rejects_same_from_and_to_assets(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path legs must form a monotonic sequence.');

        $leg = new PathLeg(
            'usd',
            'usd',
            Money::fromString('USD', '100', 2),
            Money::fromString('USD', '100', 2),
        );

        PathLegCollection::fromList([$leg]);
    }

    public function test_it_rejects_duplicate_sources_in_different_positions(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path legs must be unique.');

        PathLegCollection::fromList([
            new PathLeg(
                'usd',
                'btc',
                Money::fromString('USD', '1', 2),
                Money::fromString('BTC', '2', 2),
            ),
            new PathLeg(
                'btc',
                'eth',
                Money::fromString('BTC', '2', 2),
                Money::fromString('ETH', '3', 2),
            ),
            new PathLeg(
                'usd', // Duplicate source
                'ada',
                Money::fromString('USD', '1', 2),
                Money::fromString('ADA', '4', 2),
            ),
        ]);
    }

    public function test_to_array_returns_same_as_all(): void
    {
        $leg = new PathLeg(
            'usd',
            'btc',
            Money::fromString('USD', '100', 2),
            Money::fromString('BTC', '0.01', 8),
        );

        $collection = PathLegCollection::fromList([$leg]);

        self::assertSame($collection->all(), $collection->toArray());
    }

    public function test_at_rejects_out_of_bounds_index(): void
    {
        $collection = PathLegCollection::fromList([
            new PathLeg(
                'usd',
                'btc',
                Money::fromString('USD', '1', 2),
                Money::fromString('BTC', '2', 2),
            ),
        ]);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path leg index must reference an existing position.');

        $collection->at(1);
    }

    public function test_at_rejects_negative_index(): void
    {
        $collection = PathLegCollection::fromList([
            new PathLeg(
                'usd',
                'btc',
                Money::fromString('USD', '1', 2),
                Money::fromString('BTC', '2', 2),
            ),
        ]);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path leg index must reference an existing position.');

        $collection->at(-1);
    }

    public function test_at_rejects_very_large_index(): void
    {
        $collection = PathLegCollection::fromList([
            new PathLeg(
                'usd',
                'btc',
                Money::fromString('USD', '1', 2),
                Money::fromString('BTC', '2', 2),
            ),
        ]);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path leg index must reference an existing position.');

        $collection->at(999999);
    }

    public function test_from_list_rejects_associative_arrays(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path legs must be provided as a list.');

        PathLegCollection::fromList([
            'first' => new PathLeg(
                'usd',
                'btc',
                Money::fromString('USD', '1', 2),
                Money::fromString('BTC', '2', 2),
            ),
        ]);
    }

    public function test_from_list_rejects_non_path_leg_instances(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Every path leg must be an instance of PathLeg.');

        PathLegCollection::fromList([
            new PathLeg(
                'usd',
                'btc',
                Money::fromString('USD', '1', 2),
                Money::fromString('BTC', '2', 2),
            ),
            'not-a-path-leg',
        ]);
    }

    public function test_first_returns_null_when_collection_empty(): void
    {
        self::assertNull(PathLegCollection::empty()->first());
    }

    public function test_empty_collection_creation(): void
    {
        $collection = PathLegCollection::empty();

        self::assertTrue($collection->isEmpty());
        self::assertSame(0, $collection->count());
        self::assertNull($collection->first());
        self::assertSame([], $collection->all());
        self::assertSame([], $collection->toArray());
    }

    public function test_single_leg_collection(): void
    {
        $leg = new PathLeg(
            'usd',
            'btc',
            Money::fromString('USD', '100', 2),
            Money::fromString('BTC', '0.01', 8),
        );

        $collection = PathLegCollection::fromList([$leg]);

        self::assertFalse($collection->isEmpty());
        self::assertSame(1, $collection->count());
        self::assertSame($leg, $collection->first());
        self::assertSame([$leg], $collection->all());
        self::assertSame([$leg], $collection->toArray());
    }

    public function test_multi_leg_collection_getters(): void
    {
        $first = new PathLeg(
            'usd',
            'btc',
            Money::fromString('USD', '100', 2),
            Money::fromString('BTC', '0.01', 8),
        );
        $second = new PathLeg(
            'btc',
            'eth',
            Money::fromString('BTC', '0.01', 8),
            Money::fromString('ETH', '15', 2),
        );

        $collection = PathLegCollection::fromList([$second, $first]);

        self::assertFalse($collection->isEmpty());
        self::assertSame(2, $collection->count());
        self::assertSame($first, $collection->first());
        self::assertSame([$first, $second], $collection->all());
        self::assertSame([$first, $second], $collection->toArray());
    }

    public function test_at_method_with_valid_indices(): void
    {
        $first = new PathLeg(
            'usd',
            'btc',
            Money::fromString('USD', '100', 2),
            Money::fromString('BTC', '0.01', 8),
        );
        $second = new PathLeg(
            'btc',
            'eth',
            Money::fromString('BTC', '0.01', 8),
            Money::fromString('ETH', '15', 2),
        );

        $collection = PathLegCollection::fromList([$second, $first]);

        self::assertSame($first, $collection->at(0));
        self::assertSame($second, $collection->at(1));
    }

    public function test_iterator_functionality(): void
    {
        $first = new PathLeg(
            'usd',
            'btc',
            Money::fromString('USD', '100', 2),
            Money::fromString('BTC', '0.01', 8),
        );
        $second = new PathLeg(
            'btc',
            'eth',
            Money::fromString('BTC', '0.01', 8),
            Money::fromString('ETH', '15', 2),
        );

        $collection = PathLegCollection::fromList([$second, $first]);

        $iterated = [];
        foreach ($collection as $index => $leg) {
            $iterated[$index] = $leg;
        }

        self::assertSame([$first, $second], $iterated);
    }
}
