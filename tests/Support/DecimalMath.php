<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function ltrim;
use function max;
use function preg_match;
use function rtrim;
use function sprintf;
use function strlen;
use function strpos;
use function substr;

/**
 * Test helper exposing deterministic decimal math operations for fixtures and assertions.
 */
final class DecimalMath
{
    public const DEFAULT_SCALE = 8;

    private function __construct()
    {
    }

    /**
     * @phpstan-assert numeric-string $values
     *
     * @psalm-assert numeric-string ...$values
     */
    public static function ensureNumeric(string ...$values): void
    {
        foreach ($values as $value) {
            if (!self::isNumeric($value)) {
                throw new InvalidInput(sprintf('Value "%s" is not numeric.', $value));
            }
        }
    }

    public static function isNumeric(string $value): bool
    {
        if ('' === $value) {
            return false;
        }

        return (bool) preg_match('/^-?\d+(\.\d+)?$/', $value);
    }

    /**
     * @param numeric-string $value
     *
     * @return numeric-string
     */
    public static function normalize(string $value, int $scale): string
    {
        self::ensureScale($scale);
        self::ensureNumeric($value);

        return self::round($value, $scale);
    }

    /**
     * @param numeric-string $value
     */
    public static function decimal(string $value, int $scale): BigDecimal
    {
        self::ensureScale($scale);
        self::ensureNumeric($value);

        return self::bigDecimal($value)->toScale($scale, RoundingMode::HALF_UP);
    }

    /**
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @return numeric-string
     */
    public static function add(string $left, string $right, int $scale): string
    {
        self::ensureScale($scale);
        self::ensureNumeric($left, $right);

        $result = self::bigDecimal($left)->plus(self::bigDecimal($right));
        $workingScale = self::workingScaleForAddition($left, $right, $scale);
        $rounded = $result->toScale($workingScale, RoundingMode::HALF_UP);

        return self::roundDecimal($rounded, $scale);
    }

    /**
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @return numeric-string
     */
    public static function sub(string $left, string $right, int $scale): string
    {
        self::ensureScale($scale);
        self::ensureNumeric($left, $right);

        $result = self::bigDecimal($left)->minus(self::bigDecimal($right));
        $workingScale = self::workingScaleForAddition($left, $right, $scale);
        $rounded = $result->toScale($workingScale, RoundingMode::HALF_UP);

        return self::roundDecimal($rounded, $scale);
    }

    /**
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @return numeric-string
     */
    public static function mul(string $left, string $right, int $scale): string
    {
        self::ensureScale($scale);
        self::ensureNumeric($left, $right);

        $result = self::bigDecimal($left)->multipliedBy(self::bigDecimal($right));
        $workingScale = self::workingScaleForMultiplication($left, $right, $scale);
        $rounded = $result->toScale($workingScale, RoundingMode::HALF_UP);

        return self::roundDecimal($rounded, $scale);
    }

    /**
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @return numeric-string
     */
    public static function div(string $left, string $right, int $scale): string
    {
        self::ensureScale($scale);
        self::ensureNumeric($left, $right);
        self::ensureNonZero($right);

        $workingScale = self::workingScaleForDivision($left, $right, $scale);
        $result = self::bigDecimal($left)->dividedBy(self::bigDecimal($right), $workingScale, RoundingMode::HALF_UP);

        return self::roundDecimal($result, $scale);
    }

    public static function comp(string $left, string $right, int $scale): int
    {
        self::ensureScale($scale);
        self::ensureNumeric($left, $right);

        $comparisonScale = self::workingScaleForComparison($left, $right, $scale);
        $leftDecimal = self::bigDecimal($left)->toScale($comparisonScale, RoundingMode::HALF_UP);
        $rightDecimal = self::bigDecimal($right)->toScale($comparisonScale, RoundingMode::HALF_UP);

        return $leftDecimal->compareTo($rightDecimal);
    }

    /**
     * @param numeric-string $value
     *
     * @return numeric-string
     */
    public static function round(string $value, int $scale): string
    {
        self::ensureScale($scale);
        self::ensureNumeric($value);

        $decimal = self::bigDecimal($value)->toScale($scale, RoundingMode::HALF_UP);

        /** @var numeric-string $result */
        $result = $decimal->__toString();

        return $result;
    }

    public static function scaleForComparison(string $first, string $second, int $fallbackScale = self::DEFAULT_SCALE): int
    {
        return self::workingScaleForComparison($first, $second, $fallbackScale);
    }

    private static function bigDecimal(string $value): BigDecimal
    {
        return BigDecimal::of($value);
    }

    private static function ensureScale(int $scale): void
    {
        if ($scale < 0) {
            throw new InvalidInput('Scale cannot be negative.');
        }
    }

    private static function ensureNonZero(string $value): void
    {
        if (self::bigDecimal($value)->isZero()) {
            throw new InvalidInput('Division by zero.');
        }
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

    /**
     * @return numeric-string
     */
    private static function roundDecimal(BigDecimal $decimal, int $scale): string
    {
        /** @var numeric-string $result */
        $result = $decimal->toScale($scale, RoundingMode::HALF_UP)->__toString();

        return $result;
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
