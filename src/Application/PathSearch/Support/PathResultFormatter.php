<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Support;

use SomeWork\P2PPathFinder\Application\PathSearch\Result\Path;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSet;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;

use function implode;
use function sprintf;

use const PHP_EOL;

/**
 * Provides machine and human friendly representations of {@see Path} instances.
 */
final class PathResultFormatter
{
    /**
     * Produces a multi-line human readable summary of the conversion path.
     */
    public function formatHuman(Path $result): string
    {
        $lines = [];
        $lines[] = sprintf(
            'Total spent: %s; total received: %s; total fees: %s; residual tolerance: %s%%.',
            $this->formatMoney($result->totalSpent()),
            $this->formatMoney($result->totalReceived()),
            $this->formatFeeSummary($result->feeBreakdown()),
            $result->residualTolerancePercentage(2),
        );

        $lines[] = 'Hops:';
        foreach ($result->hops() as $index => $hop) {
            $lines[] = sprintf(
                '  %d. %s -> %s | Spent %s | Received %s | Fees %s',
                $index + 1,
                $hop->from(),
                $hop->to(),
                $this->formatMoney($hop->spent()),
                $this->formatMoney($hop->received()),
                $this->formatFeeSummary($hop->fees()),
            );
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param PathResultSet<Path>|list<Path> $results
     */
    public function formatHumanCollection(array|PathResultSet $results): string
    {
        if ($results instanceof PathResultSet) {
            $results = $results->toArray();
        }

        if ([] === $results) {
            return 'No paths available.';
        }

        $blocks = [];
        foreach ($results as $index => $result) {
            $blocks[] = sprintf('Path %d:%s%s', $index + 1, PHP_EOL, $this->formatHuman($result));
        }

        return implode(PHP_EOL.PHP_EOL, $blocks);
    }

    private function formatFeeSummary(MoneyMap $fees): string
    {
        if ($fees->isEmpty()) {
            return 'none';
        }

        $parts = [];
        foreach ($fees as $fee) {
            $parts[] = $this->formatMoney($fee);
        }

        return implode(', ', $parts);
    }

    private function formatMoney(Money $money): string
    {
        return sprintf('%s %s', $money->currency(), $money->amount());
    }
}
