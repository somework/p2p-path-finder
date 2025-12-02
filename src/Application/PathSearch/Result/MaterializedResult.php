<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Result;

use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey;

/**
 * Immutable container representing a materialised path result and its ordering key.
 *
 * @internal
 */
final class MaterializedResult
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
}
