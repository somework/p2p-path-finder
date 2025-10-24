<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use Traversable;

use function array_is_list;
use function count;

/**
 * Immutable ordered collection of {@see EdgeSegment} instances attached to a graph edge.
 *
 * @implements IteratorAggregate<int, EdgeSegment>
 */
final class EdgeSegmentCollection implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param list<EdgeSegment> $segments
     */
    private function __construct(private array $segments)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<array-key, EdgeSegment> $segments
     */
    public static function fromArray(array $segments): self
    {
        if (!array_is_list($segments)) {
            throw new InvalidInput('Graph edge segments must be provided as a list.');
        }

        foreach ($segments as $segment) {
            if (!$segment instanceof EdgeSegment) {
                throw new InvalidInput('Graph edge segments must be instances of EdgeSegment.');
            }
        }

        /* @var list<EdgeSegment> $segments */
        return new self($segments);
    }

    public function count(): int
    {
        return count($this->segments);
    }

    public function isEmpty(): bool
    {
        return [] === $this->segments;
    }

    /**
     * @return Traversable<int, EdgeSegment>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->segments);
    }

    /**
     * @return list<EdgeSegment>
     */
    public function toArray(): array
    {
        return $this->segments;
    }

    /**
     * @return list<array{
     *     isMandatory: bool,
     *     base: array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}},
     *     quote: array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}},
     *     grossBase: array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}},
     * }>
     */
    public function jsonSerialize(): array
    {
        $serialized = [];

        foreach ($this->segments as $segment) {
            $serialized[] = $segment->jsonSerialize();
        }

        return $serialized;
    }
}
