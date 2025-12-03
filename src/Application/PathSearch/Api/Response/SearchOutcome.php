<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Api\Response;

use SomeWork\P2PPathFinder\Application\PathSearch\Config\SearchGuardConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\Path;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;

/**
 * Immutable response DTO describing the outcome of a path search.
 *
 * Carries discovered {@see Path}
 * instances built from hop-centric DTOs ({@see \SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHop}
 * / {@see \SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHopCollection})
 * alongside guard rail metrics.
 *
 * @template-covariant TPath of Path
 *
 * @phpstan-template-covariant TPath of Path
 *
 * @psalm-template-covariant TPath as Path
 *
 * @see PathResultSet For the paths collection
 * @see SearchGuardReport For guard metrics and limits
 * @see SearchGuardConfig For configuring guards
 * @see docs/troubleshooting.md#guard-limits-hit For debugging guard breaches
 *
 * @api
 */
final class SearchOutcome
{
    /**
     * @var PathResultSet<TPath>
     *
     * @phpstan-var PathResultSet<TPath>
     *
     * @psalm-var PathResultSet<TPath>
     */
    private readonly PathResultSet $paths;

    private readonly SearchGuardReport $guardLimits;

    /**
     * Create a new SearchOutcome containing discovered paths and their guard-rail metrics.
     *
     * @param PathResultSet<TPath> $paths The collection of discovered Path instances.
     * @param SearchGuardReport $guardLimits The guard-rail report describing limits and metrics observed during search.
     *
     * @phpstan-param PathResultSet<TPath> $paths
     *
     * @psalm-param PathResultSet<TPath> $paths
     */
    public function __construct(PathResultSet $paths, SearchGuardReport $guardLimits)
    {
        $this->paths = $paths;
        $this->guardLimits = $guardLimits;
    }

    /**
     * Create a SearchOutcome from an existing set of discovered paths and its guard report.
     *
     * @template TOutcome of Path
     *
     * @phpstan-template TOutcome of Path
     *
     * @psalm-template TOutcome as Path
     *
     * @param PathResultSet<TOutcome> $paths Discovered Path instances to include in the outcome.
     * @phpstan-param PathResultSet<TOutcome> $paths
     * @psalm-param PathResultSet<TOutcome> $paths
     *
     * @param SearchGuardReport $guardLimits Guard metrics and limits produced during the search.
     *
     * @return self<TOutcome> A SearchOutcome containing the provided paths and guard report.
     * @phpstan-return self<TOutcome>
     * @psalm-return self<TOutcome>
     */
    public static function fromResultSet(PathResultSet $paths, SearchGuardReport $guardLimits): self
    {
        return new self($paths, $guardLimits);
    }

    /**
     * Create a SearchOutcome with no paths while retaining the provided guard report.
     *
     * @param SearchGuardReport $guardLimits Guard-rail metrics and limits to include in the outcome.
     *
     * @return self<Path> A SearchOutcome containing an empty PathResultSet and the given guard limits.
     *
     * @phpstan-return self<Path>
     * @psalm-return self<Path>
     */
    public static function empty(SearchGuardReport $guardLimits): self
    {
        /** @var PathResultSet<Path> $emptyPaths */
        $emptyPaths = PathResultSet::empty();

        /** @var self<Path> $empty */
        $empty = self::fromResultSet($emptyPaths, $guardLimits);

        return $empty;
    }

    /**
     * @return PathResultSet<TPath>
     *
     * @phpstan-return PathResultSet<TPath>
     *
     * @psalm-return PathResultSet<TPath>
     */
    public function paths(): PathResultSet
    {
        return $this->paths;
    }

    /**
     * Get the best (first) path from the result set.
     *
     * @return TPath|null The first path from the result set, or `null` if none exist.
     *
     * @phpstan-return TPath|null
     * @psalm-return TPath|null
     */
    public function bestPath(): ?Path
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