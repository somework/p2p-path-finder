<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\ValueObject;

use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalHelperTrait;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function str_starts_with;

/**
 * Represents the spend boundaries propagated through the search graph.
 */
final class SpendConstraints
{
    /**
     * @see DecimalHelperTrait::CANONICAL_SCALE
     */
    private const SCALAR_SCALE = 18;

    private readonly SpendRange $range;
    private readonly ?Money $desired;

    private function __construct(SpendRange $range, ?Money $desired)
    {
        $this->range = $range;
        $this->desired = $desired;
    }

    /**
     * @throws InvalidInput|PrecisionViolation
     */
    public static function from(Money $min, Money $max, ?Money $desired = null): self
    {
        $range = SpendRange::fromBounds($min, $max);

        $normalizedDesired = null;
        if (null !== $desired) {
            if ($desired->currency() !== $range->currency()) {
                throw new InvalidInput('Desired spend must use the same currency as the spend bounds.');
            }

            $scale = max($range->scale(), $desired->scale());
            $range = $range->withScale($scale);
            $normalizedDesired = $desired->withScale($scale);
        }

        return new self($range, $normalizedDesired);
    }

    /**
     * @param numeric-string      $min
     * @param numeric-string      $max
     * @param numeric-string|null $desired
     *
     * @throws InvalidInput|PrecisionViolation
     */
    public static function fromScalars(string $currency, string $min, string $max, ?string $desired = null): self
    {
        $lowerBound = Money::fromString($currency, $min, self::SCALAR_SCALE);
        $upperBound = Money::fromString($currency, $max, self::SCALAR_SCALE);

        $desiredAmount = null;
        if (null !== $desired) {
            $desiredAmount = Money::fromString($currency, $desired, self::SCALAR_SCALE);
        }

        foreach ([$lowerBound, $upperBound, $desiredAmount] as $value) {
            if (null !== $value && str_starts_with($value->amount(), '-')) {
                throw new InvalidInput('Spend constraints cannot contain negative values.');
            }
        }

        return self::from($lowerBound, $upperBound, $desiredAmount);
    }

    public function min(): Money
    {
        return $this->range->min();
    }

    public function max(): Money
    {
        return $this->range->max();
    }

    /**
     * @return array{min: Money, max: Money}
     */
    public function bounds(): array
    {
        return [
            'min' => $this->range->min(),
            'max' => $this->range->max(),
        ];
    }

    public function desired(): ?Money
    {
        return $this->desired;
    }

    /**
     * @internal
     */
    public function internalRange(): SpendRange
    {
        return $this->range;
    }
}
