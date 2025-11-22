<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\PHPStan\Tests;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

/**
 * Example file demonstrating what the custom PHPStan rules should catch.
 *
 * This file contains intentional violations for testing purposes.
 * Run PHPStan on this file to verify the custom rules are working.
 *
 * @internal This file is for testing PHPStan rules only
 */
final class RulesTestExamples
{
    /**
     * ❌ BAD: Float literal in arithmetic (should be caught by FloatLiteralInArithmeticRule).
     */
    public function badFloatArithmetic(): float
    {
        // @phpstan-ignore-next-line - Intentional violation for testing
        return 10.5 + 20.3; // Should trigger: Float literal in arithmetic
    }

    /**
     * ❌ BAD: Float literal passed to Money method (should be caught by FloatLiteralInArithmeticRule).
     */
    public function badMoneyMultiplication(): Money
    {
        $money = Money::fromString('USD', '100.00', 2);
        // @phpstan-ignore-next-line - Intentional violation for testing
        return $money->multiply(1.5); // Should trigger: Float literal passed to multiply()
    }

    /**
     * ❌ BAD: Missing RoundingMode (should be caught by MissingRoundingModeRule).
     */
    public function badMissingRoundingMode(): BigDecimal
    {
        $value = BigDecimal::of('10.123456');
        // @phpstan-ignore-next-line - Intentional violation for testing
        return $value->toScale(2); // Should trigger: Missing RoundingMode parameter
    }

    /**
     * ❌ BAD: BCMath function call (should be caught by BCMathFunctionCallRule).
     */
    public function badBCMathUsage(): string
    {
        // @phpstan-ignore-next-line - Intentional violation for testing
        return bcadd('10.5', '20.3', 2); // Should trigger: BCMath function prohibited
    }

    /**
     * ✅ GOOD: Proper BigDecimal usage with RoundingMode.
     */
    public function goodBigDecimalUsage(): BigDecimal
    {
        $value = BigDecimal::of('10.123456');
        return $value->toScale(2, RoundingMode::HALF_UP); // ✓ Correct
    }

    /**
     * ✅ GOOD: Proper Money arithmetic with numeric strings.
     */
    public function goodMoneyUsage(): Money
    {
        $money = Money::fromString('USD', '100.00', 2);
        return $money->multiply('1.5'); // ✓ Correct: string literal
    }

    /**
     * ✅ GOOD: Float literal in time calculation (allowed context).
     */
    public function goodTimeCalculation(): float
    {
        $startedAt = microtime(true);
        $now = microtime(true);
        $elapsedMilliseconds = ($now - $startedAt) * 1000.0; // ✓ Allowed: time conversion

        return $elapsedMilliseconds;
    }

    /**
     * ✅ GOOD: Float literal in guard comparison (allowed context).
     */
    public function goodGuardComparison(float $elapsedMilliseconds): bool
    {
        if ($elapsedMilliseconds < 0.0) { // ✓ Allowed: guard validation
            $elapsedMilliseconds = 0.0;
        }

        return $elapsedMilliseconds >= 0.0;
    }
}

