<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Engine;

use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;

/**
 * Outcome of an execution plan search operation.
 *
 * Contains the execution plan (if found), guard report describing resource usage,
 * and completion status indicating whether the full requested amount was converted.
 *
 * @api
 */
final class ExecutionPlanSearchOutcome
{
    public function __construct(
        private readonly ?ExecutionPlan $plan,
        private readonly SearchGuardReport $guardReport,
        private readonly bool $isComplete,
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
     * Creates a successful outcome with a complete plan.
     */
    public static function complete(ExecutionPlan $plan, SearchGuardReport $guardReport): self
    {
        return new self($plan, $guardReport, true);
    }

    /**
     * Creates a partial outcome when the plan does not satisfy the full amount.
     */
    public static function partial(ExecutionPlan $plan, SearchGuardReport $guardReport): self
    {
        return new self($plan, $guardReport, false);
    }

    /**
     * Returns the execution plan, or null if no valid plan was found.
     */
    public function plan(): ?ExecutionPlan
    {
        return $this->plan;
    }

    /**
     * Returns the search guard report with resource usage metrics.
     */
    public function guardReport(): SearchGuardReport
    {
        return $this->guardReport;
    }

    /**
     * Returns true if the plan satisfies the full requested amount.
     */
    public function isComplete(): bool
    {
        return $this->isComplete;
    }

    /**
     * Returns true if a plan exists but does not satisfy the full amount.
     */
    public function isPartial(): bool
    {
        return null !== $this->plan && !$this->isComplete;
    }

    /**
     * Returns true if a valid plan was found (complete or partial).
     */
    public function hasPlan(): bool
    {
        return null !== $this->plan;
    }

    /**
     * Returns true if no plan was found.
     */
    public function isEmpty(): bool
    {
        return null === $this->plan;
    }
}
