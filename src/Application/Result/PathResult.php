<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Result;

use InvalidArgumentException;
use JsonSerializable;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function array_is_list;

final class PathResult implements JsonSerializable
{
    /**
     * @var list<PathLeg>
     */
    private readonly array $legs;

    /**
     * @param list<PathLeg> $legs
     */
    public function __construct(
        private readonly Money $totalSpent,
        private readonly Money $totalReceived,
        private readonly Money $totalFees,
        private readonly float $residualTolerance,
        array $legs = [],
    ) {
        if ($residualTolerance < 0.0 || $residualTolerance > 1.0) {
            throw new InvalidArgumentException('Residual tolerance must be a value between 0 and 1 inclusive.');
        }

        if (!array_is_list($legs)) {
            throw new InvalidArgumentException('Path legs must be provided as a list.');
        }

        foreach ($legs as $leg) {
            if (!$leg instanceof PathLeg) {
                throw new InvalidArgumentException('Every path leg must be an instance of PathLeg.');
            }
        }

        $this->legs = $legs;
    }

    public function totalSpent(): Money
    {
        return $this->totalSpent;
    }

    public function totalReceived(): Money
    {
        return $this->totalReceived;
    }

    public function totalFees(): Money
    {
        return $this->totalFees;
    }

    public function residualTolerance(): float
    {
        return $this->residualTolerance;
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
    public function jsonSerialize(): array
    {
        return [
            'totalSpent' => self::serializeMoney($this->totalSpent),
            'totalReceived' => self::serializeMoney($this->totalReceived),
            'totalFees' => self::serializeMoney($this->totalFees),
            'residualTolerance' => $this->residualTolerance,
            'legs' => array_map(static fn (PathLeg $leg): array => $leg->jsonSerialize(), $this->legs),
        ];
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
