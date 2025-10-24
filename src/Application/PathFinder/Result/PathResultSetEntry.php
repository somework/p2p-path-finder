<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result;

use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;

/**
 * @template TPath of mixed
 */
final class PathResultSetEntry
{
    /**
     * @param TPath $path
     */
    public function __construct(
        private readonly mixed $path,
        private readonly PathOrderKey $orderKey,
    ) {
    }

    /**
     * @return TPath
     */
    public function path(): mixed
    {
        return $this->path;
    }

    public function orderKey(): PathOrderKey
    {
        return $this->orderKey;
    }
}
