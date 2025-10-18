<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Result;

use JsonSerializable;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalTolerance;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function array_is_list;

/**
 * Aggregated representation of a discovered conversion path.
 */
final class PathResult implements JsonSerializable
{
    /**
     * @var array<string, Money>
     */
    private readonly array $feeBreakdown;

    /**
     * @var list<PathLeg>
     */
    private readonly array $legs;

    /**
     * @param list<PathLeg>        $legs
     * @param array<string, Money> $feeBreakdown
     */
    public function __construct(
        private readonly Money $totalSpent,
        private readonly Money $totalReceived,
        private readonly DecimalTolerance $residualTolerance,
        array $legs = [],
        array $feeBreakdown = [],
    ) {
        if (!array_is_list($legs)) {
            throw new InvalidInput('Path legs must be provided as a list.');
        }

        foreach ($legs as $leg) {
            if (!$leg instanceof PathLeg) {
                throw new InvalidInput('Every path leg must be an instance of PathLeg.');
            }
        }

        $this->legs = $legs;
        $this->feeBreakdown = $this->normalizeFeeBreakdown($feeBreakdown);
    }

    /**
     * Returns the total amount of source asset spent across the entire path.
     */
    public function totalSpent(): Money
    {
        return $this->totalSpent;
    }

    /**
     * Returns the total amount of destination asset received across the path.
     */
    public function totalReceived(): Money
    {
        return $this->totalReceived;
    }

    /**
     * @return array<string, Money>
     */
    public function feeBreakdown(): array
    {
        return $this->feeBreakdown;
    }

    /**
     * Returns the remaining tolerance after accounting for the chosen path.
     */
    public function residualTolerance(): DecimalTolerance
    {
        return $this->residualTolerance;
    }

    public function residualTolerancePercentage(int $scale = 2): string
    {
        return $this->residualTolerance->percentage($scale);
    }

    /**
     * @return list<PathLeg>
     */
    public function legs(): array
    {
        return $this->legs;
    }

    /**
     * @return array{
     *     totalSpent: array{currency: string, amount: string, scale: int},
     *     totalReceived: array{currency: string, amount: string, scale: int},
     *     residualTolerance: numeric-string,
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
    #[\Override]
    public function jsonSerialize(): array
    {
        $fees = [];
        foreach ($this->feeBreakdown as $currency => $fee) {
            $fees[$currency] = self::serializeMoney($fee);
        }

        return [
            'totalSpent' => self::serializeMoney($this->totalSpent),
            'totalReceived' => self::serializeMoney($this->totalReceived),
            'residualTolerance' => $this->residualTolerance->ratio(),
            'feeBreakdown' => $fees,
            'legs' => array_map(static fn (PathLeg $leg): array => $leg->jsonSerialize(), $this->legs),
        ];
    }

    /**
     * @param array<string, Money> $feeBreakdown
     *
     * @return array<string, Money>
     */
    private function normalizeFeeBreakdown(array $feeBreakdown): array
    {
        /** @var array<string, Money> $normalized */
        $normalized = [];

        foreach ($feeBreakdown as $entry) {
            if (!$entry instanceof Money) {
                throw new InvalidInput('Fee breakdown must contain instances of Money.');
            }

            $currency = $entry->currency();

            if (isset($normalized[$currency])) {
                $normalized[$currency] = $normalized[$currency]->add($entry);

                continue;
            }

            $normalized[$currency] = $entry;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @return array{currency: string, amount: string, scale: int}
     */
    private static function serializeMoney(Money $money): array
    {
        return [
            'currency' => $money->currency(),
            'amount' => $money->amount(),
            'scale' => $money->scale(),
        ];
    }
}
