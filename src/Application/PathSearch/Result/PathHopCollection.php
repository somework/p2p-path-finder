<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Result;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SplFixedArray;
use Traversable;

use function array_diff_key;
use function array_is_list;
use function array_key_first;
use function count;

/**
 * Immutable ordered collection of {@see PathHop} instances.
 *
 * Enforces contiguity and uniqueness so routes can be consumed safely by
 * {@see Path} aggregations and downstream formatters.
 *
 * @implements IteratorAggregate<int, PathHop>
 *
 * @api
 */
final class PathHopCollection implements Countable, IteratorAggregate
{
    /**
     * @var SplFixedArray<PathHop>
     */
    private SplFixedArray $hops;

    /**
     * @param SplFixedArray<PathHop> $hops
     */
    private function __construct(SplFixedArray $hops)
    {
        $this->hops = $hops;
    }

    public static function empty(): self
    {
        /** @var SplFixedArray<PathHop> $empty */
        $empty = new SplFixedArray(0);

        return new self($empty);
    }

    /**
     * @param array<array-key, PathHop> $hops
     *
     * @throws InvalidInput when hops array is not a list, contains invalid elements, or cannot form a contiguous route
     */
    public static function fromList(array $hops): self
    {
        if ([] === $hops) {
            /** @var SplFixedArray<PathHop> $empty */
            $empty = new SplFixedArray(0);

            return new self($empty);
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

        return new self(self::toFixedArray(self::sortContiguously($normalized)));
    }

    public function count(): int
    {
        return $this->hops->count();
    }

    /**
     * @return Traversable<int, PathHop>
     */
    public function getIterator(): Traversable
    {
        /** @var list<PathHop> $hops */
        $hops = $this->hops->toArray();

        return new ArrayIterator($hops);
    }

    /**
     * @return list<PathHop>
     */
    public function all(): array
    {
        /** @var list<PathHop> $hops */
        $hops = $this->hops->toArray();

        return $hops;
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
        return 0 === $this->hops->count();
    }

    /**
     * @throws InvalidInput when index does not reference an existing position
     */
    public function at(int $index): PathHop
    {
        $count = $this->hops->count();
        if (0 > $index || $index >= $count) {
            throw new InvalidInput('Path hop index must reference an existing position.');
        }

        /** @var PathHop $hop */
        $hop = $this->hops[$index];

        return $hop;
    }

    public function first(): ?PathHop
    {
        if ($this->isEmpty()) {
            return null;
        }

        return $this->hops[0];
    }

    public function last(): ?PathHop
    {
        if ($this->isEmpty()) {
            return null;
        }

        $lastIndex = $this->hops->count() - 1;

        /** @var PathHop $hop */
        $hop = $this->hops[$lastIndex];

        return $hop;
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

    /**
     * @param list<PathHop> $hops
     *
     * @return SplFixedArray<PathHop>
     */
    private static function toFixedArray(array $hops): SplFixedArray
    {
        /** @var SplFixedArray<PathHop> $fixed */
        $fixed = new SplFixedArray(count($hops));

        foreach ($hops as $index => $hop) {
            $fixed[$index] = $hop;
        }

        return $fixed;
    }
}
