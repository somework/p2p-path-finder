<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use Brick\Math\BigDecimal;
use JsonSerializable;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function max;

/**
 * Immutable representation of a tolerance ratio expressed as a decimal value between 0 and 1.
 *
 * @api
 */
final class DecimalTolerance implements JsonSerializable
{
    use DecimalHelperTrait;

    /**
     * @see DecimalHelperTrait::CANONICAL_SCALE
     */
    private const DEFAULT_SCALE = self::CANONICAL_SCALE;

    /**
     * Multiplier to convert decimal ratio (0.0-1.0) to percentage (0-100).
     */
    private const PERCENT_MULTIPLIER = '100';

    /**
     * Extra precision digits used during percentage calculation to guard against
     * intermediate rounding loss before scaling to the final percentage scale.
     */
    private const PERCENT_WORKING_SCALE_EXTRA = 2;

    private function __construct(
        private readonly BigDecimal $decimal,
        private readonly int $scale,
    ) {
    }

    /**
     * @param numeric-string $ratio
     *
     * @throws InvalidInput when the ratio or scale fall outside the supported range
     */
    public static function fromNumericString(string $ratio, ?int $scale = null): self
    {
        $scale ??= self::DEFAULT_SCALE;
        self::assertScale($scale);

        $normalized = self::scaleDecimal(self::decimalFromString($ratio), $scale);

        // Range check after scaling: values that round to [0, 1] at the target scale are accepted.
        // For example, "1.0000000000000000001" would round to "1.000000000000000000" and pass.
        if ($normalized->compareTo(BigDecimal::zero()) < 0 || $normalized->compareTo(BigDecimal::one()) > 0) {
            throw new InvalidInput('Residual tolerance must be a value between 0 and 1 inclusive.');
        }

        return new self($normalized, $scale);
    }

    public static function zero(): self
    {
        return new self(self::scaleDecimal(BigDecimal::zero(), self::DEFAULT_SCALE), self::DEFAULT_SCALE);
    }

    /**
     * @return numeric-string
     */
    public function ratio(): string
    {
        return self::decimalToString($this->decimal, $this->scale);
    }

    public function scale(): int
    {
        return $this->scale;
    }

    public function isZero(): bool
    {
        return $this->decimal->isZero();
    }

    /**
     * @param numeric-string $value
     *
     * @throws InvalidInput when the value cannot be normalized for comparison
     */
    public function compare(string $value, ?int $scale = null): int
    {
        if (null !== $scale) {
            self::assertScale($scale);
        }

        $comparisonScale = max($scale ?? $this->scale, self::DEFAULT_SCALE);

        $left = self::scaleDecimal($this->decimal, $comparisonScale);
        $right = self::scaleDecimal(self::decimalFromString($value), $comparisonScale);

        return $left->compareTo($right);
    }

    /**
     * @param numeric-string $value
     *
     * @throws InvalidInput when the value cannot be compared using arbitrary-precision decimals
     */
    public function isGreaterThanOrEqual(string $value, ?int $scale = null): bool
    {
        return $this->compare($value, $scale) >= 0;
    }

    /**
     * @param numeric-string $value
     *
     * @throws InvalidInput when the value cannot be compared using arbitrary-precision decimals
     */
    public function isLessThanOrEqual(string $value, ?int $scale = null): bool
    {
        return $this->compare($value, $scale) <= 0;
    }

    /**
     * Converts the tolerance ratio to a percentage string.
     *
     * Uses extra working precision during multiplication to minimize rounding loss
     * before scaling to the requested percentage scale with HALF_UP rounding.
     *
     * @param int $scale The number of decimal places for the percentage result (default: 2)
     *
     * @throws InvalidInput when the scale is negative
     *
     * @return numeric-string The tolerance as a percentage (e.g., "5.00" for 0.05 ratio at scale 2)
     */
    public function percentage(int $scale = 2): string
    {
        self::assertScale($scale);

        $workingScale = max($this->scale, $scale) + self::PERCENT_WORKING_SCALE_EXTRA;
        $product = self::scaleDecimal($this->decimal, $workingScale)
            ->multipliedBy(BigDecimal::of(self::PERCENT_MULTIPLIER));

        return self::decimalToString($product, $scale);
    }

    /**
     * Returns the canonical numeric-string representation for JSON serialization.
     *
     * @return numeric-string The tolerance ratio (e.g., "0.050000000000000000" at scale 18)
     */
    public function jsonSerialize(): string
    {
        return $this->ratio();
    }
}
