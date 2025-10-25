<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering;

use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;

final class PathCost
{
    private const NORMALIZED_SCALE = 18;

    /**
     * @var numeric-string
     */
    private readonly string $value;

    /**
     * @param numeric-string $value
     */
    public function __construct(string $value)
    {
        $this->value = BcMath::normalize($value, self::NORMALIZED_SCALE);
    }

    /**
     * @return numeric-string
     */
    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function compare(self $other, int $scale = self::NORMALIZED_SCALE): int
    {
        if ($scale < self::NORMALIZED_SCALE) {
            $left = BcMath::round($this->value, $scale);
            $right = BcMath::round($other->value, $scale);

            return BcMath::comp($left, $right, $scale);
        }

        return BcMath::comp($this->value, $other->value, $scale);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
