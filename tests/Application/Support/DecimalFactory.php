<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalHelperTrait;

/**
 * Provides convenient helpers for constructing scaled BigDecimal fixtures.
 */
final class DecimalFactory
{
    /**
     * @see DecimalHelperTrait::CANONICAL_SCALE
     */
    private const CANONICAL_SCALE = 18;

    private function __construct()
    {
    }

    public static function decimal(string $value, int $scale = self::CANONICAL_SCALE): BigDecimal
    {
        return BigDecimal::of($value)->toScale($scale, RoundingMode::HALF_UP);
    }

    public static function zero(int $scale = self::CANONICAL_SCALE): BigDecimal
    {
        return self::decimal('0', $scale);
    }

    public static function unit(int $scale = self::CANONICAL_SCALE): BigDecimal
    {
        return self::decimal('1', $scale);
    }
}
