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
 * Describes a single execution step in an execution plan.
 *
 * Each step preserves the originating {@see Order}, normalized assets, step-level
 * fees, spent/received amounts, and sequence number for execution ordering.
 * Steps can represent both linear path segments and parallel execution branches.
 *
 * @api
 */
final class ExecutionStep
{
    private readonly string $fromAsset;

    private readonly string $toAsset;

    public function __construct(
        string $from,
        string $to,
        private readonly Money $spent,
        private readonly Money $received,
        private readonly Order $order,
        private readonly MoneyMap $fees,
        private readonly int $sequenceNumber,
    ) {
        $this->fromAsset = self::normalizeAsset($from, 'from');
        $this->toAsset = self::normalizeAsset($to, 'to');

        $this->assertMoneyMatchesAsset($this->spent, $this->fromAsset, 'spent', 'from');
        $this->assertMoneyMatchesAsset($this->received, $this->toAsset, 'received', 'to');
        $this->assertValidSequenceNumber($this->sequenceNumber);
    }

    /**
     * Returns the asset symbol of the step's source.
     */
    public function from(): string
    {
        return $this->fromAsset;
    }

    /**
     * Returns the asset symbol of the step's destination.
     */
    public function to(): string
    {
        return $this->toAsset;
    }

    /**
     * Returns the amount of source asset spent in this step.
     */
    public function spent(): Money
    {
        return $this->spent;
    }

    /**
     * Returns the amount of destination asset received in this step.
     */
    public function received(): Money
    {
        return $this->received;
    }

    /**
     * Returns the order associated with this step.
     */
    public function order(): Order
    {
        return $this->order;
    }

    /**
     * Returns the fees incurred during this step.
     */
    public function fees(): MoneyMap
    {
        return $this->fees;
    }

    /**
     * Returns the execution order number (1-based).
     */
    public function sequenceNumber(): int
    {
        return $this->sequenceNumber;
    }

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     spent: string,
     *     received: string,
     *     fees: array<string, string>,
     *     sequence: int,
     * }
     */
    public function toArray(): array
    {
        $feesArray = [];
        foreach ($this->fees as $currency => $money) {
            $feesArray[$currency] = $money->amount();
        }

        return [
            'from' => $this->fromAsset,
            'to' => $this->toAsset,
            'spent' => $this->spent->amount(),
            'received' => $this->received->amount(),
            'fees' => $feesArray,
            'sequence' => $this->sequenceNumber,
        ];
    }

    /**
     * Creates an ExecutionStep from an existing PathHop.
     *
     * @throws InvalidInput when the sequence number is invalid
     */
    public static function fromPathHop(PathHop $hop, int $sequenceNumber): self
    {
        return new self(
            $hop->from(),
            $hop->to(),
            $hop->spent(),
            $hop->received(),
            $hop->order(),
            $hop->fees(),
            $sequenceNumber,
        );
    }

    private static function normalizeAsset(string $asset, string $field): string
    {
        $normalized = strtoupper(trim($asset));

        if ('' === $normalized) {
            throw new InvalidInput(sprintf('Execution step %s asset cannot be empty.', $field));
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
            throw new InvalidInput(sprintf('Execution step %s currency must match the %s asset.', $moneyField, $assetField));
        }
    }

    private function assertValidSequenceNumber(int $sequenceNumber): void
    {
        if ($sequenceNumber < 1) {
            throw new InvalidInput('Execution step sequence number must be at least 1.');
        }
    }
}
