<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Service;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Domain\Tolerance\ToleranceWindow;

use function max;

/**
 * Validates materialized paths against configured tolerance bounds.
 *
 * The evaluator derives a residual ratio between the requested and actual spend
 * and compares it directly to the configured tolerance window using a common
 * scale. This keeps comparisons deterministic and eliminates the need for
 * hard-coded guard epsilons that could otherwise mask meaningful overshoot.
 *
 * @internal
 */
final class ToleranceEvaluator
{
    public function evaluate(PathSearchConfig $config, Money $requestedSpend, Money $actualSpend): ?DecimalTolerance
    {
        $residual = $this->calculateResidualTolerance($requestedSpend, $actualSpend);
        $residualDecimal = $this->decimalFromString($residual);

        $requestedComparable = $requestedSpend->withScale(max($requestedSpend->scale(), $actualSpend->scale()));
        $actualComparable = $actualSpend->withScale($requestedComparable->scale());

        if ($requestedComparable->isZero() && $actualComparable->greaterThan($requestedComparable)) {
            return null;
        }

        $toleranceWindow = $config->toleranceWindow();
        $toleranceScale = ToleranceWindow::scale();
        $minimumTolerance = $this->decimalFromString($toleranceWindow->minimum());
        $maximumTolerance = $this->decimalFromString($toleranceWindow->maximum());

        if (
            $actualComparable->lessThan($requestedComparable)
            && $residualDecimal->compareTo($minimumTolerance) > 0
        ) {
            return null;
        }

        if (
            $actualComparable->greaterThan($requestedComparable)
            && $residualDecimal->compareTo($maximumTolerance) > 0
        ) {
            return null;
        }

        return DecimalTolerance::fromNumericString($residual, $toleranceScale);
    }

    /**
     * @return numeric-string
     */
    private function calculateResidualTolerance(Money $desired, Money $actual): string
    {
        $targetScale = ToleranceWindow::scale();
        $scale = max($desired->scale(), $actual->scale(), $targetScale);
        $desiredDecimal = $desired->decimal()->toScale($scale, RoundingMode::HalfUp);
        $actualDecimal = $actual->decimal()->toScale($scale, RoundingMode::HalfUp);

        if ($desiredDecimal->isZero()) {
            if ($actualDecimal->isZero()) {
                return $this->decimalToString(BigDecimal::zero(), $targetScale);
            }

            return $this->decimalToString(BigDecimal::one(), $targetScale);
        }

        $difference = $actualDecimal->minus($desiredDecimal)->abs();

        if ($difference->isZero()) {
            return $this->decimalToString(BigDecimal::zero(), $targetScale);
        }

        $ratioScale = $targetScale + 4;
        $ratio = $difference->dividedBy($desiredDecimal, $ratioScale, RoundingMode::HalfUp);

        return $this->decimalToString($ratio, $targetScale);
    }

    private function decimalFromString(string $value): BigDecimal
    {
        return BigDecimal::of($value);
    }

    private function scaleDecimal(BigDecimal $decimal, int $scale): BigDecimal
    {
        return $decimal->toScale($scale, RoundingMode::HalfUp);
    }

    /**
     * @return numeric-string
     */
    private function decimalToString(BigDecimal $decimal, int $scale): string
    {
        /** @var numeric-string $result */
        $result = $this->scaleDecimal($decimal, $scale)->__toString();

        return $result;
    }
}
