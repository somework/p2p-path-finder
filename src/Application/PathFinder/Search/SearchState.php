<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function is_string;

/**
 * Immutable representation of a search frontier state.
 */
final class SearchState
{
    /**
     * @param numeric-string                     $cost
     * @param numeric-string                     $product
     * @param array<string, bool>                $visited
     * @param array{min: Money, max: Money}|null $amountRange
     */
    private function __construct(
        private readonly string $node,
        private readonly string $cost,
        private readonly string $product,
        private readonly int $hops,
        private readonly PathEdgeSequence $path,
        private readonly ?array $amountRange,
        private readonly ?Money $desiredAmount,
        private readonly array $visited,
    ) {
        if ('' === $this->node) {
            throw new InvalidArgumentException('Search states require a non-empty node identifier.');
        }

        if ($this->hops < 0) {
            throw new InvalidArgumentException('Search state hop counts must be non-negative.');
        }

        BcMath::ensureNumeric($this->cost, $this->product);

        if (!isset($this->visited[$this->node]) || true !== $this->visited[$this->node]) {
            throw new InvalidArgumentException('Search states must mark the current node as visited.');
        }

        foreach ($this->visited as $key => $value) {
            if (!is_string($key) || '' === $key) {
                throw new InvalidArgumentException('Visited nodes must be indexed by non-empty strings.');
            }

            if (true !== $value) {
                throw new InvalidArgumentException('Visited node markers must be set to true.');
            }
        }
    }

    /**
     * @param numeric-string                     $unitValue
     * @param array{min: Money, max: Money}|null $amountRange
     */
    public static function bootstrap(
        string $node,
        string $unitValue,
        ?array $amountRange,
        ?Money $desiredAmount
    ): self {
        BcMath::ensureNumeric($unitValue, $unitValue);

        return new self(
            $node,
            $unitValue,
            $unitValue,
            0,
            PathEdgeSequence::empty(),
            $amountRange,
            $desiredAmount,
            [$node => true],
        );
    }

    /**
     * @param numeric-string                     $cost
     * @param numeric-string                     $product
     * @param array{min: Money, max: Money}|null $amountRange
     * @param array<string, bool>                $visited
     */
    public static function fromComponents(
        string $node,
        string $cost,
        string $product,
        int $hops,
        PathEdgeSequence $path,
        ?array $amountRange,
        ?Money $desiredAmount,
        array $visited
    ): self {
        return new self($node, $cost, $product, $hops, $path, $amountRange, $desiredAmount, $visited);
    }

    /**
     * @param numeric-string                     $nextCost
     * @param numeric-string                     $nextProduct
     * @param array{min: Money, max: Money}|null $amountRange
     */
    public function transition(
        string $nextNode,
        string $nextCost,
        string $nextProduct,
        PathEdge $edge,
        ?array $amountRange,
        ?Money $desiredAmount
    ): self {
        $visited = $this->visited;
        $visited[$nextNode] = true;

        return new self(
            $nextNode,
            $nextCost,
            $nextProduct,
            $this->hops + 1,
            $this->path->append($edge),
            $amountRange,
            $desiredAmount,
            $visited,
        );
    }

    public function node(): string
    {
        return $this->node;
    }

    /**
     * @return numeric-string
     */
    public function cost(): string
    {
        return $this->cost;
    }

    /**
     * @return numeric-string
     */
    public function product(): string
    {
        return $this->product;
    }

    public function hops(): int
    {
        return $this->hops;
    }

    public function path(): PathEdgeSequence
    {
        return $this->path;
    }

    /**
     * @return array{min: Money, max: Money}|null
     */
    public function amountRange(): ?array
    {
        return $this->amountRange;
    }

    public function desiredAmount(): ?Money
    {
        return $this->desiredAmount;
    }

    /**
     * @return array<string, bool>
     */
    public function visited(): array
    {
        return $this->visited;
    }

    public function hasVisited(string $node): bool
    {
        return isset($this->visited[$node]);
    }
}
