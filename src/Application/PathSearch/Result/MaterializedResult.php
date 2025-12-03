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
        private readonly Path $result,
        private readonly PathOrderKey $orderKey,
    ) {
    }

    public function result(): Path
    {
        return $this->result;
    }

    public function orderKey(): PathOrderKey
    {
        return $this->orderKey;
    }

    /**
     * @return PathResultSetEntry<Path>
     */
    public function toEntry(): PathResultSetEntry
    {
        return new PathResultSetEntry($this->result, $this->orderKey);
    }
}
