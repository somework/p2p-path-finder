<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class OrderBoundsTest extends TestCase
{
    public function test_contains_checks_boundaries_inclusively(): void
    {
        $min = Money::fromString('USD', '10.00', 2);
        $max = Money::fromString('USD', '20.00', 2);

        $bounds = OrderBounds::from($min, $max);

        self::assertTrue($bounds->contains(Money::fromString('USD', '10.00', 2)));
        self::assertTrue($bounds->contains(Money::fromString('USD', '15.00', 2)));
        self::assertTrue($bounds->contains(Money::fromString('USD', '20.00', 2)));
        self::assertFalse($bounds->contains(Money::fromString('USD', '9.99', 2)));
        self::assertFalse($bounds->contains(Money::fromString('USD', '20.01', 2)));
    }

    public function test_clamp_returns_nearest_bound(): void
    {
        $bounds = OrderBounds::from(Money::fromString('EUR', '1.000', 3), Money::fromString('EUR', '2.000', 3));

        self::assertSame('1.000', $bounds->clamp(Money::fromString('EUR', '0.500', 3))->amount());
        self::assertSame('1.500', $bounds->clamp(Money::fromString('EUR', '1.500', 3))->amount());
        self::assertSame('2.000', $bounds->clamp(Money::fromString('EUR', '5.000', 3))->amount());
    }

    public function test_creation_with_inverted_bounds_fails(): void
    {
        $this->expectException(InvalidInput::class);
        OrderBounds::from(Money::fromString('GBP', '5.00'), Money::fromString('GBP', '2.00'));
    }

    public function test_creation_with_currency_mismatch_fails(): void
    {
        $this->expectException(InvalidInput::class);
        OrderBounds::from(Money::fromString('USD', '1.00'), Money::fromString('EUR', '2.00'));
    }
}
