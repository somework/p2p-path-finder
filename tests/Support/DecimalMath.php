<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function preg_match;
use function sprintf;

/**
 * Test helper exposing deterministic decimal math operations for fixtures and assertions.
 */
final class DecimalMath
{
    public const DEFAULT_SCALE = 18;

    private function __construct()
    {
    }

    /**
     * @phpstan-assert numeric-string ...$values
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

        return self::bigDecimal($left)->plus(self::bigDecimal($right))->toScale($scale, RoundingMode::HALF_UP)->__toString();
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

        return self::bigDecimal($left)->minus(self::bigDecimal($right))->toScale($scale, RoundingMode::HALF_UP)->__toString();
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

        return self::bigDecimal($left)->multipliedBy(self::bigDecimal($right))->toScale($scale, RoundingMode::HALF_UP)->__toString();
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

        return self::decimal($left, $scale)->dividedBy(self::decimal($right, $scale), $scale, RoundingMode::HALF_UP)->__toString();
    }

    /**
     * @param numeric-string $left
     * @param numeric-string $right
     */
    public static function comp(string $left, string $right, int $scale): int
    {
        self::ensureScale($scale);
        self::ensureNumeric($left, $right);

        return self::decimal($left, $scale)->compareTo(self::decimal($right, $scale));
    }

    /**
     * @param numeric-string $value
     *
     * @return numeric-string
     */
    private static function round(string $value, int $scale): string
    {
        // For tests expecting canonical 18-digit strings, we use HALF_UP.
        // For tests that expect stripped trailing zeros, we handle that separately if needed,
        // but currently the failures suggest we need full padding.
        return self::bigDecimal($value)->toScale($scale, RoundingMode::HALF_UP)->__toString();
    }

    private static function bigDecimal(string $value): BigDecimal
    {
        return BigDecimal::of($value);
    }

    private static function ensureScale(int $scale): void
    {
        if ($scale < 0) {
            throw new InvalidInput('Scale must be a non-negative integer.');
        }
    }
}
