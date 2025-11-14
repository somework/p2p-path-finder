<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use SomeWork\P2PPathFinder\Domain\Math\DecimalMathInterface;
use SomeWork\P2PPathFinder\Internal\Math\BcMathDecimalMath;

/**
 * Provides decimal math adapters for value object construction without relying on global state.
 */
final class MathAdapterFactory
{
    public static function default(): DecimalMathInterface
    {
        return new BcMathDecimalMath();
    }

    public static function resolve(?DecimalMathInterface $math): DecimalMathInterface
    {
        return $math ?? self::default();
    }
}
