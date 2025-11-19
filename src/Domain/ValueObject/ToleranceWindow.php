<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function sprintf;

/**
 * Represents a normalized tolerance window with deterministic heuristics.
 */
final class ToleranceWindow
{
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
     * @throws InvalidInput|PrecisionViolation when either tolerance bound is invalid
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
     * @throws InvalidInput|PrecisionViolation when the provided value is invalid
     *
     * @return numeric-string
     */
    public static function normalizeTolerance(string $value, string $context): string
    {
        return self::decimalToString(self::normalizeToleranceDecimal($value, $context));
    }

    /**
     * Returns the lower relative tolerance bound expressed as a fraction.
     *
     * @return numeric-string
     */
    public function minimum(): string
    {
        return self::decimalToString($this->minimum);
    }

    /**
     * Returns the upper relative tolerance bound expressed as a fraction.
     *
     * @return numeric-string
     */
    public function maximum(): string
    {
        return self::decimalToString($this->maximum);
    }

    /**
     * Returns the tolerance used by heuristic consumers when no override is supplied.
     *
     * @return numeric-string
     */
    public function heuristicTolerance(): string
    {
        return self::decimalToString($this->heuristicTolerance);
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

    /**
     * @throws InvalidInput|PrecisionViolation when the provided value is invalid
     */
    private static function normalizeToleranceDecimal(string $value, string $context): BigDecimal
    {
        $decimal = self::decimalFromString($value);
        $normalized = self::scaleDecimal($decimal);

        if ($normalized->compareTo(BigDecimal::zero()) < 0 || $normalized->compareTo(BigDecimal::one()) >= 0) {
            throw new InvalidInput($context.' must be in the [0, 1) range.');
        }

        return $normalized;
    }

    private static function decimalFromString(string $value): BigDecimal
    {
        try {
            return BigDecimal::of($value);
        } catch (MathException $exception) {
            throw new InvalidInput(sprintf('Value "%s" is not numeric.', $value), 0, $exception);
        }
    }

    private static function scaleDecimal(BigDecimal $decimal): BigDecimal
    {
        return $decimal->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * @return numeric-string
     */
    private static function decimalToString(BigDecimal $decimal): string
    {
        /** @var numeric-string $result */
        $result = self::scaleDecimal($decimal)->__toString();

        return $result;
    }
}
