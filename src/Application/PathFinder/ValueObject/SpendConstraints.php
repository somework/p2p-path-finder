<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\ValueObject;

use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

/**
 * Represents the spend boundaries propagated through the search graph.
 */
final class SpendConstraints
{
    private readonly Money $min;
    private readonly Money $max;
    private readonly ?Money $desired;

    private function __construct(Money $min, Money $max, ?Money $desired)
    {
        $this->min = $min;
        $this->max = $max;
        $this->desired = $desired;
    }

    /**
     * @throws InvalidInput|PrecisionViolation
     */
    public static function from(Money $min, Money $max, ?Money $desired = null): self
    {
        self::assertCurrencyConsistency($min, $max, $desired);

        $scale = max($min->scale(), $max->scale(), $desired?->scale() ?? 0);
        $normalizedMin = $min->withScale($scale);
        $normalizedMax = $max->withScale($scale);

        if ($normalizedMin->greaterThan($normalizedMax)) {
            [$normalizedMin, $normalizedMax] = [$normalizedMax, $normalizedMin];
        }

        $normalizedDesired = null === $desired ? null : $desired->withScale($scale);

        return new self($normalizedMin, $normalizedMax, $normalizedDesired);
    }

    public function min(): Money
    {
        return $this->min;
    }

    public function max(): Money
    {
        return $this->max;
    }

    public function desired(): ?Money
    {
        return $this->desired;
    }

    /**
     * @return array{min: Money, max: Money}
     */
    public function toRange(): array
    {
        return ['min' => $this->min, 'max' => $this->max];
    }

    private static function assertCurrencyConsistency(Money $min, Money $max, ?Money $desired): void
    {
        if ($min->currency() !== $max->currency()) {
            throw new InvalidInput('Spend constraint bounds must share the same currency.');
        }

        if (null !== $desired && $desired->currency() !== $min->currency()) {
            throw new InvalidInput('Desired spend must use the same currency as the spend bounds.');
        }
    }
}
