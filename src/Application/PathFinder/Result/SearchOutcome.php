<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result;

use JsonSerializable;

/**
 * @template-covariant TPath of mixed
 *
 * @phpstan-template-covariant TPath of mixed
 *
 * @psalm-template-covariant TPath as mixed
 */
final class SearchOutcome implements JsonSerializable
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
     * @template TOutcome of mixed
     *
     * @phpstan-template TOutcome of mixed
     *
     * @psalm-template TOutcome as mixed
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
     * @return self<mixed>
     *
     * @phpstan-return self<mixed>
     *
     * @psalm-return self<mixed>
     */
    public static function empty(SearchGuardReport $guardLimits): self
    {
        /** @var PathResultSet<mixed> $emptyPaths */
        $emptyPaths = PathResultSet::empty();

        /** @var self<mixed> $empty */
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

    public function hasPaths(): bool
    {
        return !$this->paths->isEmpty();
    }

    public function guardLimits(): SearchGuardReport
    {
        return $this->guardLimits;
    }

    /**
     * @return array{
     *     paths: list<TPath>,
     *     guards: array{
     *         limits: array{expansions: int, visited_states: int, time_budget_ms: int|null},
     *         metrics: array{expansions: int, visited_states: int, elapsed_ms: float},
     *         breached: array{expansions: bool, visited_states: bool, time_budget: bool, any: bool}
     *     }
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'paths' => $this->paths->jsonSerialize(),
            'guards' => $this->guardLimits->jsonSerialize(),
        ];
    }
}
