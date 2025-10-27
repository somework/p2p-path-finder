<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\ValueObject;

use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function array_key_exists;
use function gettype;
use function is_array;
use function sprintf;

/**
 * Immutable representation of spend bounds propagated through the search graph.
 */
final class SpendRange
{
    private readonly Money $min;
    private readonly Money $max;

    /**
     * @throws InvalidInput|PrecisionViolation
     */
    private function __construct(Money $min, Money $max)
    {
        if ($min->currency() !== $max->currency()) {
            throw new InvalidInput('Spend ranges require matching currencies.');
        }

        $scale = max($min->scale(), $max->scale());
        $normalizedMin = $min->withScale($scale);
        $normalizedMax = $max->withScale($scale);

        if ($normalizedMin->greaterThan($normalizedMax)) {
            [$normalizedMin, $normalizedMax] = [$normalizedMax, $normalizedMin];
        }

        $this->min = $normalizedMin;
        $this->max = $normalizedMax;
    }

    /**
     * @throws InvalidInput|PrecisionViolation
     */
    public static function fromBounds(Money $min, Money $max): self
    {
        return new self($min, $max);
    }

    /**
     * @param array{min: Money, max: Money} $range
     *
     * @throws InvalidInput|PrecisionViolation
     */
    public static function fromArray(array $range): self
    {
        self::assertArrayShape($range);

        return new self($range['min'], $range['max']);
    }

    /**
     * @param array<string, mixed> $range
     *
     * @throws InvalidInput
     */
    private static function assertArrayShape(array $range): void
    {
        foreach (['min', 'max'] as $key) {
            if (!array_key_exists($key, $range)) {
                throw new InvalidInput(sprintf('Spend ranges require a "%s" key.', $key));
            }

            if (!$range[$key] instanceof Money) {
                throw new InvalidInput(sprintf('Spend range bounds must be Money instances, "%s" given for "%s".', is_array($range[$key]) ? 'array' : gettype($range[$key]), $key));
            }
        }

        /** @var array{min: Money, max: Money} $range */
        $range = $range;

        if ($range['min']->currency() !== $range['max']->currency()) {
            throw new InvalidInput('Spend ranges require matching currencies.');
        }
    }

    public function min(): Money
    {
        return $this->min;
    }

    public function max(): Money
    {
        return $this->max;
    }

    public function currency(): string
    {
        return $this->min->currency();
    }

    public function scale(): int
    {
        return max($this->min->scale(), $this->max->scale());
    }

    /**
     * @throws InvalidInput|PrecisionViolation
     */
    public function withScale(int $scale): self
    {
        $targetScale = max($scale, $this->scale());

        return new self(
            $this->min->withScale($targetScale),
            $this->max->withScale($targetScale),
        );
    }

    /**
     * @throws InvalidInput|PrecisionViolation
     */
    public function normalizeWith(Money ...$values): self
    {
        $scale = $this->scale();

        foreach ($values as $value) {
            $this->assertCurrency($value);
            $scale = max($scale, $value->scale());
        }

        return $this->withScale($scale);
    }

    /**
     * @throws InvalidInput|PrecisionViolation
     */
    public function clamp(Money $value): Money
    {
        $this->assertCurrency($value);

        $scale = max($this->scale(), $value->scale());
        $range = $this->withScale($scale);
        $normalizedValue = $value->withScale($scale);

        if ($normalizedValue->lessThan($range->min)) {
            return $range->min;
        }

        if ($normalizedValue->greaterThan($range->max)) {
            return $range->max;
        }

        return $normalizedValue;
    }

    /**
     * @return array{min: Money, max: Money}
     */
    public function toBoundsArray(): array
    {
        return ['min' => $this->min, 'max' => $this->max];
    }

    /**
     * @throws InvalidInput
     */
    private function assertCurrency(Money $value): void
    {
        if ($value->currency() !== $this->currency()) {
            throw new InvalidInput('Spend range operations require matching currencies.');
        }
    }
}
