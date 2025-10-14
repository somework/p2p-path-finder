<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Service;

use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function substr;

/**
 * Validates residual tolerance between desired and actual spend amounts.
 */
final class ToleranceEvaluator
{
    private const RESIDUAL_TOLERANCE_EPSILON = 0.000001;

    public function evaluate(Money $desiredSpend, Money $actualSpend, PathSearchConfig $config): ?float
    {
        $residual = $this->calculateResidualTolerance($desiredSpend, $actualSpend);

        $requestedComparable = $desiredSpend->withScale(max($desiredSpend->scale(), $actualSpend->scale()));
        $actualComparable = $actualSpend->withScale($requestedComparable->scale());

        if (
            $actualComparable->lessThan($requestedComparable)
            && $residual - $config->minimumTolerance() > self::RESIDUAL_TOLERANCE_EPSILON
        ) {
            return null;
        }

        if (
            $actualComparable->greaterThan($requestedComparable)
            && $residual - $config->maximumTolerance() > self::RESIDUAL_TOLERANCE_EPSILON
        ) {
            return null;
        }

        return $residual;
    }

    private function calculateResidualTolerance(Money $desired, Money $actual): float
    {
        $scale = max($desired->scale(), $actual->scale(), 8);
        $desiredAmount = $desired->withScale($scale)->amount();

        if (0 === BcMath::comp($desiredAmount, '0', $scale)) {
            return 0.0;
        }

        $actualAmount = $actual->withScale($scale)->amount();
        $diff = BcMath::sub($actualAmount, $desiredAmount, $scale + 4);

        if ('-' === $diff[0]) {
            $diff = substr($diff, 1);
        }

        $ratio = BcMath::div($diff, $desiredAmount, $scale + 4);

        return (float) $ratio;
    }
}
