<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSetEntry;

use function count;

#[CoversClass(PathResultSetEntry::class)]
final class PathResultSetEntryTest extends TestCase
{
    #[TestDox('Construction with string path and orderKey getter works')]
    public function test_construction_with_string_path_and_order_key_getter_works(): void
    {
        $orderKey = $this->createOrderKey('2.0', ['USD', 'EUR']);

        $entry = new PathResultSetEntry('USD->EUR', $orderKey);

        self::assertSame('USD->EUR', $entry->path());
        self::assertSame('2.000000000000000000', $entry->orderKey()->cost()->value());
        self::assertSame('USD->EUR', $entry->orderKey()->routeSignature()->value());
    }

    #[TestDox('Construction with object path works')]
    public function test_construction_with_object_path_works(): void
    {
        $path = new \stdClass();
        $path->route = 'USD->GBP->EUR';
        $entry = new PathResultSetEntry($path, $this->createOrderKey('1.5', ['USD', 'GBP', 'EUR']));

        self::assertSame('USD->GBP->EUR', $entry->path()->route);
    }

    #[TestDox('path() returns the exact array value passed to constructor')]
    public function test_path_returns_the_exact_array_value_passed_to_constructor(): void
    {
        $path = ['hop1' => 'USD', 'hop2' => 'EUR'];
        $entry = new PathResultSetEntry($path, $this->createOrderKey('1.5', ['USD', 'EUR']));

        self::assertSame($path, $entry->path());
    }

    #[TestDox('orderKey() exposes cost and route signature from construction')]
    public function test_order_key_exposes_cost_and_route_signature_from_construction(): void
    {
        $entry = new PathResultSetEntry('any-path', $this->createOrderKey('3.14', ['A', 'B', 'C']));

        self::assertSame('3.140000000000000000', $entry->orderKey()->cost()->value());
        self::assertSame('A->B->C', $entry->orderKey()->routeSignature()->value());
        self::assertSame(2, $entry->orderKey()->hops());
    }

    /**
     * @param numeric-string $cost
     * @param list<string>   $nodes
     */
    private function createOrderKey(string $cost, array $nodes): PathOrderKey
    {
        return new PathOrderKey(
            new PathCost($cost),
            count($nodes) - 1,
            RouteSignature::fromNodes($nodes),
            1,
        );
    }
}
