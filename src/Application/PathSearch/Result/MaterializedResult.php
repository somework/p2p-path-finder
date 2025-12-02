<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Result;

use JsonSerializable;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey;

/**
 * Immutable container representing a materialised path result and its ordering key.
 *
 * @internal
 */
final class MaterializedResult implements JsonSerializable
{
    public function __construct(
        private readonly PathResult $result,
        private readonly PathOrderKey $orderKey,
    ) {
    }

    public function result(): PathResult
    {
        return $this->result;
    }

    public function orderKey(): PathOrderKey
    {
        return $this->orderKey;
    }

    /**
     * @return PathResultSetEntry<PathResult>
     */
    public function toEntry(): PathResultSetEntry
    {
        return new PathResultSetEntry($this->result, $this->orderKey);
    }

    /**
     * @return array{
     *     totalSpent: array{currency: string, amount: string, scale: int},
     *     totalReceived: array{currency: string, amount: string, scale: int},
     *     residualTolerance: numeric-string,
     *     feeBreakdown: array<string, array{currency: string, amount: string, scale: int}>,
     *     legs: list<array{
     *         from: string,
     *         to: string,
     *         spent: array{currency: string, amount: string, scale: int},
     *         received: array{currency: string, amount: string, scale: int},
     *         fees: array<string, array{currency: string, amount: string, scale: int}>,
     *     }>,
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->result->jsonSerialize();
    }
}
