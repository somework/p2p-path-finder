<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Engine;

use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

/**
 * Outcome of an execution plan search operation.
 *
 * Contains raw order fills from the search engine, guard report describing resource usage,
 * and completion status indicating whether the full requested amount was converted.
 *
 * The raw fills can be materialized into an ExecutionPlan using the ExecutionPlanMaterializer.
 *
 * @api
 */
final class ExecutionPlanSearchOutcome
{
    /**
     * @param list<array{order: Order, spend: Money, sequence: int}>|null $rawFills
     */
    public function __construct(
        private readonly ?array $rawFills,
        private readonly SearchGuardReport $guardReport,
        private readonly bool $isComplete,
        private readonly string $sourceCurrency = '',
        private readonly string $targetCurrency = '',
    ) {
    }

    /**
     * Creates an empty outcome when no plan could be found.
     */
    public static function empty(SearchGuardReport $guardReport): self
    {
        return new self(null, $guardReport, false);
    }

    /**
     * Creates a successful outcome with complete raw fills.
     *
     * @param list<array{order: Order, spend: Money, sequence: int}> $rawFills
     *
     * @throws InvalidInput when $rawFills is empty
     */
    public static function complete(
        array $rawFills,
        SearchGuardReport $guardReport,
        string $sourceCurrency,
        string $targetCurrency,
    ): self {
        if ([] === $rawFills) {
            throw new InvalidInput('Complete outcome requires at least one raw fill.');
        }

        return new self($rawFills, $guardReport, true, $sourceCurrency, $targetCurrency);
    }

    /**
     * Creates a partial outcome when fills do not satisfy the full amount.
     *
     * @param list<array{order: Order, spend: Money, sequence: int}> $rawFills
     *
     * @throws InvalidInput when $rawFills is empty
     */
    public static function partial(
        array $rawFills,
        SearchGuardReport $guardReport,
        string $sourceCurrency,
        string $targetCurrency,
    ): self {
        if ([] === $rawFills) {
            throw new InvalidInput('Partial outcome requires at least one raw fill.');
        }

        return new self($rawFills, $guardReport, false, $sourceCurrency, $targetCurrency);
    }

    /**
     * Returns the raw order fills from the search engine.
     *
     * Use ExecutionPlanMaterializer to convert these fills into an ExecutionPlan.
     *
     * @return list<array{order: Order, spend: Money, sequence: int}>|null
     */
    public function rawFills(): ?array
    {
        return $this->rawFills;
    }

    /**
     * Returns true if raw fills are available.
     */
    public function hasRawFills(): bool
    {
        return null !== $this->rawFills && [] !== $this->rawFills;
    }

    /**
     * Returns the source currency for the search.
     */
    public function sourceCurrency(): string
    {
        return $this->sourceCurrency;
    }

    /**
     * Returns the target currency for the search.
     */
    public function targetCurrency(): string
    {
        return $this->targetCurrency;
    }

    /**
     * Returns the search guard report with resource usage metrics.
     */
    public function guardReport(): SearchGuardReport
    {
        return $this->guardReport;
    }

    /**
     * Returns true if the fills satisfy the full requested amount.
     */
    public function isComplete(): bool
    {
        return $this->isComplete;
    }

    /**
     * Returns true if fills exist but do not satisfy the full amount.
     */
    public function isPartial(): bool
    {
        return $this->hasRawFills() && !$this->isComplete;
    }

    /**
     * Returns true if no fills were found.
     */
    public function isEmpty(): bool
    {
        return !$this->hasRawFills();
    }
}
