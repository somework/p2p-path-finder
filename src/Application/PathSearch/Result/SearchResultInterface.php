<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Result;

use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;

/**
 * Common interface for search results (both linear paths and execution plans).
 *
 * This interface defines the common contract between {@see Path} (linear paths)
 * and {@see ExecutionPlan} (which may include split/merge routes), allowing
 * {@see SearchOutcome} to be generic over either result type.
 *
 * @api
 */
interface SearchResultInterface
{
    /**
     * Returns the total amount spent in the source currency.
     */
    public function totalSpent(): Money;

    /**
     * Returns the total amount received in the target currency.
     */
    public function totalReceived(): Money;

    /**
     * Returns the fee breakdown across all currencies.
     */
    public function feeBreakdown(): MoneyMap;

    /**
     * Returns the residual tolerance after executing the search result.
     */
    public function residualTolerance(): DecimalTolerance;
}
