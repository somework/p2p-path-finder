<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Support\DecimalMath;

use function sprintf;

/**
 * Shared helpers for asserting canonical Money representations within domain tests.
 */
trait MoneyAssertions
{
    /**
     * @param non-empty-string $amount
     */
    private static function assertMoneyAmount(Money $money, string $amount, int $scale): void
    {
        self::assertSame($amount, $money->amount());
        self::assertSame($scale, $money->scale());
        self::assertTrue(
            DecimalMath::decimal($amount, $scale)->isEqualTo($money->decimal()),
            sprintf('Expected %s at scale %d, received %s at scale %d.', $amount, $scale, $money->amount(), $money->scale()),
        );
    }

    private static function money(string $currency, string $amount, int $scale = 2): Money
    {
        return Money::fromString($currency, $amount, $scale);
    }
}
