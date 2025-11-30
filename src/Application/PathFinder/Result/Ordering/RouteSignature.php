<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering;

use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function implode;
use function is_int;
use function sprintf;
use function trim;

final class RouteSignature implements \Stringable
{
    /**
     * @var list<string>
     */
    private readonly array $nodes;

    private readonly string $value;

    /**
     * @param iterable<array-key, string> $nodes
     *
     * @throws InvalidInput when any node is empty after trimming whitespace
     */
    private function __construct(iterable $nodes)
    {
        $normalized = [];
        foreach ($nodes as $position => $node) {
            $node = trim($node);

            if ('' === $node) {
                $index = is_int($position)
                    ? (string) $position
                    : $position;

                throw new InvalidInput(sprintf('Route signature nodes cannot be empty (index %s).', $index));
            }

            $normalized[] = $node;
        }

        $this->nodes = $normalized;
        $this->value = implode('->', $normalized);
    }

    /**
     * @internal
     */
    public static function fromPathEdgeSequence(PathEdgeSequence $edges): self
    {
        if ($edges->isEmpty()) {
            return new self([]);
        }

        $first = $edges->first();
        if (null === $first) {
            return new self([]);
        }

        $nodes = [$first->from()];

        foreach ($edges as $edge) {
            $nodes[] = $edge->to();
        }

        return new self($nodes);
    }

    /**
     * @param list<string> $nodes
     */
    public static function fromNodes(array $nodes): self
    {
        return new self($nodes);
    }

    /**
     * @return list<string>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function compare(self $other): int
    {
        return $this->value <=> $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
