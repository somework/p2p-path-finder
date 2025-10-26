<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result;

use JsonSerializable;

/**
 * @template TPath of mixed
 */
final class SearchOutcome implements JsonSerializable
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
     * @param PathResultSet<TPath> $paths
     *
     * @return SearchOutcome<TPath>
     *
     * @psalm-return SearchOutcome<TPath>
     */
    public static function fromResultSet(PathResultSet $paths, SearchGuardReport $guardLimits): self
    {
        /** @var SearchOutcome<TPath> $outcome */
        $outcome = new self($paths, $guardLimits);

        return $outcome;
    }

    /**
     * @return SearchOutcome<TPath>
     *
     * @psalm-return SearchOutcome<TPath>
     */
    public static function empty(SearchGuardReport $guardLimits): self
    {
        /** @var PathResultSet<TPath> $emptyPaths */
        $emptyPaths = PathResultSet::empty();

        return self::fromResultSet($emptyPaths, $guardLimits);
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

    /**
     * @return array{
     *     paths: list<mixed>,
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
