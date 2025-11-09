<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Result\Ordering;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;

final class PathOrderKeyTest extends TestCase
{
    public function test_it_exposes_all_components(): void
    {
        $payload = ['candidate' => ['cost' => '0.1']];
        $key = new PathOrderKey(
            new PathCost('0.100000000000000000'),
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
}
