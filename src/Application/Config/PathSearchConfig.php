<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Config;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function sprintf;

final class PathSearchConfig
{
    private readonly Money $minimumSpendAmount;

    private readonly Money $maximumSpendAmount;

    public function __construct(
        private readonly Money $spendAmount,
        private readonly float $minimumTolerance,
        private readonly float $maximumTolerance,
        private readonly int $minimumHops,
        private readonly int $maximumHops,
    ) {
        if ($minimumTolerance < 0.0 || $minimumTolerance >= 1.0) {
            throw new InvalidArgumentException('Minimum tolerance must be in the [0, 1) range.');
        }

        if ($maximumTolerance < 0.0 || $maximumTolerance >= 1.0) {
            throw new InvalidArgumentException('Maximum tolerance must be in the [0, 1) range.');
        }

        if ($minimumHops < 1) {
            throw new InvalidArgumentException('Minimum hops must be at least one.');
        }

        if ($maximumHops < $minimumHops) {
            throw new InvalidArgumentException('Maximum hops must be greater than or equal to minimum hops.');
        }

        $this->minimumSpendAmount = $this->calculateBoundedSpend(1.0 - $minimumTolerance);
        $this->maximumSpendAmount = $this->calculateBoundedSpend(1.0 + $maximumTolerance);
    }

    public static function builder(): PathSearchConfigBuilder
    {
        return new PathSearchConfigBuilder();
    }

    public function spendAmount(): Money
    {
        return $this->spendAmount;
    }

    public function minimumTolerance(): float
    {
        return $this->minimumTolerance;
    }

    public function maximumTolerance(): float
    {
        return $this->maximumTolerance;
    }

    public function minimumHops(): int
    {
        return $this->minimumHops;
    }

    public function maximumHops(): int
    {
        return $this->maximumHops;
    }

    public function minimumSpendAmount(): Money
    {
        return $this->minimumSpendAmount;
    }

    public function maximumSpendAmount(): Money
    {
        return $this->maximumSpendAmount;
    }

    public function pathFinderTolerance(): float
    {
        return min(max($this->minimumTolerance, $this->maximumTolerance), 0.999999);
    }

    private function calculateBoundedSpend(float $multiplier): Money
    {
        if ($multiplier < 0.0) {
            throw new InvalidArgumentException('Spend multiplier must be non-negative.');
        }

        $scale = max($this->spendAmount->scale(), 8);
        $factor = self::floatToString($multiplier, $scale);

        $adjusted = $this->spendAmount->multiply($factor, $scale);

        return $adjusted->withScale($this->spendAmount->scale());
    }

    private static function floatToString(float $value, int $scale): string
    {
        $normalized = BcMath::normalize(sprintf('%.'.($scale + 2).'F', $value), $scale);

        if ('-0' === $normalized) {
            return '0';
        }

        return $normalized;
    }
}
