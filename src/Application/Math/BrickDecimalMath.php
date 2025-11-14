<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Math;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Domain\Math\DecimalMathInterface;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function ltrim;
use function max;
use function preg_match;
use function rtrim;
use function sprintf;
use function strlen;
use function strpos;
use function substr;

/**
 * Decimal math implementation backed by brick/math's arbitrary precision decimals.
 */
final class BrickDecimalMath implements DecimalMathInterface
{
    public const DEFAULT_SCALE = DecimalMathInterface::DEFAULT_SCALE;

    public function ensureNumeric(string ...$values): void
    {
        foreach ($values as $value) {
            if (!$this->isNumeric($value)) {
                throw new InvalidInput(sprintf('Value "%s" is not numeric.', $value));
            }
        }
    }

    public function isNumeric(string $value): bool
    {
        if ('' === $value) {
            return false;
        }

        return (bool) preg_match('/^-?\d+(\.\d+)?$/', $value);
    }

    public function normalize(string $value, int $scale): string
    {
        $this->ensureScale($scale);
        $this->ensureNumeric($value);

        return $this->round($value, $scale);
    }

    public function add(string $left, string $right, int $scale): string
    {
        $this->ensureScale($scale);
        $this->ensureNumeric($left, $right);

        $result = $this->bigDecimal($left)->plus($this->bigDecimal($right));
        $workingScale = $this->workingScaleForAddition($left, $right, $scale);
        $rounded = $result->toScale($workingScale, RoundingMode::HALF_UP);

        return $this->roundDecimal($rounded, $scale);
    }

    public function sub(string $left, string $right, int $scale): string
    {
        $this->ensureScale($scale);
        $this->ensureNumeric($left, $right);

        $result = $this->bigDecimal($left)->minus($this->bigDecimal($right));
        $workingScale = $this->workingScaleForAddition($left, $right, $scale);
        $rounded = $result->toScale($workingScale, RoundingMode::HALF_UP);

        return $this->roundDecimal($rounded, $scale);
    }

    public function mul(string $left, string $right, int $scale): string
    {
        $this->ensureScale($scale);
        $this->ensureNumeric($left, $right);

        $result = $this->bigDecimal($left)->multipliedBy($this->bigDecimal($right));
        $workingScale = $this->workingScaleForMultiplication($left, $right, $scale);
        $rounded = $result->toScale($workingScale, RoundingMode::HALF_UP);

        return $this->roundDecimal($rounded, $scale);
    }

    public function div(string $left, string $right, int $scale): string
    {
        $this->ensureScale($scale);
        $this->ensureNumeric($left, $right);
        $this->ensureNonZero($right);

        $workingScale = $this->workingScaleForDivision($left, $right, $scale);
        $result = $this->bigDecimal($left)->dividedBy($this->bigDecimal($right), $workingScale, RoundingMode::HALF_UP);

        return $this->roundDecimal($result, $scale);
    }

    public function comp(string $left, string $right, int $scale): int
    {
        $this->ensureScale($scale);
        $this->ensureNumeric($left, $right);

        $comparisonScale = $this->workingScaleForComparison($left, $right, $scale);
        $leftDecimal = $this->bigDecimal($left)->toScale($comparisonScale, RoundingMode::HALF_UP);
        $rightDecimal = $this->bigDecimal($right)->toScale($comparisonScale, RoundingMode::HALF_UP);

        return $leftDecimal->compareTo($rightDecimal);
    }

    public function round(string $value, int $scale): string
    {
        $this->ensureScale($scale);
        $this->ensureNumeric($value);

        $decimal = $this->bigDecimal($value)->toScale($scale, RoundingMode::HALF_UP);

        /** @var numeric-string $result */
        $result = $decimal->__toString();

        return $result;
    }

    public function scaleForComparison(string $first, string $second, int $fallbackScale = self::DEFAULT_SCALE): int
    {
        return $this->workingScaleForComparison($first, $second, $fallbackScale);
    }

    private function bigDecimal(string $value): BigDecimal
    {
        return BigDecimal::of($value);
    }

    private function ensureScale(int $scale): void
    {
        if ($scale < 0) {
            throw new InvalidInput('Scale cannot be negative.');
        }
    }

    private function ensureNonZero(string $value): void
    {
        if ($this->bigDecimal($value)->isZero()) {
            throw new InvalidInput('Division by zero.');
        }
    }

    private function scaleOf(string $value): int
    {
        $value = ltrim($value, '+-');
        $decimalPosition = strpos($value, '.');
        if (false === $decimalPosition) {
            return 0;
        }

        $fractional = rtrim(substr($value, $decimalPosition + 1), '0');

        return strlen($fractional);
    }

    /**
     * @return numeric-string
     */
    private function roundDecimal(BigDecimal $decimal, int $scale): string
    {
        /** @var numeric-string $result */
        $result = $decimal->toScale($scale, RoundingMode::HALF_UP)->__toString();

        return $result;
    }

    private function workingScaleForAddition(string $left, string $right, int $scale): int
    {
        return max($scale, $this->scaleOf($left), $this->scaleOf($right));
    }

    private function workingScaleForMultiplication(string $left, string $right, int $scale): int
    {
        $fractionalDigits = $this->scaleOf($left) + $this->scaleOf($right);

        return max($scale + $fractionalDigits, $fractionalDigits);
    }

    private function workingScaleForDivision(string $left, string $right, int $scale): int
    {
        $fractionalLeft = $this->scaleOf($left);
        $fractionalRight = max($this->scaleOf($right), 1);

        return max($scale + $fractionalLeft + $fractionalRight, $fractionalLeft + $fractionalRight);
    }

    private function workingScaleForComparison(string $left, string $right, int $scale): int
    {
        return max($scale, $this->scaleOf($left), $this->scaleOf($right));
    }
}
