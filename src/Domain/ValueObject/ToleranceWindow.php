<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use Brick\Math\BigDecimal;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

/**
 * Represents a normalized tolerance window with deterministic heuristics.
 */
final class ToleranceWindow
{
    use DecimalHelperTrait;

    /**
     * @see DecimalHelperTrait::CANONICAL_SCALE
     */
    private const SCALE = self::CANONICAL_SCALE;

    /**
     * @param 'minimum'|'maximum' $heuristicSource
     */
    private function __construct(
        private readonly BigDecimal $minimum,
        private readonly BigDecimal $maximum,
        private readonly BigDecimal $heuristicTolerance,
        private readonly string $heuristicSource,
    ) {
    }

    /**
     * @throws InvalidInput when either tolerance bound is invalid
     */
    public static function fromStrings(string $minimum, string $maximum): self
    {
        $normalizedMinimum = self::normalizeToleranceDecimal($minimum, 'Minimum tolerance');
        $normalizedMaximum = self::normalizeToleranceDecimal($maximum, 'Maximum tolerance');

        if ($normalizedMinimum->compareTo($normalizedMaximum) > 0) {
            throw new InvalidInput('Minimum tolerance must be less than or equal to maximum tolerance.');
        }

        if (0 === $normalizedMinimum->compareTo($normalizedMaximum)) {
            return new self($normalizedMinimum, $normalizedMaximum, $normalizedMinimum, 'minimum');
        }

        return new self($normalizedMinimum, $normalizedMaximum, $normalizedMaximum, 'maximum');
    }

    /**
     * Normalizes a tolerance value ensuring it lies within the [0, 1) interval.
     *
     * @throws InvalidInput when the provided value is invalid
     *
     * @return numeric-string
     */
    public static function normalizeTolerance(string $value, string $context): string
    {
        return self::decimalToString(self::normalizeToleranceDecimal($value, $context), self::SCALE);
    }

    /**
     * Returns the lower relative tolerance bound expressed as a fraction.
     *
     * @return numeric-string
     */
    public function minimum(): string
    {
        return self::decimalToString($this->minimum, self::SCALE);
    }

    /**
     * Returns the upper relative tolerance bound expressed as a fraction.
     *
     * @return numeric-string
     */
    public function maximum(): string
    {
        return self::decimalToString($this->maximum, self::SCALE);
    }

    /**
     * Returns the tolerance used by heuristic consumers when no override is supplied.
     *
     * @return numeric-string
     */
    public function heuristicTolerance(): string
    {
        return self::decimalToString($this->heuristicTolerance, self::SCALE);
    }

    /**
     * Returns the source of the heuristic tolerance value.
     *
     * @return 'minimum'|'maximum'
     */
    public function heuristicSource(): string
    {
        return $this->heuristicSource;
    }

    public static function scale(): int
    {
        return self::SCALE;
    }

    /**
     * @throws InvalidInput when the provided value is invalid
     */
    private static function normalizeToleranceDecimal(string $value, string $context): BigDecimal
    {
        $decimal = self::decimalFromString($value);
        $normalized = self::scaleDecimal($decimal, self::SCALE);

        if ($normalized->compareTo(BigDecimal::zero()) < 0 || $normalized->compareTo(BigDecimal::one()) >= 0) {
            throw new InvalidInput($context.' must be in the [0, 1) range.');
        }

        return $normalized;
    }
}
