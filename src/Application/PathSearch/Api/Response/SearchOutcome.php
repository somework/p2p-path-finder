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
     * @param PathResultSet<TPath> $paths
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
     * @template TOutcome of Path
     *
     * @phpstan-template TOutcome of Path
     *
     * @psalm-template TOutcome as Path
     *
     * @param PathResultSet<TOutcome> $paths
     *
     * @phpstan-param PathResultSet<TOutcome> $paths
     *
     * @psalm-param PathResultSet<TOutcome> $paths
     *
     * @return self<TOutcome>
     *
     * @phpstan-return self<TOutcome>
     *
     * @psalm-return self<TOutcome>
     */
    public static function fromResultSet(PathResultSet $paths, SearchGuardReport $guardLimits): self
    {
        return new self($paths, $guardLimits);
    }

    /**
     * @return self<Path>
     *
     * @phpstan-return self<Path>
     *
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
     * @return TPath|null
     *
     * @phpstan-return TPath|null
     *
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
