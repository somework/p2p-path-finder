<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use Closure;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function bcadd;
use function bccomp;
use function bcdiv;
use function bcmul;
use function bcsub;
use function extension_loaded;
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

    private static bool $extensionVerified = false;

    /**
     * @var (Closure(string):bool)|null
     *
     * @phpstan-var (Closure(string):bool)|null
     *
     * @psalm-var (Closure(string):bool)|null
     */
    private static ?Closure $extensionDetector = null;

    private function __construct()
    {
    }

    /**
     * Asserts that all provided string values represent numeric quantities.
     *
     * @phpstan-assert numeric-string $values
     *
     * @psalm-assert numeric-string $values
     *
     * @throws InvalidInput when at least one value is not numeric
     */
    public static function ensureNumeric(string ...$values): void
    {
        foreach ($values as $value) {
            if (!self::isNumeric($value)) {
                throw new InvalidInput(sprintf('Value "%s" is not numeric.', $value));
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
     * Normalizes a numeric string to the provided scale using half-up rounding.
     *
     * The path finder normalizes tolerances and costs to 18 decimal places by default,
     * so half-up rounding keeps deterministic behaviour even for tie-breaking cases.
     *
     * @param numeric-string $value
     *
     * @throws InvalidInput|PrecisionViolation when the provided value cannot be normalized at the requested scale
     *
     * @return numeric-string
     */
    public static function normalize(string $value, int $scale): string
    {
        self::ensureExtensionAvailable();
        self::ensureScale($scale);
        self::ensureNumeric($value);

        return self::round($value, $scale);
    }

    /**
     * Adds two numeric strings while maintaining deterministic scale handling.
     *
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @throws InvalidInput|PrecisionViolation when operands or the requested scale are invalid
     *
     * @return numeric-string
     */
    public static function add(string $left, string $right, int $scale): string
    {
        self::ensureExtensionAvailable();
        self::ensureScale($scale);
        self::ensureNumeric($left, $right);

        $workingScale = self::workingScaleForAddition($left, $right, $scale);

        $result = bcadd($left, $right, $workingScale);

        return self::round($result, $scale);
    }

    /**
     * Subtracts the right operand from the left operand while preserving scale.
     *
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @throws InvalidInput|PrecisionViolation when operands or the requested scale are invalid
     *
     * @return numeric-string
     */
    public static function sub(string $left, string $right, int $scale): string
    {
        self::ensureExtensionAvailable();
        self::ensureScale($scale);
        self::ensureNumeric($left, $right);

        $workingScale = self::workingScaleForAddition($left, $right, $scale);

        $result = bcsub($left, $right, $workingScale);

        return self::round($result, $scale);
    }

    /**
     * Multiplies two numeric strings with deterministic rounding behaviour.
     *
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @throws InvalidInput|PrecisionViolation when operands or the requested scale are invalid
     *
     * @return numeric-string
     */
    public static function mul(string $left, string $right, int $scale): string
    {
        self::ensureExtensionAvailable();
        self::ensureScale($scale);
        self::ensureNumeric($left, $right);

        $workingScale = self::workingScaleForMultiplication($left, $right, $scale);

        $result = bcmul($left, $right, $workingScale);

        return self::round($result, $scale);
    }

    /**
     * Divides the left operand by the right operand while guarding against division by zero.
     *
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @throws InvalidInput|PrecisionViolation when operands, scale or divisor are invalid
     *
     * @return numeric-string
     */
    public static function div(string $left, string $right, int $scale): string
    {
        self::ensureExtensionAvailable();
        self::ensureScale($scale);
        self::ensureNumeric($left, $right);
        self::ensureNonZero($right);

        $workingScale = self::workingScaleForDivision($left, $right, $scale);

        $result = bcdiv($left, $right, $workingScale);

        return self::round($result, $scale);
    }

    /**
     * Compares two numeric strings at the requested precision level.
     *
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @throws InvalidInput|PrecisionViolation when operands or scale validation fails
     */
    public static function comp(string $left, string $right, int $scale): int
    {
        self::ensureExtensionAvailable();
        self::ensureScale($scale);
        self::ensureNumeric($left, $right);

        $comparisonScale = self::workingScaleForComparison($left, $right, $scale);

        return bccomp($left, $right, $comparisonScale);
    }

    /**
     * Rounds a numeric string to the provided scale using half-up semantics.
     *
     * @param numeric-string $value
     *
     * @throws InvalidInput|PrecisionViolation when the provided value cannot be rounded at the requested scale
     *
     * @return numeric-string
     */
    public static function round(string $value, int $scale): string
    {
        self::ensureExtensionAvailable();
        self::ensureScale($scale);
        self::ensureNumeric($value);

        if (0 === $scale) {
            /** @var numeric-string $increment */
            $increment = '-' === $value[0] ? '-0.5' : '0.5';
            self::ensureNumeric($increment);
            /** @var numeric-string $rounded */
            $rounded = bcadd($value, $increment, 1);

            /** @var numeric-string $result */
            $result = bcadd($rounded, '0', 0);

            return $result;
        }

        /** @var numeric-string $increment */
        $increment = '0.'.str_repeat('0', $scale).'5';
        if ('-' === $value[0]) {
            $increment = '-'.$increment;
        }

        self::ensureNumeric($increment);

        /** @var numeric-string $adjusted */
        $adjusted = bcadd($value, $increment, $scale + 1);

        /** @var numeric-string $result */
        $result = bcadd($adjusted, '0', $scale);

        return $result;
    }

    /**
     * Determines the scale required to safely compare the provided operands.
     *
     * @param numeric-string $first
     * @param numeric-string $second
     *
     * @throws PrecisionViolation when the BCMath extension is unavailable
     */
    public static function scaleForComparison(string $first, string $second, int $fallbackScale = self::DEFAULT_SCALE): int
    {
        self::ensureExtensionAvailable();

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
            throw new InvalidInput('Scale cannot be negative.');
        }
    }

    private static function ensureNonZero(string $value): void
    {
        self::ensureExtensionAvailable();
        self::ensureNumeric($value);
        $scale = max(self::scaleOf($value), 1);

        if (0 === bccomp($value, '0', $scale)) {
            throw new InvalidInput('Division by zero.');
        }
    }

    private static function ensureExtensionAvailable(): void
    {
        if (self::$extensionVerified) {
            return;
        }

        if (!self::extensionLoaded('bcmath')) {
            throw new PrecisionViolation('The BCMath extension (ext-bcmath) is required. Install it or require symfony/polyfill-bcmath when the extension cannot be loaded.');
        }

        self::$extensionVerified = true;
    }

    private static function extensionLoaded(string $extension): bool
    {
        if (null !== self::$extensionDetector) {
            /** @var Closure(string):bool $detector */
            $detector = self::$extensionDetector;

            return $detector($extension);
        }

        return extension_loaded($extension);
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
