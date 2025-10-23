<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

/**
 * Represents a normalized tolerance window with deterministic heuristics.
 */
final class ToleranceWindow
{
    /** @var numeric-string */
    private readonly string $minimum;

    /** @var numeric-string */
    private readonly string $maximum;

    /** @var numeric-string */
    private readonly string $heuristicTolerance;

    /** @var 'minimum'|'maximum' */
    private readonly string $heuristicSource;

    /**
     * @param numeric-string      $minimum
     * @param numeric-string      $maximum
     * @param numeric-string      $heuristicTolerance
     * @param 'minimum'|'maximum' $heuristicSource
     */
    private function __construct(string $minimum, string $maximum, string $heuristicTolerance, string $heuristicSource)
    {
        $this->minimum = $minimum;
        $this->maximum = $maximum;
        $this->heuristicTolerance = $heuristicTolerance;
        $this->heuristicSource = $heuristicSource;
    }

    /**
     * @throws InvalidInput|PrecisionViolation when either tolerance bound is invalid
     */
    public static function fromStrings(string $minimum, string $maximum): self
    {
        $normalizedMinimum = self::normalizeTolerance($minimum, 'Minimum tolerance');
        $normalizedMaximum = self::normalizeTolerance($maximum, 'Maximum tolerance');

        if (BcMath::comp($normalizedMinimum, $normalizedMaximum, self::SCALE) > 0) {
            throw new InvalidInput('Minimum tolerance must be less than or equal to maximum tolerance.');
        }

        if (0 === BcMath::comp($normalizedMinimum, $normalizedMaximum, self::SCALE)) {
            return new self($normalizedMinimum, $normalizedMaximum, $normalizedMinimum, 'minimum');
        }

        return new self($normalizedMinimum, $normalizedMaximum, $normalizedMaximum, 'maximum');
    }

    /**
     * Normalizes a tolerance value ensuring it lies within the [0, 1) interval.
     *
     * @throws InvalidInput|PrecisionViolation when the provided value is invalid
     *
     * @return numeric-string
     */
    public static function normalizeTolerance(string $value, string $context): string
    {
        BcMath::ensureNumeric($value);
        /** @var numeric-string $value */
        $normalized = BcMath::normalize($value, self::SCALE);

        if (BcMath::comp($normalized, '0', self::SCALE) < 0 || BcMath::comp($normalized, '1', self::SCALE) >= 0) {
            throw new InvalidInput($context.' must be in the [0, 1) range.');
        }

        return $normalized;
    }

    /**
     * Returns the lower relative tolerance bound expressed as a fraction.
     *
     * @return numeric-string
     */
    public function minimum(): string
    {
        return $this->minimum;
    }

    /**
     * Returns the upper relative tolerance bound expressed as a fraction.
     *
     * @return numeric-string
     */
    public function maximum(): string
    {
        return $this->maximum;
    }

    /**
     * Returns the tolerance used by heuristic consumers when no override is supplied.
     *
     * @return numeric-string
     */
    public function heuristicTolerance(): string
    {
        return $this->heuristicTolerance;
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

    private const SCALE = 18;
}
