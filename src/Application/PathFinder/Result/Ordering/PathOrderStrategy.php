<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering;

interface PathOrderStrategy
{
    public function compare(PathOrderKey $left, PathOrderKey $right): int;
}
