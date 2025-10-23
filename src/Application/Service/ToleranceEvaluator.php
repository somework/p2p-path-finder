<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Service;

use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalTolerance;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\ToleranceWindow;

use function max;
use function substr;

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

        $requestedComparable = $requestedSpend->withScale(max($requestedSpend->scale(), $actualSpend->scale()));
        $actualComparable = $actualSpend->withScale($requestedComparable->scale());

        $requestedAmount = $requestedComparable->amount();
        $actualAmount = $actualComparable->amount();
        $comparisonScale = $requestedComparable->scale();

        if (
            0 === BcMath::comp($requestedAmount, '0', $comparisonScale)
            && 1 === BcMath::comp($actualAmount, '0', $comparisonScale)
        ) {
            return null;
        }

        $toleranceWindow = $config->toleranceWindow();
        $toleranceScale = ToleranceWindow::scale();
        $minimumTolerance = $toleranceWindow->minimum();
        $maximumTolerance = $toleranceWindow->maximum();

        if (
            $actualComparable->lessThan($requestedComparable)
            && 1 === BcMath::comp($residual, $minimumTolerance, $toleranceScale)
        ) {
            return null;
        }

        if (
            $actualComparable->greaterThan($requestedComparable)
            && 1 === BcMath::comp($residual, $maximumTolerance, $toleranceScale)
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
        $desiredAmount = $desired->withScale($scale)->amount();
        $actualAmount = $actual->withScale($scale)->amount();

        if (0 === BcMath::comp($desiredAmount, '0', $scale)) {
            if (0 === BcMath::comp($actualAmount, '0', $scale)) {
                return BcMath::normalize('0', $targetScale);
            }

            return BcMath::normalize('1', $targetScale);
        }

        $diff = BcMath::sub($actualAmount, $desiredAmount, $scale + 4);

        if ('-' === $diff[0]) {
            $diff = substr($diff, 1);
        }

        BcMath::ensureNumeric($diff);

        if (0 === BcMath::comp($diff, '0', $scale + 4)) {
            $diff = '0';
        }

        BcMath::ensureNumeric($diff);
        $ratio = BcMath::div($diff, $desiredAmount, $targetScale + 4);

        return BcMath::normalize($ratio, $targetScale);
    }
}
