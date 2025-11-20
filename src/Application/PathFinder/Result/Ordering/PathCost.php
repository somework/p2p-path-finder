<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering;

use Brick\Math\BigDecimal;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalHelperTrait;

final class PathCost
{
    use DecimalHelperTrait;

    private const NORMALIZED_SCALE = 18;

    private readonly BigDecimal $decimal;

    /**
     * @param numeric-string|BigDecimal $value
     */
    public function __construct(BigDecimal|string $value)
    {
        $decimal = $value instanceof BigDecimal ? $value : self::decimalFromString($value);

        $this->decimal = self::scaleDecimal($decimal, self::NORMALIZED_SCALE);
    }

    /**
     * @return numeric-string
     */
    public function value(): string
    {
        return self::decimalToString($this->decimal, self::NORMALIZED_SCALE);
    }

    public function decimal(): BigDecimal
    {
        return $this->decimal;
    }

    public function equals(self $other): bool
    {
        return 0 === $this->decimal->compareTo($other->decimal);
    }

    public function compare(self $other, int $scale = self::NORMALIZED_SCALE): int
    {
        self::assertScale($scale);

        $comparisonScale = $scale;

        $left = self::scaleDecimal($this->decimal, $comparisonScale);
        $right = self::scaleDecimal($other->decimal, $comparisonScale);

        return $left->compareTo($right);
    }

    public function __toString(): string
    {
        return $this->value();
    }
}
