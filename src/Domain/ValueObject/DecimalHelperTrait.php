<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function sprintf;

/**
 * Shared helpers for BigDecimal operations with canonical scale handling.
 *
 * This trait provides consistent decimal parsing, scaling, and serialization
 * across value objects and services. All methods use HALF_UP rounding and
 * preserve fixed scales without stripping trailing zeros, ensuring deterministic
 * string representations for assertions and persistence.
 *
 * Canonicalization policy:
 * - Scale validation ensures non-negative integers only
 * - String parsing uses BigDecimal::of() with InvalidInput on failure
 * - Scaling always applies HALF_UP rounding to the specified scale
 * - String output preserves trailing zeros (e.g., "1.500000000000000000" at scale 18)
 *
 * @internal
 */
trait DecimalHelperTrait
{
    /**
     * Canonical scale for high-precision decimal operations across the application.
     *
     * This scale (18 decimal places) is used for:
     * - Path finding cost/product calculations
     * - Exchange rate conversions
     * - Tolerance comparisons
     * - Test fixtures and assertions
     *
     * Using a consistent scale ensures deterministic rounding behavior and
     * prevents subtle precision mismatches across different components.
     */
    private const CANONICAL_SCALE = 18;

    /**
     * Maximum allowed scale to prevent memory exhaustion and performance degradation.
     *
     * Setting an upper bound (50 decimal places) protects against accidental or malicious
     * requests for extremely high precision that could consume excessive resources.
     */
    private const MAX_SCALE = 50;

    /**
     * @psalm-mutation-free
     */
    private static function assertScale(int $scale): void
    {
        if ($scale < 0) {
            throw new InvalidInput('Scale must be a non-negative integer.');
        }
        if ($scale > self::MAX_SCALE) {
            throw new InvalidInput(sprintf('Scale cannot exceed %d decimal places.', self::MAX_SCALE));
        }
    }

    private static function decimalFromString(string $value): BigDecimal
    {
        try {
            return BigDecimal::of($value);
        } catch (MathException $exception) {
            throw new InvalidInput(sprintf('Value "%s" is not numeric.', $value), 0, $exception);
        }
    }

    /**
     * @psalm-mutation-free
     *
     * @psalm-suppress ImpureMethodCall BigDecimal is immutable, toScale returns a new instance
     */
    private static function scaleDecimal(BigDecimal $decimal, int $scale): BigDecimal
    {
        self::assertScale($scale);

        return $decimal->toScale($scale, RoundingMode::HalfUp);
    }

    /**
     * @psalm-mutation-free
     *
     * @psalm-suppress ImpureMethodCall BigDecimal is immutable, __toString returns string representation
     *
     * @return numeric-string
     */
    private static function decimalToString(BigDecimal $decimal, int $scale): string
    {
        /** @var numeric-string $result */
        $result = self::scaleDecimal($decimal, $scale)->__toString();

        return $result;
    }
}
