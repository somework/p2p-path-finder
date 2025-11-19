<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function max;
use function sprintf;

final class PathCost
{
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
        $this->assertScale($scale);

        $comparisonScale = $scale < self::NORMALIZED_SCALE
            ? $scale
            : max($scale, self::NORMALIZED_SCALE);

        $left = self::scaleDecimal($this->decimal, $comparisonScale);
        $right = self::scaleDecimal($other->decimal, $comparisonScale);

        return $left->compareTo($right);
    }

    public function __toString(): string
    {
        return $this->value();
    }

    private static function decimalFromString(string $value): BigDecimal
    {
        try {
            return BigDecimal::of($value);
        } catch (MathException $exception) {
            throw new InvalidInput(sprintf('Value "%s" is not numeric.', $value), 0, $exception);
        }
    }

    private static function scaleDecimal(BigDecimal $decimal, int $scale): BigDecimal
    {
        self::assertScale($scale);

        return $decimal->toScale($scale, RoundingMode::HALF_UP);
    }

    /**
     * @return numeric-string
     */
    private static function decimalToString(BigDecimal $decimal, int $scale): string
    {
        /** @var numeric-string $value */
        $value = self::scaleDecimal($decimal, $scale)->__toString();

        return $value;
    }

    private static function assertScale(int $scale): void
    {
        if ($scale < 0) {
            throw new InvalidInput('Scale cannot be negative.');
        }
    }
}
