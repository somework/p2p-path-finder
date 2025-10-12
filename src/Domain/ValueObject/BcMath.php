<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use InvalidArgumentException;

use function bcadd;
use function bccomp;
use function bcdiv;
use function bcmul;
use function bcsub;
use function ltrim;
use function max;
use function preg_match;
use function rtrim;
use function sprintf;
use function strlen;
use function strpos;
use function substr;

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

    /**
     * Asserts that all provided string values represent numeric quantities.
     *
     * @throws InvalidArgumentException when at least one value is not numeric
     */
    public static function ensureNumeric(string ...$values): void
    {
        foreach ($values as $value) {
            if (!self::isNumeric($value)) {
                throw new InvalidArgumentException(sprintf('Value "%s" is not numeric.', $value));
            }
        }
    }

    /**
     * Checks whether the provided string represents a numeric value compatible with BCMath.
     */
    public static function isNumeric(string $value): bool
    {
        if ('' === $value) {
            return false;
        }

        return (bool) preg_match('/^-?\d+(\.\d+)?$/', $value);
    }

    /**
     * Normalizes a numeric string to the provided scale using bankers rounding.
     */
    public static function normalize(string $value, int $scale): string
    {
        self::ensureScale($scale);
        self::ensureNumeric($value);

        return self::round($value, $scale);
    }

    /**
     * Adds two numeric strings while maintaining deterministic scale handling.
     */
    public static function add(string $left, string $right, int $scale): string
    {
        self::ensureScale($scale);
        self::ensureNumeric($left, $right);

        $workingScale = self::workingScaleForAddition($left, $right, $scale);

        $result = bcadd($left, $right, $workingScale);

        return self::round($result, $scale);
    }

    /**
     * Subtracts the right operand from the left operand while preserving scale.
     */
    public static function sub(string $left, string $right, int $scale): string
    {
        self::ensureScale($scale);
        self::ensureNumeric($left, $right);

        $workingScale = self::workingScaleForAddition($left, $right, $scale);

        $result = bcsub($left, $right, $workingScale);

        return self::round($result, $scale);
    }

    /**
     * Multiplies two numeric strings with deterministic rounding behaviour.
     */
    public static function mul(string $left, string $right, int $scale): string
    {
        self::ensureScale($scale);
        self::ensureNumeric($left, $right);

        $workingScale = self::workingScaleForMultiplication($left, $right, $scale);

        $result = bcmul($left, $right, $workingScale);

        return self::round($result, $scale);
    }

    /**
     * Divides the left operand by the right operand while guarding against division by zero.
     */
    public static function div(string $left, string $right, int $scale): string
    {
        self::ensureScale($scale);
        self::ensureNumeric($left, $right);
        self::ensureNonZero($right);

        $workingScale = self::workingScaleForDivision($left, $right, $scale);

        $result = bcdiv($left, $right, $workingScale);

        return self::round($result, $scale);
    }

    /**
     * Compares two numeric strings at the requested precision level.
     */
    public static function comp(string $left, string $right, int $scale): int
    {
        self::ensureScale($scale);
        self::ensureNumeric($left, $right);

        $comparisonScale = self::workingScaleForComparison($left, $right, $scale);

        return bccomp($left, $right, $comparisonScale);
    }

    /**
     * Rounds a numeric string to the provided scale using half-up semantics.
     */
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

    /**
     * Determines the scale required to safely compare the provided operands.
     */
    public static function scaleForComparison(string $first, string $second, int $fallbackScale = self::DEFAULT_SCALE): int
    {
        return self::workingScaleForComparison($first, $second, $fallbackScale);
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
        $scale = max(self::scaleOf($value), 1);

        if (0 === bccomp($value, '0', $scale)) {
            throw new InvalidArgumentException('Division by zero.');
        }
    }

    private static function workingScaleForAddition(string $left, string $right, int $scale): int
    {
        return max($scale, self::scaleOf($left), self::scaleOf($right));
    }

    private static function workingScaleForMultiplication(string $left, string $right, int $scale): int
    {
        $fractionalDigits = self::scaleOf($left) + self::scaleOf($right);

        return max($scale + $fractionalDigits, $fractionalDigits);
    }

    private static function workingScaleForDivision(string $left, string $right, int $scale): int
    {
        $fractionalLeft = self::scaleOf($left);
        $fractionalRight = max(self::scaleOf($right), 1);

        return max($scale + $fractionalLeft + $fractionalRight, $fractionalLeft + $fractionalRight);
    }

    private static function workingScaleForComparison(string $left, string $right, int $scale): int
    {
        return max($scale, self::scaleOf($left), self::scaleOf($right));
    }
}
