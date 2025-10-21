<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Service;

use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\Result\PathResult;

/**
 * Immutable container representing a materialised path result and its ordering key.
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
}
