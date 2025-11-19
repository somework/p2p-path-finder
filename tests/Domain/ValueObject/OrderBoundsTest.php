<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class OrderBoundsTest extends TestCase
{
    use MoneyAssertions;

    public function test_contains_checks_boundaries_inclusively(): void
    {
        $min = $this->money('USD', '10.00', 2);
        $max = $this->money('USD', '20.00', 2);

        $bounds = OrderBounds::from($min, $max);

        self::assertTrue($bounds->contains($this->money('USD', '10.00', 2)));
        self::assertTrue($bounds->contains($this->money('USD', '15.00', 2)));
        self::assertTrue($bounds->contains($this->money('USD', '20.00', 2)));
        self::assertFalse($bounds->contains($this->money('USD', '9.99', 2)));
        self::assertFalse($bounds->contains($this->money('USD', '20.01', 2)));
    }

    public function test_clamp_returns_nearest_bound(): void
    {
        $bounds = OrderBounds::from($this->money('EUR', '1.000', 3), $this->money('EUR', '2.000', 3));

        self::assertMoneyAmount($bounds->clamp($this->money('EUR', '0.500', 3)), '1.000', 3);
        self::assertMoneyAmount($bounds->clamp($this->money('EUR', '1.500', 3)), '1.500', 3);
        self::assertMoneyAmount($bounds->clamp($this->money('EUR', '5.000', 3)), '2.000', 3);
    }

    public function test_creation_with_inverted_bounds_fails(): void
    {
        $this->expectException(InvalidInput::class);
        OrderBounds::from($this->money('GBP', '5.00'), $this->money('GBP', '2.00'));
    }

    public function test_creation_with_currency_mismatch_fails(): void
    {
        $this->expectException(InvalidInput::class);
        OrderBounds::from($this->money('USD', '1.00'), $this->money('EUR', '2.00'));
    }

    public function test_contains_rejects_mismatched_currency(): void
    {
        $bounds = OrderBounds::from($this->money('USD', '10.00', 2), $this->money('USD', '20.00', 2));

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money currency must match order bounds.');

        $bounds->contains($this->money('EUR', '15.00', 2));
    }
}
