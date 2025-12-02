<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSet;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalFactory;

#[CoversClass(PathResultSet::class)]
final class PathResultSetTest extends TestCase
{
    public function test_it_orders_entries_using_strategy(): void
    {
        $paths = ['late', 'early', 'middle'];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.300000000000000000')), 3, RouteSignature::fromNodes(['SRC', 'C', 'DST']), 2),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'B', 'DST']), 1),
        ];

        $set = $this->createResultSet($paths, $orderKeys);

        self::assertSame(['early', 'middle', 'late'], $set->toArray());
        self::assertSame($set->toArray(), iterator_to_array($set));
    }

    public function test_it_discards_duplicate_route_signatures(): void
    {
        $paths = ['worse duplicate', 'preferred duplicate', 'unique'];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.300000000000000000')), 3, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 2),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.200000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'B', 'DST']), 1),
        ];

        $set = $this->createResultSet($paths, $orderKeys);

        self::assertSame(['preferred duplicate', 'unique'], $set->toArray());
    }

    public function test_it_discards_duplicates_while_preserving_payload_order(): void
    {
        $paths = [
            'discarded',
            'preferred',
            'unique1',
            'unique2',
        ];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.400000000000000000')), 3, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 3),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.150000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'B', 'DST']), 1),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.200000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'C', 'DST']), 2),
        ];

        $set = $this->createResultSet($paths, $orderKeys);

        $expectedPayloads = ['preferred', 'unique1', 'unique2'];

        self::assertSame($expectedPayloads, $set->toArray());
    }

    public function test_slice_returns_subset_preserving_order(): void
    {
        $paths = ['first', 'second', 'third'];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.200000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'B', 'DST']), 1),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.300000000000000000')), 3, RouteSignature::fromNodes(['SRC', 'C', 'DST']), 2),
        ];

        $set = $this->createResultSet($paths, $orderKeys);

        self::assertSame(['first', 'second'], $set->slice(0, 2)->toArray());
        self::assertSame(['second', 'third'], $set->slice(1)->toArray());
    }

    public function test_slice_returns_empty_set_for_out_of_bounds_offsets(): void
    {
        $paths = ['only'];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
        ];

        $set = $this->createResultSet($paths, $orderKeys);

        $slice = $set->slice(5);

        self::assertTrue($slice->isEmpty());
        self::assertSame([], $slice->toArray());
    }

    public function test_empty_collection_behavior(): void
    {
        $set = PathResultSet::empty();

        $this->assertTrue($set->isEmpty());
        $this->assertSame(0, $set->count());
        $this->assertNull($set->first());
        $this->assertSame([], $set->toArray());
        $this->assertSame([], iterator_to_array($set));
    }

    public function test_empty_collection_slice_returns_empty(): void
    {
        $set = PathResultSet::empty();

        $slice = $set->slice(0, 10);

        $this->assertTrue($slice->isEmpty());
        $this->assertSame(0, $slice->count());
    }

    public function test_iterator_functionality(): void
    {
        $paths = ['first', 'second', 'third'];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.200000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'B', 'DST']), 1),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.300000000000000000')), 3, RouteSignature::fromNodes(['SRC', 'C', 'DST']), 2),
        ];

        $set = $this->createResultSet($paths, $orderKeys);

        $iterated = [];
        foreach ($set as $index => $path) {
            $iterated[$index] = $path;
        }

        $this->assertSame(['first', 'second', 'third'], $iterated);
    }

    public function test_count_and_is_empty_methods(): void
    {
        // Test empty set
        $emptySet = PathResultSet::empty();
        $this->assertSame(0, $emptySet->count());
        $this->assertTrue($emptySet->isEmpty());

        // Test non-empty set
        $paths = ['single'];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
        ];

        $set = $this->createResultSet($paths, $orderKeys);
        $this->assertSame(1, $set->count());
        $this->assertFalse($set->isEmpty());
    }

    public function test_first_method(): void
    {
        // Test empty set
        $emptySet = PathResultSet::empty();
        $this->assertNull($emptySet->first());

        // Test non-empty set
        $paths = ['first', 'second'];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.200000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'B', 'DST']), 1),
        ];

        $set = $this->createResultSet($paths, $orderKeys);
        $this->assertSame('first', $set->first());
    }

    public function test_to_array_method(): void
    {
        $paths = ['path1', 'path2'];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.200000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'B', 'DST']), 1),
        ];

        $set = $this->createResultSet($paths, $orderKeys);

        $this->assertSame(['path1', 'path2'], $set->toArray());
    }

    public function test_slice_with_negative_offset(): void
    {
        $paths = ['first', 'second', 'third'];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.200000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'B', 'DST']), 1),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.300000000000000000')), 3, RouteSignature::fromNodes(['SRC', 'C', 'DST']), 2),
        ];

        $set = $this->createResultSet($paths, $orderKeys);

        $slice = $set->slice(-2);
        $this->assertSame(['second', 'third'], $slice->toArray());
    }

    public function test_slice_with_negative_length(): void
    {
        $paths = ['first', 'second', 'third'];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.200000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'B', 'DST']), 1),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.300000000000000000')), 3, RouteSignature::fromNodes(['SRC', 'C', 'DST']), 2),
        ];

        $set = $this->createResultSet($paths, $orderKeys);

        $slice = $set->slice(0, -1);
        $this->assertSame(['first', 'second'], $slice->toArray());
    }

    public function test_slice_returns_new_instance(): void
    {
        $paths = ['first', 'second'];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.200000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'B', 'DST']), 1),
        ];

        $set = $this->createResultSet($paths, $orderKeys);
        $slice = $set->slice(0, 1);

        $this->assertNotSame($set, $slice);
        $this->assertSame(2, $set->count());
        $this->assertSame(1, $slice->count());
    }

    public function test_from_paths_with_invalid_order_key_resolver(): void
    {
        $strategy = new CostHopsSignatureOrderingStrategy(18);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path order key resolver must return an instance of SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey, string returned.');

        PathResultSet::fromPaths(
            $strategy,
            ['invalid'],
            static fn (mixed $path, int $index): string => 'not-a-path-order-key',
        );
    }

    public function test_from_paths_with_empty_iterable(): void
    {
        $strategy = new CostHopsSignatureOrderingStrategy(18);

        $set = PathResultSet::fromPaths(
            $strategy,
            [],
            static fn (mixed $path, int $index): PathOrderKey => new PathOrderKey(
                new PathCost(DecimalFactory::decimal('0.100000000000000000')),
                1,
                RouteSignature::fromNodes(['SRC', 'DST']),
                0
            ),
        );

        $this->assertTrue($set->isEmpty());
        $this->assertSame(0, $set->count());
    }

    public function test_duplicate_discarding_preserves_correct_order(): void
    {
        // Create paths where the first and third have same signature
        // After ordering: third (lowest cost), second, first (highest cost)
        // Duplicates removed: keep third (first occurrence of signature A), and second (unique signature B)
        $paths = ['first_duplicate', 'different', 'second_duplicate'];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.200000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0), // higher cost, signature A
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.150000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'B', 'DST']), 1), // different signature B
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 2), // lower cost, signature A
        ];

        $set = $this->createResultSet($paths, $orderKeys);

        // After ordering and deduplication: second_duplicate (best for signature A), different (signature B)
        $this->assertSame(['second_duplicate', 'different'], $set->toArray());
    }

    /**
     * @template TPath of mixed
     *
     * @param list<TPath>        $paths
     * @param list<PathOrderKey> $orderKeys
     *
     * @return PathResultSet<TPath>
     */
    private function createResultSet(array $paths, array $orderKeys): PathResultSet
    {
        $strategy = new CostHopsSignatureOrderingStrategy(18);

        return PathResultSet::fromPaths(
            $strategy,
            $paths,
            static fn (mixed $path, int $index): PathOrderKey => $orderKeys[$index],
        );
    }

    private function createArrayConvertiblePayload(): object
    {
        return new class {
            /**
             * @return array{type: string}
             */
            public function toArray(): array
            {
                return ['type' => 'array'];
            }
        };
    }
}
