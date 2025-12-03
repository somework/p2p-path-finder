<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Result;

use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function sprintf;
use function strtoupper;

/**
 * Describes a single conversion hop in a path finder result.
 *
 * @api
 */
final class PathHop
{
    private readonly string $fromAsset;

    private readonly string $toAsset;

    private readonly MoneyMap $fees;

    public function __construct(
        string $fromAsset,
        string $toAsset,
        private readonly Money $spent,
        private readonly Money $received,
        private readonly Order $order,
        ?MoneyMap $fees = null,
    ) {
        $this->fromAsset = self::normalizeAsset($fromAsset, 'from');
        $this->toAsset = self::normalizeAsset($toAsset, 'to');
        $this->assertMoneyMatchesAsset($this->spent, $this->fromAsset, 'spent', 'from');
        $this->assertMoneyMatchesAsset($this->received, $this->toAsset, 'received', 'to');

        $this->fees = $fees ?? MoneyMap::empty();
    }

    /**
     * Returns the asset symbol of the hop's source.
     */
    public function from(): string
    {
        return $this->fromAsset;
    }

    /**
     * Returns the asset symbol of the hop's destination.
     */
    public function to(): string
    {
        return $this->toAsset;
    }

    /**
     * Returns the amount of source asset spent in this hop.
     */
    public function spent(): Money
    {
        return $this->spent;
    }

    /**
     * Returns the amount of destination asset received in this hop.
     */
    public function received(): Money
    {
        return $this->received;
    }

    public function order(): Order
    {
        return $this->order;
    }

    public function fees(): MoneyMap
    {
        return $this->fees;
    }

    /**
     * @return array<string, Money>
     */
    public function feesAsArray(): array
    {
        return $this->fees->toArray();
    }

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     spent: Money,
     *     received: Money,
     *     fees: MoneyMap,
     *     order: Order,
     * }
     */
    public function toArray(): array
    {
        return [
            'from' => $this->fromAsset,
            'to' => $this->toAsset,
            'spent' => $this->spent,
            'received' => $this->received,
            'fees' => $this->fees,
            'order' => $this->order,
        ];
    }

    private static function normalizeAsset(string $asset, string $field): string
    {
        $normalized = strtoupper(trim($asset));

        if ('' === $normalized) {
            throw new InvalidInput(sprintf('Path hop %s asset cannot be empty.', $field));
        }

        return $normalized;
    }

    private function assertMoneyMatchesAsset(
        Money $money,
        string $asset,
        string $moneyField,
        string $assetField,
    ): void {
        if ($money->currency() !== $asset) {
            throw new InvalidInput(sprintf('Path hop %s currency must match the %s asset.', $moneyField, $assetField));
        }
    }
}
