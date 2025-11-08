<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result;

use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;

/**
 * @template TPath of mixed
 *
 * @psalm-template TPath as mixed
 *
 * @internal
 */
final class PathResultSetEntry
{
    /**
     * @psalm-var TPath
     */
    private readonly mixed $path;

    private readonly PathOrderKey $orderKey;

    /**
     * @psalm-param TPath $path
     */
    public function __construct(mixed $path, PathOrderKey $orderKey)
    {
        $this->path = $path;
        $this->orderKey = $orderKey;
    }

    /**
     * @psalm-return TPath
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
