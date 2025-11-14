<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use SomeWork\P2PPathFinder\Domain\Math\DecimalMathInterface;
use SomeWork\P2PPathFinder\Internal\Math\BcMathDecimalMath;

/**
 * Legacy static facade over the configured decimal math strategy.
 */
final class BcMath
{
    public const DEFAULT_SCALE = DecimalMathInterface::DEFAULT_SCALE;

    private static ?DecimalMathInterface $decimalMath = null;

    private function __construct()
    {
    }

    /**
     * @internal tests may swap implementations when simulating edge cases
     */
    public static function useDecimalMath(?DecimalMathInterface $decimalMath): void
    {
        self::$decimalMath = $decimalMath;
    }

    /**
     * @phpstan-assert numeric-string $values
     *
     * @psalm-assert numeric-string ...$values
     */
    public static function ensureNumeric(string ...$values): void
    {
        self::decimalMath()->ensureNumeric(...$values);
    }

    public static function isNumeric(string $value): bool
    {
        return self::decimalMath()->isNumeric($value);
    }

    /**
     * @param numeric-string $value
     *
     * @return numeric-string
     */
    public static function normalize(string $value, int $scale): string
    {
        return self::decimalMath()->normalize($value, $scale);
    }

    /**
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @return numeric-string
     */
    public static function add(string $left, string $right, int $scale): string
    {
        return self::decimalMath()->add($left, $right, $scale);
    }

    /**
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @return numeric-string
     */
    public static function sub(string $left, string $right, int $scale): string
    {
        return self::decimalMath()->sub($left, $right, $scale);
    }

    /**
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @return numeric-string
     */
    public static function mul(string $left, string $right, int $scale): string
    {
        return self::decimalMath()->mul($left, $right, $scale);
    }

    /**
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @return numeric-string
     */
    public static function div(string $left, string $right, int $scale): string
    {
        return self::decimalMath()->div($left, $right, $scale);
    }

    /**
     * @param numeric-string $left
     * @param numeric-string $right
     */
    public static function comp(string $left, string $right, int $scale): int
    {
        return self::decimalMath()->comp($left, $right, $scale);
    }

    /**
     * @param numeric-string $value
     *
     * @return numeric-string
     */
    public static function round(string $value, int $scale): string
    {
        return self::decimalMath()->round($value, $scale);
    }

    /**
     * @param numeric-string $first
     * @param numeric-string $second
     */
    public static function scaleForComparison(string $first, string $second, int $fallbackScale = self::DEFAULT_SCALE): int
    {
        return self::decimalMath()->scaleForComparison($first, $second, $fallbackScale);
    }

    private static function decimalMath(): DecimalMathInterface
    {
        return self::$decimalMath ??= new BcMathDecimalMath();
    }
}
