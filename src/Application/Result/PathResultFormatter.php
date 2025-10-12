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
     *     totalFees: array{currency: string, amount: string, scale: int},
     *     residualTolerance: float,
     *     legs: list<array{
     *         from: string,
     *         to: string,
     *         spent: array{currency: string, amount: string, scale: int},
     *         received: array{currency: string, amount: string, scale: int},
     *         fee: array{currency: string, amount: string, scale: int},
     *     }>,
     * }
     */
    public function formatMachine(PathResult $result): array
    {
        /** @var array{
         *     totalSpent: array{currency: string, amount: string, scale: int},
         *     totalReceived: array{currency: string, amount: string, scale: int},
         *     totalFees: array{currency: string, amount: string, scale: int},
         *     residualTolerance: float,
         *     legs: list<array{
         *         from: string,
         *         to: string,
         *         spent: array{currency: string, amount: string, scale: int},
         *         received: array{currency: string, amount: string, scale: int},
         *         fee: array{currency: string, amount: string, scale: int},
         *     }>,
         * } $payload
         */
        $payload = $result->jsonSerialize();

        return $payload;
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
            $this->formatMoney($result->totalFees()),
            number_format($result->residualTolerance() * 100, 2, '.', ''),
        );

        $lines[] = 'Legs:';
        foreach ($result->legs() as $index => $leg) {
            $lines[] = sprintf(
                '  %d. %s -> %s | Spent %s | Received %s | Fee %s',
                $index + 1,
                $leg->from(),
                $leg->to(),
                $this->formatMoney($leg->spent()),
                $this->formatMoney($leg->received()),
                $this->formatMoney($leg->fee()),
            );
        }

        return implode(PHP_EOL, $lines);
    }

    private function formatMoney(Money $money): string
    {
        return sprintf('%s %s', $money->currency(), $money->amount());
    }
}
