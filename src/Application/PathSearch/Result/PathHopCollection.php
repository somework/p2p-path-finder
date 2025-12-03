<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Result;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use Traversable;

use function array_diff_key;
use function array_is_list;
use function array_key_first;
use function count;

/**
 * Immutable ordered collection of {@see PathHop} instances.
 *
 * @implements IteratorAggregate<int, PathHop>
 *
 * @api
 */
final class PathHopCollection implements Countable, IteratorAggregate
{
    /**
     * @var list<PathHop>
     */
    private array $hops;

    /**
     * @param list<PathHop> $hops
     */
    private function __construct(array $hops)
    {
        $this->hops = $hops;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<array-key, PathHop> $hops
     *
     * @throws InvalidInput when hops array is not a list, contains invalid elements, or cannot form a contiguous route
     */
    public static function fromList(array $hops): self
    {
        if ([] === $hops) {
            return new self([]);
        }

        if (!array_is_list($hops)) {
            throw new InvalidInput('Path hops must be provided as a list.');
        }

        /** @var list<PathHop> $normalized */
        $normalized = [];
        foreach ($hops as $hop) {
            /* @phpstan-ignore-next-line instanceof.alwaysTrue */
            if (!$hop instanceof PathHop) {
                throw new InvalidInput('Every path hop must be an instance of PathHop.');
            }

            $normalized[] = $hop;
        }

        return new self(self::sortContiguously($normalized));
    }

    public function count(): int
    {
        return count($this->hops);
    }

    /**
     * @return Traversable<int, PathHop>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->hops);
    }

    /**
     * @return list<PathHop>
     */
    public function all(): array
    {
        return $this->hops;
    }

    /**
     * @return list<PathHop>
     */
    public function toArray(): array
    {
        return $this->all();
    }

    public function isEmpty(): bool
    {
        return [] === $this->hops;
    }

    /**
     * @throws InvalidInput when index does not reference an existing position
     */
    public function at(int $index): PathHop
    {
        if (!isset($this->hops[$index])) {
            throw new InvalidInput('Path hop index must reference an existing position.');
        }

        return $this->hops[$index];
    }

    public function first(): ?PathHop
    {
        return $this->hops[0] ?? null;
    }

    public function last(): ?PathHop
    {
        if ($this->isEmpty()) {
            return null;
        }

        $lastIndex = array_key_last($this->hops);
        if (null === $lastIndex) {
            return null;
        }

        return $this->hops[$lastIndex];
    }

    /**
     * @param list<PathHop> $hops
     *
     * @return list<PathHop>
     */
    private static function sortContiguously(array $hops): array
    {
        if ([] === $hops) {
            return $hops;
        }

        /** @var array<string, PathHop> $byOrigin */
        $byOrigin = [];
        /** @var array<string, true> $destinations */
        $destinations = [];

        foreach ($hops as $hop) {
            $from = $hop->from();
            $to = $hop->to();

            if (isset($byOrigin[$from])) {
                throw new InvalidInput('Path hops must be unique.');
            }

            $byOrigin[$from] = $hop;
            $destinations[$to] = true;
        }

        $startCandidates = array_diff_key($byOrigin, $destinations);
        if (1 !== count($startCandidates)) {
            throw new InvalidInput('Path hops must form a contiguous route.');
        }

        /** @var string|null $currentAsset */
        $currentAsset = array_key_first($startCandidates);
        if (null === $currentAsset) {
            throw new InvalidInput('Path hops must form a contiguous route.');
        }
        $sorted = [];
        /** @var array<string, true> $visitedDestinations */
        $visitedDestinations = [];

        while (isset($byOrigin[$currentAsset])) {
            $hop = $byOrigin[$currentAsset];
            unset($byOrigin[$currentAsset]);

            $destination = $hop->to();
            if (isset($visitedDestinations[$destination])) {
                throw new InvalidInput('Path hops must form a contiguous route.');
            }

            $sorted[] = $hop;
            $visitedDestinations[$destination] = true;
            $currentAsset = $destination;
        }

        if ([] !== $byOrigin) {
            throw new InvalidInput('Path hops must form a contiguous route.');
        }

        return $sorted;
    }
}
