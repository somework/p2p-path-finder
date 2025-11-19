<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Provides convenient helpers for constructing scaled BigDecimal fixtures.
 */
final class DecimalFactory
{
    private function __construct()
    {
    }

    public static function decimal(string $value, int $scale = 18): BigDecimal
    {
        return BigDecimal::of($value)->toScale($scale, RoundingMode::HALF_UP);
    }

    public static function zero(int $scale = 18): BigDecimal
    {
        return self::decimal('0', $scale);
    }

    public static function unit(int $scale = 18): BigDecimal
    {
        return self::decimal('1', $scale);
    }
}
