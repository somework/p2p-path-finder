<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Graph;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class EdgeCapacityTest extends TestCase
{
    public function test_allows_equal_bounds(): void
    {
        $min = Money::fromString('EUR', '1', 2);
        $max = Money::fromString('EUR', '1', 2);

        $capacity = new EdgeCapacity($min, $max);

        self::assertTrue($capacity->min()->equals($capacity->max()));
    }

    public function test_rejects_currency_mismatch(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Edge capacity bounds must share the same currency.');

        new EdgeCapacity(Money::zero('EUR', 2), Money::zero('USD', 2));
    }

    public function test_rejects_minimum_greater_than_maximum(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Edge capacity minimum cannot exceed maximum.');

        new EdgeCapacity(Money::fromString('EUR', '2', 2), Money::fromString('EUR', '1', 2));
    }

    public function test_json_serialization_includes_minimum_and_maximum(): void
    {
        $capacity = new EdgeCapacity(
            Money::fromString('USD', '1.00', 2),
            Money::fromString('USD', '2.50', 2),
        );

        self::assertSame(
            [
                'min' => ['currency' => 'USD', 'amount' => '1.00', 'scale' => 2],
                'max' => ['currency' => 'USD', 'amount' => '2.50', 'scale' => 2],
            ],
            $capacity->jsonSerialize(),
        );
    }
}
