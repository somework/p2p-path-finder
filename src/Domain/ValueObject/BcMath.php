<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use InvalidArgumentException;

use function sprintf;
use function strlen;

/**
 * Thin wrappers around BCMath functions that provide input validation and
 * consistent scale handling for value objects.
 */
final class BcMath
{
    private const DEFAULT_SCALE = 8;

    private function __construct()
    {
    }

    public static function ensureNumeric(string $value): void
    {
        if (!self::isNumeric($value)) {
            throw new InvalidArgumentException(sprintf('Value "%s" is not numeric.', $value));
        }
    }

    public static function isNumeric(string $value): bool
    {
        if ('' === $value) {
            return false;
        }

        return (bool) preg_match('/^-?\d+(\.\d+)?$/', $value);
    }

    public static function normalize(string $value, int $scale): string
    {
        self::ensureScale($scale);
        self::ensureNumeric($value);

        return self::round($value, $scale);
    }

    public static function add(string $left, string $right, int $scale): string
    {
        self::ensureScale($scale);

        return bcadd($left, $right, $scale);
    }

    public static function sub(string $left, string $right, int $scale): string
    {
        self::ensureScale($scale);

        return bcsub($left, $right, $scale);
    }

    public static function mul(string $left, string $right, int $scale): string
    {
        self::ensureScale($scale);

        return bcmul($left, $right, $scale);
    }

    public static function div(string $left, string $right, int $scale): string
    {
        self::ensureScale($scale);
        self::ensureNonZero($right);

        return bcdiv($left, $right, $scale);
    }

    public static function comp(string $left, string $right, int $scale): int
    {
        self::ensureScale($scale);

        return bccomp($left, $right, $scale);
    }

    public static function round(string $value, int $scale): string
    {
        self::ensureScale($scale);
        self::ensureNumeric($value);

        if (0 === $scale) {
            $increment = '' !== $value && '-' === $value[0] ? '-0.5' : '0.5';
            $rounded = bcadd($value, $increment, 1);

            return bcadd($rounded, '0', 0);
        }

        $increment = '0.'.str_repeat('0', $scale).'5';
        if ('' !== $value && '-' === $value[0]) {
            $increment = '-'.$increment;
        }

        $adjusted = bcadd($value, $increment, $scale + 1);

        return bcadd($adjusted, '0', $scale);
    }

    public static function scaleForComparison(string $first, string $second, int $fallbackScale = self::DEFAULT_SCALE): int
    {
        $scale = max(self::scaleOf($first), self::scaleOf($second), $fallbackScale);

        return $scale;
    }

    private static function scaleOf(string $value): int
    {
        $value = ltrim($value, '+-');
        $decimalPosition = strpos($value, '.');
        if (false === $decimalPosition) {
            return 0;
        }

        $fractional = rtrim(substr($value, $decimalPosition + 1), '0');

        return strlen($fractional);
    }

    private static function ensureScale(int $scale): void
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('Scale cannot be negative.');
        }
    }

    private static function ensureNonZero(string $value): void
    {
        self::ensureNumeric($value);
        if (0 === bccomp($value, '0', max(self::scaleOf($value), 0))) {
            throw new InvalidArgumentException('Division by zero.');
        }
    }
}
