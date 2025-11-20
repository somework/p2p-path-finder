<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use Brick\Math\BigDecimal;
use JsonSerializable;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function max;

/**
 * Immutable representation of a tolerance ratio expressed as a decimal value between 0 and 1.
 */
final class DecimalTolerance implements JsonSerializable
{
    use DecimalHelperTrait;

    private const DEFAULT_SCALE = 18;

    private const PERCENT_MULTIPLIER = '100';

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

        $comparisonScale = max($this->scale, $scale ?? $this->scale, self::DEFAULT_SCALE);

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
     * @throws InvalidInput when the tolerance cannot be converted to a percentage
     *
     * @return numeric-string
     */
    public function percentage(int $scale = 2): string
    {
        self::assertScale($scale);

        $workingScale = max($this->scale, $scale) + 2;
        $product = self::scaleDecimal($this->decimal, $workingScale)
            ->multipliedBy(BigDecimal::of(self::PERCENT_MULTIPLIER));

        return self::decimalToString($product, $scale);
    }

    /**
     * @return numeric-string
     */
    public function jsonSerialize(): string
    {
        return $this->ratio();
    }
}
