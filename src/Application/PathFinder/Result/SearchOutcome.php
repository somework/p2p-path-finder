<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result;

use JsonSerializable;

final class SearchOutcome implements JsonSerializable
{
    /**
     * @var PathResultSet
     *
     * @phpstan-var PathResultSet<mixed>
     * @psalm-var PathResultSet
     */
    private readonly PathResultSet $paths;

    private readonly SearchGuardReport $guardLimits;

    /**
     * @param PathResultSet<mixed> $paths
     *
     * @psalm-param PathResultSet $paths
     *
     * @phpstan-param PathResultSet<mixed> $paths
     */
    public function __construct(PathResultSet $paths, SearchGuardReport $guardLimits)
    {
        $this->paths = $paths;
        $this->guardLimits = $guardLimits;
    }

    /**
     * @param PathResultSet<mixed> $paths
     *
     * @psalm-param PathResultSet $paths
     *
     * @phpstan-param PathResultSet<mixed> $paths
     *
     * @return SearchOutcome
     */
    public static function fromResultSet(PathResultSet $paths, SearchGuardReport $guardLimits): self
    {
        return new self($paths, $guardLimits);
    }

    /**
     * @return SearchOutcome
     */
    public static function empty(SearchGuardReport $guardLimits): self
    {
        return self::fromResultSet(PathResultSet::empty(), $guardLimits);
    }

    /**
     * @return PathResultSet
     *
     * @phpstan-return PathResultSet<mixed>
     * @psalm-return PathResultSet
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
