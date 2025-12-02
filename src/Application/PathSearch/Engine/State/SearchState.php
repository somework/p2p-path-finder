<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Engine\State;

use Brick\Math\BigDecimal;
use InvalidArgumentException;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdgeSequence;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\SpendRange;
use SomeWork\P2PPathFinder\Domain\Money\Money;

use function is_string;

/**
 * Immutable representation of a search frontier state.
 *
 * @internal
 */
final class SearchState
{
    /**
     * @var int<0, max>
     */
    private readonly int $hops;

    /**
     * @var non-empty-array<string, bool>
     */
    private readonly array $visited;

    /**
     * @param array<array-key, bool> $visited
     */
    private function __construct(
        private readonly string $node,
        private readonly BigDecimal $cost,
        private readonly BigDecimal $product,
        int $hops,
        private readonly PathEdgeSequence $path,
        private readonly ?SpendRange $amountRange,
        private readonly ?Money $desiredAmount,
        array $visited,
    ) {
        $this->hops = self::guardNonNegative($hops, 'Search state hop counts must be non-negative.');

        if ('' === $this->node) {
            throw new InvalidArgumentException('Search states require a non-empty node identifier.');
        }

        self::assertVisitedRegistry($visited, $this->node);

        /* @var non-empty-array<string, bool> $visited */
        $this->visited = $visited;
    }

    public static function bootstrap(
        string $node,
        BigDecimal $unitValue,
        ?SpendRange $amountRange,
        ?Money $desiredAmount
    ): self {
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
     * @param array<array-key, bool> $visited
     *
     * @phpstan-param int<0, max> $hops
     *
     * @psalm-param int<0, max>   $hops
     */
    public static function fromComponents(
        string $node,
        BigDecimal $cost,
        BigDecimal $product,
        int $hops,
        PathEdgeSequence $path,
        ?SpendRange $amountRange,
        ?Money $desiredAmount,
        array $visited
    ): self {
        return new self($node, $cost, $product, $hops, $path, $amountRange, $desiredAmount, $visited);
    }

    /**
     * @phpstan-assert int<0, max> $value
     *
     * @psalm-assert int<0, max> $value
     *
     * @return int<0, max>
     */
    private static function guardNonNegative(int $value, string $message): int
    {
        if ($value < 0) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    public function transition(
        string $nextNode,
        BigDecimal $nextCost,
        BigDecimal $nextProduct,
        PathEdge $edge,
        ?SpendRange $amountRange,
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

    /**
     * @param array<array-key, bool> $visited
     *
     * @psalm-assert non-empty-array<string, bool> $visited
     */
    private static function assertVisitedRegistry(array $visited, string $currentNode): void
    {
        if (!isset($visited[$currentNode]) || true !== $visited[$currentNode]) {
            throw new InvalidArgumentException('Search states must mark the current node as visited.');
        }

        foreach ($visited as $key => $value) {
            if (!is_string($key) || '' === $key) {
                throw new InvalidArgumentException('Visited nodes must be indexed by non-empty strings.');
            }

            if (true !== $value) {
                throw new InvalidArgumentException('Visited node markers must be set to true.');
            }
        }
    }

    public function node(): string
    {
        return $this->node;
    }

    /**
     * @return numeric-string
     *
     * @phpstan-return numeric-string
     */
    public function cost(): string
    {
        /** @var numeric-string $value */
        $value = $this->cost->__toString();

        return $value;
    }

    public function costDecimal(): BigDecimal
    {
        return $this->cost;
    }

    /**
     * @return numeric-string
     *
     * @phpstan-return numeric-string
     */
    public function product(): string
    {
        /** @var numeric-string $value */
        $value = $this->product->__toString();

        return $value;
    }

    public function productDecimal(): BigDecimal
    {
        return $this->product;
    }

    /**
     * @phpstan-return int<0, max>
     *
     * @psalm-return int<0, max>
     */
    public function hops(): int
    {
        return $this->hops;
    }

    public function path(): PathEdgeSequence
    {
        return $this->path;
    }

    public function amountRange(): ?SpendRange
    {
        return $this->amountRange;
    }

    public function desiredAmount(): ?Money
    {
        return $this->desiredAmount;
    }

    /**
     * @return non-empty-array<string, bool>
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
