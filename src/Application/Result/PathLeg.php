<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Result;

use InvalidArgumentException;
use JsonSerializable;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function sprintf;
use function strtoupper;

final class PathLeg implements JsonSerializable
{
    private readonly string $fromAsset;

    private readonly string $toAsset;

    public function __construct(
        string $fromAsset,
        string $toAsset,
        private readonly Money $spent,
        private readonly Money $received,
        private readonly Money $fee,
    ) {
        $this->fromAsset = self::normalizeAsset($fromAsset, 'from');
        $this->toAsset = self::normalizeAsset($toAsset, 'to');
    }

    public function from(): string
    {
        return $this->fromAsset;
    }

    public function to(): string
    {
        return $this->toAsset;
    }

    public function spent(): Money
    {
        return $this->spent;
    }

    public function received(): Money
    {
        return $this->received;
    }

    public function fee(): Money
    {
        return $this->fee;
    }

    /**
     * @return array{from: string, to: string, spent: array{currency: string, amount: string, scale: int}, received: array{currency: string, amount: string, scale: int}, fee: array{currency: string, amount: string, scale: int}}
     */
    public function jsonSerialize(): array
    {
        return [
            'from' => $this->fromAsset,
            'to' => $this->toAsset,
            'spent' => self::serializeMoney($this->spent),
            'received' => self::serializeMoney($this->received),
            'fee' => self::serializeMoney($this->fee),
        ];
    }

    private static function normalizeAsset(string $asset, string $field): string
    {
        $normalized = strtoupper($asset);

        if ('' === $normalized) {
            throw new InvalidArgumentException(sprintf('Path leg %s asset cannot be empty.', $field));
        }

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
