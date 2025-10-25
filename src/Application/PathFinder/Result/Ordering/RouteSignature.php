<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering;

use function implode;
use function trim;

final class RouteSignature
{
    /**
     * @var list<string>
     */
    private readonly array $nodes;

    private readonly string $value;

    /**
     * @param iterable<string> $nodes
     */
    public function __construct(iterable $nodes)
    {
        $normalized = [];
        foreach ($nodes as $node) {
            $node = trim($node);

            if ('' === $node && [] === $normalized) {
                continue;
            }

            $normalized[] = $node;
        }

        $this->nodes = $normalized;
        $this->value = implode('->', $normalized);
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
