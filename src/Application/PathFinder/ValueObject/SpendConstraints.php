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

    public function min(): Money
    {
        return $this->range->min();
    }

    public function max(): Money
    {
        return $this->range->max();
    }

    public function desired(): ?Money
    {
        return $this->desired;
    }

    public function range(): SpendRange
    {
        return $this->range;
    }
}
