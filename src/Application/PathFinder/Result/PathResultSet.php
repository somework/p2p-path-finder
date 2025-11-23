<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderStrategy;
use Traversable;

use function array_map;
use function array_slice;
use function count;
use function get_debug_type;
use function is_array;
use function sprintf;
use function usort;

/**
 * Immutable collection of ordered path results.
 *
 * @template-covariant TPath of mixed
 *
 * @phpstan-template-covariant TPath of mixed
 *
 * @psalm-template-covariant TPath as mixed
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
     * @return PathResultSet<TPath>
     *
     * @psalm-return PathResultSet<TPath>
     */
    public static function empty(): self
    {
        /** @var PathResultSet<TPath> $empty */
        $empty = new self([]);

        return $empty;
    }

    /**
     * @template TIn of mixed
     *
     * @psalm-template TIn as mixed
     *
     * @param iterable<PathResultSetEntry<TIn>> $entries
     *
     * @return PathResultSet<TIn>
     *
     * @internal
     */
    public static function fromEntries(PathOrderStrategy $orderingStrategy, iterable $entries): self
    {
        /** @var list<PathResultSetEntry<TIn>> $collected */
        $collected = [];
        foreach ($entries as $entry) {
            /* @var PathResultSetEntry<TIn> $entry */
            $collected[] = $entry;
        }

        $count = count($collected);
        if (0 === $count) {
            /** @var PathResultSet<TIn> $empty */
            $empty = self::empty();

            return $empty;
        }

        usort(
            $collected,
            /**
             * @param PathResultSetEntry<TIn> $left
             * @param PathResultSetEntry<TIn> $right
             */
            static fn (PathResultSetEntry $left, PathResultSetEntry $right): int => $orderingStrategy->compare(
                $left->orderKey(),
                $right->orderKey(),
            ),
        );

        /** @var list<TIn> $paths */
        $paths = [];
        $signatures = [];

        foreach ($collected as $entry) {
            $signature = $entry->orderKey()->routeSignature();
            $signatureKey = $signature->value();
            if ('' !== $signatureKey) {
                if (isset($signatures[$signatureKey])) {
                    continue;
                }

                $signatures[$signatureKey] = true;
            }

            $paths[] = $entry->path();
        }

        if ([] === $paths) {
            /** @var PathResultSet<TIn> $empty */
            $empty = self::empty();

            return $empty;
        }

        return new self($paths);
    }

    /**
     * @template TIn of mixed
     *
     * @psalm-template TIn as mixed
     *
     * @param iterable<TIn>                    $paths
     * @param callable(TIn, int): PathOrderKey $orderKeyResolver
     *
     * @return PathResultSet<TIn>
     */
    public static function fromPaths(
        PathOrderStrategy $orderingStrategy,
        iterable $paths,
        callable $orderKeyResolver,
    ): self {
        /** @var list<PathResultSetEntry<TIn>> $entries */
        $entries = [];

        $index = 0;
        foreach ($paths as $path) {
            $orderKey = $orderKeyResolver($path, $index);
            /* @phpstan-ignore-next-line instanceof.alwaysTrue */
            if (!$orderKey instanceof PathOrderKey) {
                throw new InvalidArgumentException(sprintf('Path order key resolver must return an instance of %s, %s returned.', PathOrderKey::class, get_debug_type($orderKey)));
            }

            /** @var PathResultSetEntry<TIn> $entry */
            $entry = new PathResultSetEntry($path, $orderKey);

            $entries[] = $entry;
            ++$index;
        }

        return self::fromEntries($orderingStrategy, $entries);
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

                if (is_array($path)) {
                    return $path;
                }

                // Fallback for objects with toArray method
                if (is_object($path) && method_exists($path, 'toArray')) {
                    return $path->toArray();
                }

                return $path;
            },
            $this->paths,
        );

        return $serialized;
    }
}
