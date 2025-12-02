<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine\Ordering;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalFactory;

final class PathOrderKeyTest extends TestCase
{
    public function test_it_exposes_all_components(): void
    {
        $payload = ['candidate' => ['cost' => '0.1']];
        $key = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('0.100000000000000000')),
            2,
            RouteSignature::fromNodes(['SRC', 'MID', 'DST']),
            17,
            $payload,
        );

        self::assertSame('0.100000000000000000', $key->cost()->value());
        self::assertSame(2, $key->hops());
        self::assertSame('SRC->MID->DST', (string) $key->routeSignature());
        self::assertSame(17, $key->insertionOrder());
        self::assertSame($payload, $key->payload());
    }

    public function test_immutability_preserves_original_payload(): void
    {
        $payload = ['data' => 'value'];
        $key = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('0.5')),
            1,
            RouteSignature::fromNodes(['A', 'B']),
            1,
            $payload,
        );

        // Accessing components shouldn't mutate
        $retrievedPayload1 = $key->payload();
        $retrievedPayload2 = $key->payload();

        self::assertSame($payload, $retrievedPayload1);
        self::assertSame($payload, $retrievedPayload2);
    }

    public function test_with_zero_hops(): void
    {
        $key = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('1.0')),
            0,
            RouteSignature::fromNodes(['SINGLE']),
            1,
            [],
        );

        self::assertSame(0, $key->hops());
        self::assertSame('SINGLE', (string) $key->routeSignature());
    }

    public function test_with_large_insertion_order(): void
    {
        $largeOrder = 999999999;
        $key = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('0.001')),
            3,
            RouteSignature::fromNodes(['X', 'Y', 'Z', 'W']),
            $largeOrder,
            ['test' => 'data'],
        );

        self::assertSame($largeOrder, $key->insertionOrder());
    }

    public function test_with_very_precise_cost(): void
    {
        $preciseCost = '0.123456789012345678'; // 18 decimals
        $key = new PathOrderKey(
            new PathCost(DecimalFactory::decimal($preciseCost)),
            2,
            RouteSignature::fromNodes(['A', 'B', 'C']),
            5,
            [],
        );

        self::assertSame($preciseCost, $key->cost()->value());
    }

    public function test_with_empty_payload(): void
    {
        $key = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('0.1')),
            1,
            RouteSignature::fromNodes(['SRC', 'DST']),
            1,
            [],
        );

        self::assertSame([], $key->payload());
    }
}
