<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Result;

use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function implode;
use function number_format;
use function sprintf;

use const PHP_EOL;

/**
 * Provides machine and human friendly representations of {@see PathResult} instances.
 */
final class PathResultFormatter
{
    /**
     * @return array{
     *     totalSpent: array{currency: string, amount: string, scale: int},
     *     totalReceived: array{currency: string, amount: string, scale: int},
     *     residualTolerance: float,
     *     feeBreakdown: array<string, array{currency: string, amount: string, scale: int}>,
     *     legs: list<array{
     *         from: string,
     *         to: string,
     *         spent: array{currency: string, amount: string, scale: int},
     *         received: array{currency: string, amount: string, scale: int},
     *         fees: array<string, array{currency: string, amount: string, scale: int}>,
     *     }>,
     * }
     */
    public function formatMachine(PathResult $result): array
    {
        return $result->jsonSerialize();
    }

    /**
     * @param list<PathResult> $results
     *
     * @return list<array{
     *     totalSpent: array{currency: string, amount: string, scale: int},
     *     totalReceived: array{currency: string, amount: string, scale: int},
     *     residualTolerance: float,
     *     feeBreakdown: array<string, array{currency: string, amount: string, scale: int}>,
     *     legs: list<array{
     *         from: string,
     *         to: string,
     *         spent: array{currency: string, amount: string, scale: int},
     *         received: array{currency: string, amount: string, scale: int},
     *         fees: array<string, array{currency: string, amount: string, scale: int}>,
     *     }>,
     * }>
     */
    public function formatMachineCollection(array $results): array
    {
        return array_map(fn (PathResult $result): array => $this->formatMachine($result), $results);
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
            number_format($result->residualTolerance() * 100, 2, '.', ''),
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
     * @param list<PathResult> $results
     */
    public function formatHumanCollection(array $results): string
    {
        if ([] === $results) {
            return 'No paths available.';
        }

        $blocks = [];
        foreach ($results as $index => $result) {
            $blocks[] = sprintf('Path %d:%s%s', $index + 1, PHP_EOL, $this->formatHuman($result));
        }

        return implode(PHP_EOL.PHP_EOL, $blocks);
    }

    /**
     * @param array<string, Money> $fees
     */
    private function formatFeeSummary(array $fees): string
    {
        if ([] === $fees) {
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
