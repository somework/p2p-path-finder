<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

/**
 * @internal
 */
final class InsertionOrderCounter
{
    /**
     * @var int<0, max>
     */
    private int $value;

    public function __construct(int $value = 0)
    {
        $this->value = self::guardNonNegative($value, 'Insertion counters must start at a non-negative value.');
    }

    /**
     * @phpstan-return int<0, max>
     *
     * @psalm-return int<0, max>
     */
    public function next(): int
    {
        return $this->value++;
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
            throw new \InvalidArgumentException($message);
        }

        return $value;
    }
}
