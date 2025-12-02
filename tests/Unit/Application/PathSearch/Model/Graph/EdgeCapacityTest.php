<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Model\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

#[CoversClass(EdgeCapacity::class)]
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

    public function test_min_getter_returns_correct_value(): void
    {
        $min = Money::fromString('EUR', '5.00', 2);
        $max = Money::fromString('EUR', '10.00', 2);
        $capacity = new EdgeCapacity($min, $max);

        self::assertTrue($capacity->min()->equals($min));
    }

    public function test_max_getter_returns_correct_value(): void
    {
        $min = Money::fromString('EUR', '5.00', 2);
        $max = Money::fromString('EUR', '10.00', 2);
        $capacity = new EdgeCapacity($min, $max);

        self::assertTrue($capacity->max()->equals($max));
    }

    public function test_getters_return_same_instances(): void
    {
        $min = Money::fromString('BTC', '0.00100000', 8);
        $max = Money::fromString('BTC', '0.00500000', 8);
        $capacity = new EdgeCapacity($min, $max);

        self::assertSame($min, $capacity->min());
        self::assertSame($max, $capacity->max());
    }

    public function test_accepts_different_scales_same_currency(): void
    {
        // Test that Money objects with different scales but same currency are accepted
        $min = Money::fromString('USD', '1.00', 2);
        $max = Money::fromString('USD', '2.5000', 4);

        $capacity = new EdgeCapacity($min, $max);

        self::assertTrue($capacity->min()->equals($min));
        self::assertTrue($capacity->max()->equals($max));
    }

    public function test_accepts_zero_minimum(): void
    {
        $min = Money::zero('EUR', 2);
        $max = Money::fromString('EUR', '100.00', 2);

        $capacity = new EdgeCapacity($min, $max);

        self::assertTrue($capacity->min()->isZero());
        self::assertTrue($capacity->max()->equals($max));
    }

    public function test_accepts_large_numbers(): void
    {
        $min = Money::fromString('BTC', '1000000.00000000', 8);
        $max = Money::fromString('BTC', '2000000.00000000', 8);

        $capacity = new EdgeCapacity($min, $max);

        self::assertTrue($capacity->min()->equals($min));
        self::assertTrue($capacity->max()->equals($max));
    }

    public function test_accepts_small_decimals(): void
    {
        $min = Money::fromString('ETH', '0.000000000000000001', 18);
        $max = Money::fromString('ETH', '0.000000000000000010', 18);

        $capacity = new EdgeCapacity($min, $max);

        self::assertTrue($capacity->min()->equals($min));
        self::assertTrue($capacity->max()->equals($max));
    }

    public function test_rejects_minimum_greater_than_maximum_with_different_scales(): void
    {
        // Test that min > max validation works even with different scales
        $min = Money::fromString('USD', '2.00', 2);
        $max = Money::fromString('USD', '1.000', 3);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Edge capacity minimum cannot exceed maximum.');

        new EdgeCapacity($min, $max);
    }
}
