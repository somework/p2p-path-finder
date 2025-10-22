<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use JsonSerializable;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function max;

/**
 * Immutable representation of a tolerance ratio expressed as a decimal value between 0 and 1.
 */
final class DecimalTolerance implements JsonSerializable
{
    private const DEFAULT_SCALE = 18;

    private const PERCENT_MULTIPLIER = '100';

    /**
     * @var numeric-string
     */
    private readonly string $ratio;

    private readonly int $scale;

    /**
     * @param numeric-string $ratio
     */
    private function __construct(string $ratio, int $scale)
    {
        $this->ratio = $ratio;
        $this->scale = $scale;
    }

    /**
     * @param numeric-string $ratio
     *
     * @throws InvalidInput|PrecisionViolation when the ratio or scale fall outside the supported range
     */
    public static function fromNumericString(string $ratio, ?int $scale = null): self
    {
        $scale ??= self::DEFAULT_SCALE;
        self::assertScale($scale);

        BcMath::ensureNumeric($ratio);

        $normalized = BcMath::normalize($ratio, $scale);
        $comparisonScale = max($scale, self::DEFAULT_SCALE);

        if (BcMath::comp($normalized, '0', $comparisonScale) < 0 || BcMath::comp($normalized, '1', $comparisonScale) > 0) {
            throw new InvalidInput('Residual tolerance must be a value between 0 and 1 inclusive.');
        }

        return new self($normalized, $scale);
    }

    public static function zero(): self
    {
        return new self(BcMath::normalize('0', self::DEFAULT_SCALE), self::DEFAULT_SCALE);
    }

    /**
     * @return numeric-string
     */
    public function ratio(): string
    {
        return $this->ratio;
    }

    public function scale(): int
    {
        return $this->scale;
    }

    public function isZero(): bool
    {
        return 0 === BcMath::comp($this->ratio, '0', $this->scale);
    }

    /**
     * @param numeric-string $value
     *
     * @throws InvalidInput|PrecisionViolation when the value cannot be normalized for comparison
     */
    public function compare(string $value, ?int $scale = null): int
    {
        if (null !== $scale) {
            self::assertScale($scale);
        }

        $comparisonScale = max($this->scale, $scale ?? $this->scale, self::DEFAULT_SCALE);

        $normalized = BcMath::normalize($value, $comparisonScale);

        return BcMath::comp($this->ratio, $normalized, $comparisonScale);
    }

    /**
     * @param numeric-string $value
     *
     * @throws InvalidInput|PrecisionViolation when the value cannot be compared using BCMath
     */
    public function isGreaterThanOrEqual(string $value, ?int $scale = null): bool
    {
        return $this->compare($value, $scale) >= 0;
    }

    /**
     * @param numeric-string $value
     *
     * @throws InvalidInput|PrecisionViolation when the value cannot be compared using BCMath
     */
    public function isLessThanOrEqual(string $value, ?int $scale = null): bool
    {
        return $this->compare($value, $scale) <= 0;
    }

    /**
     * @throws InvalidInput|PrecisionViolation when the tolerance cannot be converted to a percentage
     *
     * @return numeric-string
     */
    public function percentage(int $scale = 2): string
    {
        self::assertScale($scale);

        $workingScale = max($this->scale, $scale) + 2;
        $product = BcMath::mul($this->ratio, self::PERCENT_MULTIPLIER, $workingScale);

        return BcMath::normalize($product, $scale);
    }

    /**
     * @return numeric-string
     */
    public function jsonSerialize(): string
    {
        return $this->ratio;
    }

    private static function assertScale(int $scale): void
    {
        if ($scale < 0) {
            throw new InvalidInput('Scale must be a non-negative integer.');
        }
    }
}
