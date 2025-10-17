<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result;

/**
 * @template TPath of mixed
 */
final class SearchOutcome
{
    /**
     * @var list<TPath>
     */
    private readonly array $paths;

    private readonly GuardLimitStatus $guardLimits;

    /**
     * @param list<TPath> $paths
     */
    public function __construct(array $paths, GuardLimitStatus $guardLimits)
    {
        $this->paths = $paths;
        $this->guardLimits = $guardLimits;
    }

    /**
     * @return list<TPath>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    public function hasPaths(): bool
    {
        return [] !== $this->paths;
    }

    public function guardLimits(): GuardLimitStatus
    {
        return $this->guardLimits;
    }
}
