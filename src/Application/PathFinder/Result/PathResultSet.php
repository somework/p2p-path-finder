<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderStrategy;
use Traversable;

use function array_map;
use function array_slice;
use function count;
use function is_array;
use function is_object;
use function method_exists;
use function usort;

/**
 * Immutable collection of ordered path results.
 *
 * @template TPath of mixed
 *
 * @psalm-template TPath as mixed
 *
 * @implements IteratorAggregate<int, TPath>
 */
final class PathResultSet implements IteratorAggregate, Countable, JsonSerializable
{
    /**
     * @var list<TPath>
     */
    private readonly array $paths;

    /**
     * @param list<TPath> $paths
     */
    private function __construct(array $paths)
    {
        $this->paths = $paths;
    }

    /**
     * @return PathResultSet<mixed>
     */
    public static function empty(): self
    {
        /** @var PathResultSet<mixed> $empty */
        $empty = new self([]);

        return $empty;
    }

    /**
     * @template TCollected as mixed
     *
     * @param iterable<PathResultSetEntry<TCollected>> $entries
     *
     * @return PathResultSet<TCollected>
     */
    public static function fromEntries(PathOrderStrategy $orderingStrategy, iterable $entries): self
    {
        /** @var list<PathResultSetEntry<TCollected>> $collected */
        $collected = [];
        foreach ($entries as $entry) {
            /* @var PathResultSetEntry<TCollected> $entry */
            $collected[] = $entry;
        }

        $count = count($collected);
        if (0 === $count) {
            /** @var PathResultSet<TCollected> $empty */
            $empty = self::empty();

            return $empty;
        }

        usort(
            $collected,
            /**
             * @param PathResultSetEntry<TCollected> $left
             * @param PathResultSetEntry<TCollected> $right
             */
            static fn (PathResultSetEntry $left, PathResultSetEntry $right): int => $orderingStrategy->compare(
                $left->orderKey(),
                $right->orderKey(),
            ),
        );

        /** @var list<TCollected> $paths */
        $paths = [];
        $signatures = [];

        foreach ($collected as $entry) {
            $signature = $entry->orderKey()->routeSignature();
            if ('' !== $signature) {
                if (isset($signatures[$signature])) {
                    continue;
                }

                $signatures[$signature] = true;
            }

            $paths[] = $entry->path();
        }

        if ([] === $paths) {
            /** @var PathResultSet<TCollected> $empty */
            $empty = self::empty();

            return $empty;
        }

        return new self($paths);
    }

    /**
     * @return Traversable<int, TPath>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->paths);
    }

    public function count(): int
    {
        return count($this->paths);
    }

    public function isEmpty(): bool
    {
        return [] === $this->paths;
    }

    /**
     * @return list<TPath>
     */
    public function toArray(): array
    {
        return $this->paths;
    }

    /**
     * @return PathResultSet<TPath>
     */
    public function slice(int $offset, ?int $length = null): self
    {
        /** @var list<TPath> $sliced */
        $sliced = array_slice($this->paths, $offset, $length);

        if ([] === $sliced) {
            /** @var PathResultSet<TPath> $empty */
            $empty = self::empty();

            return $empty;
        }

        return new self($sliced);
    }

    /**
     * @return TPath|null
     */
    public function first(): mixed
    {
        return $this->paths[0] ?? null;
    }

    /**
     * @return list<mixed>
     */
    public function jsonSerialize(): array
    {
        /** @var list<mixed> $serialized */
        $serialized = array_map(
            static function (mixed $path): mixed {
                if ($path instanceof JsonSerializable) {
                    return $path->jsonSerialize();
                }

                if (is_object($path)) {
                    if (method_exists($path, 'jsonSerialize')) {
                        /* @psalm-suppress MixedMethodCall */
                        return $path->jsonSerialize();
                    }

                    if (method_exists($path, 'toArray')) {
                        /* @psalm-suppress MixedMethodCall */
                        return $path->toArray();
                    }
                }

                if (is_array($path)) {
                    return $path;
                }

                return $path;
            },
            $this->paths,
        );

        return $serialized;
    }
}
