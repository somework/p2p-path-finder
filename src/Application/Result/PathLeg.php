<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Result;

use JsonSerializable;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function sprintf;
use function strtoupper;

/**
 * Describes a single conversion leg in a path finder result.
 */
final class PathLeg implements JsonSerializable
{
    private readonly string $fromAsset;

    private readonly string $toAsset;

    /**
     * @var array<string, Money>
     */
    private readonly array $fees;

    /**
     * @param array<array-key, Money> $fees
     */
    public function __construct(
        string $fromAsset,
        string $toAsset,
        private readonly Money $spent,
        private readonly Money $received,
        array $fees = [],
    ) {
        $this->fromAsset = self::normalizeAsset($fromAsset, 'from');
        $this->toAsset = self::normalizeAsset($toAsset, 'to');
        $this->fees = $this->normalizeFees($fees);
    }

    /**
     * Returns the asset symbol of the leg's source.
     */
    public function from(): string
    {
        return $this->fromAsset;
    }

    /**
     * Returns the asset symbol of the leg's destination.
     */
    public function to(): string
    {
        return $this->toAsset;
    }

    /**
     * Returns the amount of source asset spent in this leg.
     */
    public function spent(): Money
    {
        return $this->spent;
    }

    /**
     * Returns the amount of destination asset received in this leg.
     */
    public function received(): Money
    {
        return $this->received;
    }

    /**
     * @return array<string, Money>
     */
    public function fees(): array
    {
        return $this->fees;
    }

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     spent: array{currency: string, amount: string, scale: int},
     *     received: array{currency: string, amount: string, scale: int},
     *     fees: array<string, array{currency: string, amount: string, scale: int}>,
     * }
     */
    public function jsonSerialize(): array
    {
        $fees = [];
        foreach ($this->fees as $currency => $fee) {
            $fees[$currency] = self::serializeMoney($fee);
        }

        return [
            'from' => $this->fromAsset,
            'to' => $this->toAsset,
            'spent' => self::serializeMoney($this->spent),
            'received' => self::serializeMoney($this->received),
            'fees' => $fees,
        ];
    }

    private static function normalizeAsset(string $asset, string $field): string
    {
        $normalized = strtoupper($asset);

        if ('' === $normalized) {
            throw new InvalidInput(sprintf('Path leg %s asset cannot be empty.', $field));
        }

        return $normalized;
    }

    /**
     * @param array<array-key, Money> $fees
     *
     * @throws InvalidInput|PrecisionViolation when fee entries are invalid or cannot be merged deterministically
     *
     * @return array<string, Money>
     */
    private function normalizeFees(array $fees): array
    {
        /** @var array<string, Money> $normalized */
        $normalized = [];

        foreach ($fees as $fee) {
            if (!$fee instanceof Money) {
                throw new InvalidInput('Path leg fees must be instances of Money.');
            }

            if ($fee->isZero()) {
                continue;
            }

            $currency = $fee->currency();

            if (isset($normalized[$currency])) {
                $normalized[$currency] = $normalized[$currency]->add($fee);

                continue;
            }

            $normalized[$currency] = $fee;
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
