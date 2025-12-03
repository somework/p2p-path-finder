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
     * @param PathResultSet<CandidatePath> $paths
     */
    public function __construct(PathResultSet $paths, SearchGuardReport $guardLimits)
    {
        $this->paths = $paths;
        $this->guardLimits = $guardLimits;
    }

    /**
     * @param PathResultSet<CandidatePath> $paths
     */
    public static function fromResultSet(PathResultSet $paths, SearchGuardReport $guardLimits): self
    {
        return new self($paths, $guardLimits);
    }

    public static function empty(SearchGuardReport $guardLimits): self
    {
        /** @var PathResultSet<CandidatePath> $emptyPaths */
        $emptyPaths = PathResultSet::empty();

        return self::fromResultSet($emptyPaths, $guardLimits);
    }

    /**
     * @return PathResultSet<CandidatePath>
     */
    public function paths(): PathResultSet
    {
        return $this->paths;
    }

    public function bestPath(): ?CandidatePath
    {
        return $this->paths->first();
    }

    public function hasPaths(): bool
    {
        return !$this->paths->isEmpty();
    }

    public function guardLimits(): SearchGuardReport
    {
        return $this->guardLimits;
    }
}
