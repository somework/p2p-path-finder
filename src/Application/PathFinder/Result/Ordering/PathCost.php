<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering;

use Brick\Math\BigDecimal;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalHelperTrait;

final class PathCost
{
    use DecimalHelperTrait;

    /**
     * Canonical scale for path cost storage and default comparisons.
     *
     * @see DecimalHelperTrait::CANONICAL_SCALE
     */
    private const NORMALIZED_SCALE = self::CANONICAL_SCALE;

    private readonly BigDecimal $decimal;

    /**
     * Creates a path cost from a numeric string or BigDecimal.
     *
     * The value is normalized to 18 decimal places using HALF_UP rounding,
     * ensuring consistent precision for all cost calculations and comparisons.
     *
     * @param numeric-string|BigDecimal $value The cost value to store
     */
    public function __construct(BigDecimal|string $value)
    {
        $decimal = $value instanceof BigDecimal ? $value : self::decimalFromString($value);

        $this->decimal = self::scaleDecimal($decimal, self::NORMALIZED_SCALE);
    }

    /**
     * Returns the cost as a canonical numeric string at 18 decimal places.
     *
     * @return numeric-string The cost (e.g., "1.234567890123456789")
     */
    public function value(): string
    {
        return self::decimalToString($this->decimal, self::NORMALIZED_SCALE);
    }

    /**
     * Returns the underlying BigDecimal representation at normalized scale.
     *
     * @return BigDecimal The cost as a BigDecimal at 18 decimal places
     */
    public function decimal(): BigDecimal
    {
        return $this->decimal;
    }

    /**
     * Checks if this cost equals another cost at full precision.
     *
     * @param self $other The cost to compare against
     *
     * @return bool True if costs are equal at normalized scale
     */
    public function equals(self $other): bool
    {
        return 0 === $this->decimal->compareTo($other->decimal);
    }

    /**
     * Compares this cost to another cost with optional scale control.
     *
     * Both costs are rescaled to the specified comparison scale using HALF_UP
     * rounding before comparison. Passing a scale smaller than NORMALIZED_SCALE
     * effectively rounds both costs down to that precision, which may cause
     * costs that differ only in lower-order digits to compare as equal.
     *
     * @param self $other The cost to compare against
     * @param int  $scale The decimal scale for comparison (default: 18)
     *
     * @return int -1 if this cost is less, 0 if equal, 1 if greater at the given scale
     */
    public function compare(self $other, int $scale = self::NORMALIZED_SCALE): int
    {
        self::assertScale($scale);

        $comparisonScale = $scale;

        $left = self::scaleDecimal($this->decimal, $comparisonScale);
        $right = self::scaleDecimal($other->decimal, $comparisonScale);

        return $left->compareTo($right);
    }

    public function __toString(): string
    {
        return $this->value();
    }
}
