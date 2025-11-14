<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Internal\Math;

use Closure;
use SomeWork\P2PPathFinder\Domain\Math\DecimalMathInterface;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function bcadd;
use function bccomp;
use function bcdiv;
use function bcmul;
use function bcsub;
use function extension_loaded;
use function ltrim;
use function max;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_repeat;
use function strlen;
use function strpos;
use function substr;

/**
 * Decimal math implementation backed by the native BCMath extension.
 */
final class BcMathDecimalMath implements DecimalMathInterface
{
    public const DEFAULT_SCALE = DecimalMathInterface::DEFAULT_SCALE;

    private bool $extensionVerified = false;

    /**
     * @var (Closure(string):bool)|null
     *
     * @phpstan-var (Closure(string):bool)|null
     *
     * @psalm-var (Closure(string):bool)|null
     */
    private ?Closure $extensionDetector = null;

    /**
     * @phpstan-assert numeric-string $values
     *
     * @psalm-assert numeric-string ...$values
     */
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
        $this->ensureExtensionAvailable();
        $this->ensureScale($scale);
        $this->ensureNumeric($value);

        return $this->round($value, $scale);
    }

    public function add(string $left, string $right, int $scale): string
    {
        $this->ensureExtensionAvailable();
        $this->ensureScale($scale);
        $this->ensureNumeric($left, $right);

        $workingScale = $this->workingScaleForAddition($left, $right, $scale);

        $result = bcadd($left, $right, $workingScale);

        return $this->round($result, $scale);
    }

    public function sub(string $left, string $right, int $scale): string
    {
        $this->ensureExtensionAvailable();
        $this->ensureScale($scale);
        $this->ensureNumeric($left, $right);

        $workingScale = $this->workingScaleForAddition($left, $right, $scale);

        $result = bcsub($left, $right, $workingScale);

        return $this->round($result, $scale);
    }

    public function mul(string $left, string $right, int $scale): string
    {
        $this->ensureExtensionAvailable();
        $this->ensureScale($scale);
        $this->ensureNumeric($left, $right);

        $workingScale = $this->workingScaleForMultiplication($left, $right, $scale);

        $result = bcmul($left, $right, $workingScale);

        return $this->round($result, $scale);
    }

    public function div(string $left, string $right, int $scale): string
    {
        $this->ensureExtensionAvailable();
        $this->ensureScale($scale);
        $this->ensureNumeric($left, $right);
        $this->ensureNonZero($right);

        $workingScale = $this->workingScaleForDivision($left, $right, $scale);

        $result = bcdiv($left, $right, $workingScale);

        return $this->round($result, $scale);
    }

    public function comp(string $left, string $right, int $scale): int
    {
        $this->ensureExtensionAvailable();
        $this->ensureScale($scale);
        $this->ensureNumeric($left, $right);

        $comparisonScale = $this->workingScaleForComparison($left, $right, $scale);

        return bccomp($left, $right, $comparisonScale);
    }

    public function round(string $value, int $scale): string
    {
        $this->ensureExtensionAvailable();
        $this->ensureScale($scale);
        $this->ensureNumeric($value);

        if (0 === $scale) {
            /** @var numeric-string $increment */
            $increment = '-' === $value[0] ? '-0.5' : '0.5';
            $this->ensureNumeric($increment);
            /** @var numeric-string $rounded */
            $rounded = bcadd($value, $increment, 1);

            /** @var numeric-string $result */
            $result = bcadd($rounded, '0', 0);

            return $result;
        }

        /** @var numeric-string $increment */
        $increment = '0.'.str_repeat('0', $scale).'5';
        if ('-' === $value[0]) {
            $increment = '-'.$increment;
        }

        $this->ensureNumeric($increment);

        /** @var numeric-string $numericIncrement */
        $numericIncrement = $increment;

        /** @var numeric-string $adjusted */
        $adjusted = bcadd($value, $numericIncrement, $scale + 1);

        /** @var numeric-string $result */
        $result = bcadd($adjusted, '0', $scale);

        return $result;
    }

    public function scaleForComparison(string $first, string $second, int $fallbackScale = self::DEFAULT_SCALE): int
    {
        $this->ensureExtensionAvailable();

        return $this->workingScaleForComparison($first, $second, $fallbackScale);
    }

    /**
     * Allows tests to replace the extension detector.
     *
     * @param (Closure(string):bool)|null $detector
     *
     * @phpstan-param (Closure(string):bool)|null $detector
     *
     * @psalm-param (Closure(string):bool)|null $detector
     *
     * @internal
     */
    public function setExtensionDetector(?Closure $detector): void
    {
        $this->extensionDetector = $detector;
        $this->extensionVerified = false;
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

    private function ensureScale(int $scale): void
    {
        if ($scale < 0) {
            throw new InvalidInput('Scale cannot be negative.');
        }
    }

    private function ensureNonZero(string $value): void
    {
        $this->ensureExtensionAvailable();
        $this->ensureNumeric($value);
        /** @var numeric-string $numericValue */
        $numericValue = $value;
        $scale = max($this->scaleOf($value), 1);

        if (0 === bccomp($numericValue, '0', $scale)) {
            throw new InvalidInput('Division by zero.');
        }
    }

    private function ensureExtensionAvailable(): void
    {
        if ($this->extensionVerified) {
            return;
        }

        if (!$this->extensionLoaded('bcmath')) {
            throw new PrecisionViolation('The BCMath extension (ext-bcmath) is required. Install it or require symfony/polyfill-bcmath when the extension cannot be loaded.');
        }

        $this->extensionVerified = true;
    }

    private function extensionLoaded(string $extension): bool
    {
        if (null !== $this->extensionDetector) {
            return ($this->extensionDetector)($extension);
        }

        return extension_loaded($extension);
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
