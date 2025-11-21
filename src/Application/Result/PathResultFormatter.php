<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Result;

use SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSet;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function implode;
use function sprintf;

use const PHP_EOL;

/**
 * Provides machine and human friendly representations of {@see PathResult} instances.
 */
final class PathResultFormatter
{
    /**
     * @return array{
     *     totalSpent: array{currency: string, amount: numeric-string, scale: int},
     *     totalReceived: array{currency: string, amount: numeric-string, scale: int},
     *     residualTolerance: numeric-string,
     *     feeBreakdown: array<string, array{currency: string, amount: numeric-string, scale: int}>,
     *     legs: list<array{
     *         from: string,
     *         to: string,
     *         spent: array{currency: string, amount: numeric-string, scale: int},
     *         received: array{currency: string, amount: numeric-string, scale: int},
     *         fees: array<string, array{currency: string, amount: numeric-string, scale: int}>,
     *     }>,
     * }
     */
    public function formatMachine(PathResult $result): array
    {
        return $result->jsonSerialize();
    }

    /**
     * @param PathResultSet<PathResult> $results
     *
     * @return list<array{
     *     totalSpent: array{currency: string, amount: numeric-string, scale: int},
     *     totalReceived: array{currency: string, amount: numeric-string, scale: int},
     *     residualTolerance: numeric-string,
     *     feeBreakdown: array<string, array{currency: string, amount: numeric-string, scale: int}>,
     *     legs: list<array{
     *         from: string,
     *         to: string,
     *         spent: array{currency: string, amount: numeric-string, scale: int},
     *         received: array{currency: string, amount: numeric-string, scale: int},
     *         fees: array<string, array{currency: string, amount: numeric-string, scale: int}>,
     *     }>,
     * }>
     */
    public function formatMachineCollection(PathResultSet $results): array
    {
        return array_map(fn (PathResult $result): array => $this->formatMachine($result), $results->toArray());
    }

    /**
     * Produces a multi-line human readable summary of the conversion path.
     */
    public function formatHuman(PathResult $result): string
    {
        $lines = [];
        $lines[] = sprintf(
            'Total spent: %s; total received: %s; total fees: %s; residual tolerance: %s%%.',
            $this->formatMoney($result->totalSpent()),
            $this->formatMoney($result->totalReceived()),
            $this->formatFeeSummary($result->feeBreakdown()),
            $result->residualTolerancePercentage(2),
        );

        $lines[] = 'Legs:';
        foreach ($result->legs() as $index => $leg) {
            $lines[] = sprintf(
                '  %d. %s -> %s | Spent %s | Received %s | Fees %s',
                $index + 1,
                $leg->from(),
                $leg->to(),
                $this->formatMoney($leg->spent()),
                $this->formatMoney($leg->received()),
                $this->formatFeeSummary($leg->fees()),
            );
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param PathResultSet<PathResult>|list<PathResult> $results
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
