<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine\Ordering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;

#[CoversClass(PathOrderKey::class)]
final class PathOrderKeyTest extends TestCase
{
    #[TestDox('Construction and all getters return correct values')]
    public function test_construction_and_all_getters_return_correct_values(): void
    {
        $cost = new PathCost('1.5');
        $routeSignature = RouteSignature::fromNodes(['USD', 'EUR']);
        $payload = ['strategy' => 'direct'];

        $key = new PathOrderKey($cost, 3, $routeSignature, 7, $payload);

        self::assertSame($cost->value(), $key->cost()->value());
        self::assertSame(3, $key->hops());
        self::assertSame($routeSignature->value(), $key->routeSignature()->value());
        self::assertSame(7, $key->insertionOrder());
        self::assertSame($payload, $key->payload());
    }

    #[TestDox('Default empty payload when not provided')]
    public function test_default_empty_payload_when_not_provided(): void
    {
        $key = new PathOrderKey(
            new PathCost('1.5'),
            2,
            RouteSignature::fromNodes(['USD', 'EUR']),
            1,
        );

        self::assertSame([], $key->payload());
    }

    #[TestDox('Custom payload is preserved')]
    public function test_custom_payload_is_preserved(): void
    {
        $payload = ['candidate' => ['cost' => '0.1'], 'rank' => 3];

        $key = new PathOrderKey(
            new PathCost('1.5'),
            2,
            RouteSignature::fromNodes(['USD', 'EUR']),
            1,
            $payload,
        );

        self::assertSame($payload, $key->payload());
    }
}
