<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Engine;

use SomeWork\P2PPathFinder\Application\PathSearch\Model\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;

/**
 * Internal response DTO describing the outcome of a search that returns {@see CandidatePath} payloads.
 *
 * This mirrors {@see \SomeWork\P2PPathFinder\Application\PathSearch\Api\Response\SearchOutcome}
 * but is dedicated to the engine layer where candidate hop sequences are surfaced before
 * materialization into full {@see \SomeWork\P2PPathFinder\Application\PathSearch\Result\Path} instances.
 *
 * @internal
 */
final class CandidateSearchOutcome
{
    /**
     * @var PathResultSet<CandidatePath>
     */
    private readonly PathResultSet $paths;

    private readonly SearchGuardReport $guardLimits;

    /**
     * Create a CandidateSearchOutcome containing candidate paths and the search guard report.
     *
     * @param PathResultSet<CandidatePath> $paths       the set of candidate paths produced by the search engine
     * @param SearchGuardReport            $guardLimits the search guard report describing applied limits/guidance
     */
    public function __construct(PathResultSet $paths, SearchGuardReport $guardLimits)
    {
        $this->paths = $paths;
        $this->guardLimits = $guardLimits;
    }

    /**
     * Create a CandidateSearchOutcome from an existing set of candidate paths and a guard limits report.
     *
     * @param PathResultSet<CandidatePath> $paths       the PathResultSet of CandidatePath items to include in the outcome
     * @param SearchGuardReport            $guardLimits the SearchGuardReport describing guard limits observed during the search
     *
     * @return self a new CandidateSearchOutcome containing the provided paths and guard limits
     */
    public static function fromResultSet(PathResultSet $paths, SearchGuardReport $guardLimits): self
    {
        return new self($paths, $guardLimits);
    }

    /**
     * Create a CandidateSearchOutcome containing no candidate paths and the provided guard limits.
     *
     * @param SearchGuardReport $guardLimits guard limits report to attach to the empty outcome
     *
     * @return self an instance whose paths set is empty and whose guardLimits is the provided report
     */
    public static function empty(SearchGuardReport $guardLimits): self
    {
        /** @var PathResultSet<CandidatePath> $emptyPaths */
        $emptyPaths = PathResultSet::empty();

        return self::fromResultSet($emptyPaths, $guardLimits);
    }

    /**
     * The set of candidate paths produced by the engine search.
     *
     * @return PathResultSet<CandidatePath> the candidate path results
     */
    public function paths(): PathResultSet
    {
        return $this->paths;
    }

    /**
     * Get the first candidate path from the stored result set.
     *
     * @return CandidatePath|null the first CandidatePath in the result set, or `null` if the set is empty
     */
    public function bestPath(): ?CandidatePath
    {
        return $this->paths->first();
    }

    /**
     * Indicates whether any candidate paths are present in the result set.
     *
     * @return bool `true` if the result set contains at least one CandidatePath, `false` otherwise
     */
    public function hasPaths(): bool
    {
        return !$this->paths->isEmpty();
    }

    /**
     * Retrieve the guard limits report produced during the search.
     *
     * @return SearchGuardReport the search guard's limits and guidance report
     */
    public function guardLimits(): SearchGuardReport
    {
        return $this->guardLimits;
    }
}
