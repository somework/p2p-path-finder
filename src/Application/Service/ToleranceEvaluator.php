<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Service;

use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalTolerance;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function max;
use function substr;

/**
 * Validates materialized paths against configured tolerance bounds.
 */
final class ToleranceEvaluator
{
    private const RESIDUAL_TOLERANCE_EPSILON = '0.000001';
    private const RESIDUAL_SCALE = 18;

    public function evaluate(PathSearchConfig $config, Money $requestedSpend, Money $actualSpend): ?DecimalTolerance
    {
        $residual = $this->calculateResidualTolerance($requestedSpend, $actualSpend);

        $requestedComparable = $requestedSpend->withScale(max($requestedSpend->scale(), $actualSpend->scale()));
        $actualComparable = $actualSpend->withScale($requestedComparable->scale());

        if (
            $actualComparable->lessThan($requestedComparable)
            && 1 === BcMath::comp(
                BcMath::sub($residual, $config->minimumTolerance(), self::RESIDUAL_SCALE),
                self::RESIDUAL_TOLERANCE_EPSILON,
                self::RESIDUAL_SCALE,
            )
        ) {
            return null;
        }

        if (
            $actualComparable->greaterThan($requestedComparable)
            && 1 === BcMath::comp(
                BcMath::sub($residual, $config->maximumTolerance(), self::RESIDUAL_SCALE),
                self::RESIDUAL_TOLERANCE_EPSILON,
                self::RESIDUAL_SCALE,
            )
        ) {
            return null;
        }

        return DecimalTolerance::fromNumericString($residual, self::RESIDUAL_SCALE);
    }

    /**
     * @return numeric-string
     */
    private function calculateResidualTolerance(Money $desired, Money $actual): string
    {
        $scale = max($desired->scale(), $actual->scale(), self::RESIDUAL_SCALE);
        $desiredAmount = $desired->withScale($scale)->amount();

        if (0 === BcMath::comp($desiredAmount, '0', $scale)) {
            return BcMath::normalize('0', self::RESIDUAL_SCALE);
        }

        $actualAmount = $actual->withScale($scale)->amount();
        $diff = BcMath::sub($actualAmount, $desiredAmount, $scale + 4);

        if ('-' === $diff[0]) {
            $diff = substr($diff, 1);
        }

        if ('' === $diff) {
            $diff = '0';
        }

        BcMath::ensureNumeric($diff);
        $ratio = BcMath::div($diff, $desiredAmount, self::RESIDUAL_SCALE + 4);

        return BcMath::normalize($ratio, self::RESIDUAL_SCALE);
    }
}
