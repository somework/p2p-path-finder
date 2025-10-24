<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result;

/**
 * @template TPath of mixed
 */
final class SearchOutcome
{
    /**
     * @var PathResultSet<TPath>
     */
    private readonly PathResultSet $paths;

    private readonly SearchGuardReport $guardLimits;

    /**
     * @param PathResultSet<TPath> $paths
     */
    public function __construct(PathResultSet $paths, SearchGuardReport $guardLimits)
    {
        $this->paths = $paths;
        $this->guardLimits = $guardLimits;
    }

    /**
     * @return SearchOutcome<TPath>
     *
     * @psalm-return SearchOutcome<TPath>
     */
    public static function empty(SearchGuardReport $guardLimits): self
    {
        /** @var SearchOutcome<TPath> $empty */
        $empty = new self(PathResultSet::empty(), $guardLimits);

        return $empty;
    }

    /**
     * @return PathResultSet<TPath>
     */
    public function paths(): PathResultSet
    {
        return $this->paths;
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
