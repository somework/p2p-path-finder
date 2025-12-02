<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\MaterializedResult;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResult;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSetEntry;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalFactory;

#[CoversClass(MaterializedResult::class)]
final class MaterializedResultTest extends TestCase
{
    public function test_it_exposes_path_result_and_order_key(): void
    {
        $result = new PathResult(
            Money::fromString('USD', '100.00', 2),
            Money::fromString('EUR', '90.00', 2),
            DecimalTolerance::fromNumericString('0.050000000000000000'),
        );

        $orderKey = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('1.111111111111111111')),
            1,
            RouteSignature::fromNodes(['USD', 'EUR']),
            0,
        );

        $materialized = new MaterializedResult($result, $orderKey);

        self::assertSame($result, $materialized->result());
        self::assertSame($orderKey, $materialized->orderKey());

        $entry = $materialized->toEntry();

        self::assertInstanceOf(PathResultSetEntry::class, $entry);
        self::assertSame($result, $entry->path());
        self::assertSame($orderKey, $entry->orderKey());
        self::assertSame($result->jsonSerialize(), $materialized->jsonSerialize());
    }

    public function test_it_handles_zero_amounts(): void
    {
        $result = new PathResult(
            Money::fromString('USD', '0.00', 2),
            Money::fromString('EUR', '0.00', 2),
            DecimalTolerance::fromNumericString('0.000000000000000000'),
        );

        $orderKey = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('0.000000000000000000')),
            0,
            RouteSignature::fromNodes([]),
            0,
        );

        $materialized = new MaterializedResult($result, $orderKey);

        self::assertSame('0.00', $materialized->result()->totalSpent()->amount());
        self::assertSame('0.00', $materialized->result()->totalReceived()->amount());
        self::assertSame(0, $materialized->orderKey()->hops());
        self::assertEmpty($materialized->orderKey()->routeSignature()->nodes());
    }

    public function test_it_handles_very_small_amounts(): void
    {
        $result = new PathResult(
            Money::fromString('BTC', '0.00000001', 8),
            Money::fromString('ETH', '0.00000100', 8),
            DecimalTolerance::fromNumericString('0.000000000000000001'),
        );

        $orderKey = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('0.000000000000000001')),
            1,
            RouteSignature::fromNodes(['BTC', 'ETH']),
            42,
        );

        $materialized = new MaterializedResult($result, $orderKey);

        self::assertSame('0.00000001', $materialized->result()->totalSpent()->amount());
        self::assertSame('0.00000100', $materialized->result()->totalReceived()->amount());
        self::assertSame(8, $materialized->result()->totalSpent()->scale());
        self::assertSame(8, $materialized->result()->totalReceived()->scale());
        self::assertSame(1, $materialized->orderKey()->hops());
        self::assertSame(42, $materialized->orderKey()->insertionOrder());
    }

    public function test_it_handles_very_large_amounts(): void
    {
        $result = new PathResult(
            Money::fromString('USD', '1000000.00', 2),
            Money::fromString('EUR', '950000.00', 2),
            DecimalTolerance::fromNumericString('0.500000000000000000'),
        );

        $orderKey = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('1.052631578947368421')),
            3,
            RouteSignature::fromNodes(['USD', 'GBP', 'EUR']),
            1000,
        );

        $materialized = new MaterializedResult($result, $orderKey);

        self::assertSame('1000000.00', $materialized->result()->totalSpent()->amount());
        self::assertSame('950000.00', $materialized->result()->totalReceived()->amount());
        self::assertSame(3, $materialized->orderKey()->hops());
        self::assertCount(3, $materialized->orderKey()->routeSignature()->nodes());
    }

    public function test_it_handles_complex_currencies_and_amounts(): void
    {
        $result = new PathResult(
            Money::fromString('JPY', '1234567.89', 2),
            Money::fromString('KRW', '987654.32', 2),
            DecimalTolerance::fromNumericString('0.123456789012345678'),
        );

        $orderKey = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('1.234567890123456789')),
            5,
            RouteSignature::fromNodes(['JPY', 'USD', 'EUR', 'GBP', 'KRW']),
            999,
        );

        $materialized = new MaterializedResult($result, $orderKey);

        self::assertSame('JPY', $materialized->result()->totalSpent()->currency());
        self::assertSame('KRW', $materialized->result()->totalReceived()->currency());
        self::assertSame('1234567.89', $materialized->result()->totalSpent()->amount());
        self::assertSame('987654.32', $materialized->result()->totalReceived()->amount());
        self::assertSame(5, $materialized->orderKey()->hops());
        self::assertCount(5, $materialized->orderKey()->routeSignature()->nodes());
        self::assertSame(999, $materialized->orderKey()->insertionOrder());
    }

    public function test_it_handles_different_tolerance_values(): void
    {
        $testCases = [
            '0.000000000000000000', // Zero tolerance
            '0.000000000000000001', // Very small tolerance
            '0.500000000000000000', // Medium tolerance
            '0.999999999999999999', // Very large tolerance
        ];

        foreach ($testCases as $tolerance) {
            $result = new PathResult(
                Money::fromString('USD', '100.00', 2),
                Money::fromString('EUR', '90.00', 2),
                DecimalTolerance::fromNumericString($tolerance),
            );

            $orderKey = new PathOrderKey(
                new PathCost(DecimalFactory::decimal('1.111111111111111111')),
                1,
                RouteSignature::fromNodes(['USD', 'EUR']),
                0,
            );

            $materialized = new MaterializedResult($result, $orderKey);

            self::assertSame($tolerance, $materialized->result()->residualTolerance()->ratio());
            self::assertSame($result->jsonSerialize(), $materialized->jsonSerialize());
        }
    }

    public function test_it_handles_different_hop_counts(): void
    {
        $testCases = [0, 1, 5, 10, 100];

        foreach ($testCases as $hops) {
            $nodes = array_fill(0, $hops + 1, 'NODE');
            $routeSignature = RouteSignature::fromNodes($nodes);

            $orderKey = new PathOrderKey(
                new PathCost(DecimalFactory::decimal('1.000000000000000000')),
                $hops,
                $routeSignature,
                0,
            );

            $result = new PathResult(
                Money::fromString('USD', '100.00', 2),
                Money::fromString('EUR', '90.00', 2),
                DecimalTolerance::fromNumericString('0.050000000000000000'),
            );

            $materialized = new MaterializedResult($result, $orderKey);

            self::assertSame($hops, $materialized->orderKey()->hops());
            self::assertCount($hops + 1, $materialized->orderKey()->routeSignature()->nodes());
        }
    }

    public function test_it_handles_different_insertion_orders(): void
    {
        $testCases = [0, 1, 42, 999, 10000, -1, -999];

        foreach ($testCases as $insertionOrder) {
            $orderKey = new PathOrderKey(
                new PathCost(DecimalFactory::decimal('1.000000000000000000')),
                1,
                RouteSignature::fromNodes(['A', 'B']),
                $insertionOrder,
            );

            $result = new PathResult(
                Money::fromString('USD', '100.00', 2),
                Money::fromString('EUR', '90.00', 2),
                DecimalTolerance::fromNumericString('0.050000000000000000'),
            );

            $materialized = new MaterializedResult($result, $orderKey);

            self::assertSame($insertionOrder, $materialized->orderKey()->insertionOrder());
        }
    }

    public function test_it_handles_empty_route_signatures(): void
    {
        $orderKey = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('0.000000000000000000')),
            0,
            RouteSignature::fromNodes([]),
            0,
        );

        $result = new PathResult(
            Money::fromString('USD', '100.00', 2),
            Money::fromString('EUR', '90.00', 2),
            DecimalTolerance::fromNumericString('0.050000000000000000'),
        );

        $materialized = new MaterializedResult($result, $orderKey);

        self::assertEmpty($materialized->orderKey()->routeSignature()->nodes());
        self::assertCount(0, $materialized->orderKey()->routeSignature()->nodes());
        self::assertSame(0, $materialized->orderKey()->hops());
    }

    public function test_it_handles_single_node_route_signatures(): void
    {
        $orderKey = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('1.000000000000000000')),
            0,
            RouteSignature::fromNodes(['USD']),
            0,
        );

        $result = new PathResult(
            Money::fromString('USD', '100.00', 2),
            Money::fromString('USD', '100.00', 2), // Same currency, zero-hop
            DecimalTolerance::fromNumericString('0.000000000000000000'),
        );

        $materialized = new MaterializedResult($result, $orderKey);

        self::assertNotEmpty($materialized->orderKey()->routeSignature()->nodes());
        self::assertCount(1, $materialized->orderKey()->routeSignature()->nodes());
        self::assertSame(['USD'], $materialized->orderKey()->routeSignature()->nodes());
        self::assertSame(0, $materialized->orderKey()->hops());
    }

    public function test_it_handles_long_route_signatures(): void
    {
        $nodes = ['USD', 'EUR', 'GBP', 'JPY', 'KRW', 'BTC', 'ETH', 'ADA', 'DOT', 'SOL'];
        $routeSignature = RouteSignature::fromNodes($nodes);

        $orderKey = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('2.500000000000000000')),
            9, // 10 nodes - 1 = 9 hops
            $routeSignature,
            12345,
        );

        $result = new PathResult(
            Money::fromString('USD', '1000.00', 2),
            Money::fromString('SOL', '50.00', 2),
            DecimalTolerance::fromNumericString('0.750000000000000000'),
        );

        $materialized = new MaterializedResult($result, $orderKey);

        self::assertCount(10, $materialized->orderKey()->routeSignature()->nodes());
        self::assertSame($nodes, $materialized->orderKey()->routeSignature()->nodes());
        self::assertSame(9, $materialized->orderKey()->hops());
        self::assertSame(12345, $materialized->orderKey()->insertionOrder());
    }

    public function test_it_preserves_immutability(): void
    {
        $result = new PathResult(
            Money::fromString('USD', '100.00', 2),
            Money::fromString('EUR', '90.00', 2),
            DecimalTolerance::fromNumericString('0.050000000000000000'),
        );

        $orderKey = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('1.111111111111111111')),
            1,
            RouteSignature::fromNodes(['USD', 'EUR']),
            0,
        );

        $materialized = new MaterializedResult($result, $orderKey);

        // Ensure the same data is returned on multiple calls (immutability)
        $result1 = $materialized->result();
        $result2 = $materialized->result();
        $orderKey1 = $materialized->orderKey();
        $orderKey2 = $materialized->orderKey();

        // Compare by properties, not object identity
        self::assertSame($result1->totalSpent()->amount(), $result->totalSpent()->amount());
        self::assertSame($result1->totalSpent()->currency(), $result->totalSpent()->currency());
        self::assertSame($result1->totalReceived()->amount(), $result->totalReceived()->amount());
        self::assertSame($result1->totalReceived()->currency(), $result->totalReceived()->currency());
        self::assertSame($result2->totalSpent()->amount(), $result->totalSpent()->amount());
        self::assertSame($orderKey1->cost()->value(), $orderKey->cost()->value());
        self::assertSame($orderKey1->hops(), $orderKey->hops());
        self::assertSame($orderKey2->hops(), $orderKey->hops());

        // Ensure toEntry() returns new instances but with same data
        $entry1 = $materialized->toEntry();
        $entry2 = $materialized->toEntry();

        self::assertNotSame($entry1, $entry2); // Different instances

        // Compare entry data by properties, not object identity
        $entry1Path = $entry1->path();
        $entry2Path = $entry2->path();
        $entry1OrderKey = $entry1->orderKey();
        $entry2OrderKey = $entry2->orderKey();

        self::assertSame($entry1Path->totalSpent()->amount(), $entry2Path->totalSpent()->amount());
        self::assertSame($entry1Path->totalSpent()->currency(), $entry2Path->totalSpent()->currency());
        self::assertSame($entry1Path->totalReceived()->amount(), $entry2Path->totalReceived()->amount());
        self::assertSame($entry1Path->totalReceived()->currency(), $entry2Path->totalReceived()->currency());
        self::assertSame($entry1OrderKey->cost()->value(), $entry2OrderKey->cost()->value());
        self::assertSame($entry1OrderKey->hops(), $entry2OrderKey->hops());

        // Ensure jsonSerialize() returns consistent data
        $json1 = $materialized->jsonSerialize();
        $json2 = $materialized->jsonSerialize();

        self::assertSame($json1, $json2);
        self::assertSame($result->jsonSerialize(), $json1);
    }

    public function test_it_handles_extreme_cost_values(): void
    {
        $testCases = [
            '0.000000000000000000', // Zero cost
            '0.000000000000000001', // Very small cost
            '999999999.999999999999999999', // Very large cost
        ];

        foreach ($testCases as $cost) {
            $orderKey = new PathOrderKey(
                new PathCost(DecimalFactory::decimal($cost)),
                1,
                RouteSignature::fromNodes(['A', 'B']),
                0,
            );

            $result = new PathResult(
                Money::fromString('USD', '100.00', 2),
                Money::fromString('EUR', '90.00', 2),
                DecimalTolerance::fromNumericString('0.050000000000000000'),
            );

            $materialized = new MaterializedResult($result, $orderKey);

            self::assertSame($cost, $materialized->orderKey()->cost()->value());
        }
    }

    public function test_to_entry_creates_correct_path_result_set_entry(): void
    {
        $result = new PathResult(
            Money::fromString('USD', '100.00', 2),
            Money::fromString('EUR', '90.00', 2),
            DecimalTolerance::fromNumericString('0.050000000000000000'),
        );

        $orderKey = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('1.111111111111111111')),
            1,
            RouteSignature::fromNodes(['USD', 'EUR']),
            42,
        );

        $materialized = new MaterializedResult($result, $orderKey);
        $entry = $materialized->toEntry();

        self::assertInstanceOf(PathResultSetEntry::class, $entry);
        self::assertSame($result, $entry->path());
        self::assertSame($orderKey, $entry->orderKey());
        self::assertSame($result->totalSpent(), $entry->path()->totalSpent());
        self::assertSame($result->totalReceived(), $entry->path()->totalReceived());
        self::assertSame($orderKey->cost(), $entry->orderKey()->cost());
        self::assertSame($orderKey->hops(), $entry->orderKey()->hops());
    }

    public function test_json_serialize_delegates_to_path_result(): void
    {
        $result = new PathResult(
            Money::fromString('USD', '100.00', 2),
            Money::fromString('EUR', '90.00', 2),
            DecimalTolerance::fromNumericString('0.050000000000000000'),
        );

        $orderKey = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('1.111111111111111111')),
            1,
            RouteSignature::fromNodes(['USD', 'EUR']),
            0,
        );

        $materialized = new MaterializedResult($result, $orderKey);

        $jsonData = $materialized->jsonSerialize();
        $resultJsonData = $result->jsonSerialize();

        self::assertSame($resultJsonData, $jsonData);
        self::assertIsArray($jsonData);
        self::assertArrayHasKey('totalSpent', $jsonData);
        self::assertArrayHasKey('totalReceived', $jsonData);
        self::assertArrayHasKey('residualTolerance', $jsonData);
        self::assertArrayHasKey('feeBreakdown', $jsonData);
        self::assertArrayHasKey('legs', $jsonData);
    }
}
