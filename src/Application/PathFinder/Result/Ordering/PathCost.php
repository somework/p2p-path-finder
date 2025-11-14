<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering;

use SomeWork\P2PPathFinder\Domain\Math\DecimalMathInterface;
use SomeWork\P2PPathFinder\Domain\ValueObject\MathAdapterFactory;

final class PathCost
{
    private const NORMALIZED_SCALE = 18;

    /**
     * @var numeric-string
     */
    private readonly string $value;

    private readonly DecimalMathInterface $math;

    /**
     * @param numeric-string $value
     */
    public function __construct(string $value, ?DecimalMathInterface $math = null)
    {
        $this->math = MathAdapterFactory::resolve($math);
        $this->value = $this->math->normalize($value, self::NORMALIZED_SCALE);
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
            $left = $this->math->round($this->value, $scale);
            $right = $this->math->round($other->value, $scale);

            return $this->math->comp($left, $right, $scale);
        }

        return $this->math->comp($this->value, $other->value, $scale);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function math(): DecimalMathInterface
    {
        return $this->math;
    }
}
