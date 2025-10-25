<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering;

use Override;

final class CostHopsSignatureOrderingStrategy implements PathOrderStrategy
{
    public function __construct(private readonly int $costScale)
    {
    }

    #[Override]
    public function compare(PathOrderKey $left, PathOrderKey $right): int
    {
        $costComparison = $left->cost()->compare($right->cost(), $this->costScale);
        if (0 !== $costComparison) {
            return $costComparison;
        }

        $hopComparison = $left->hops() <=> $right->hops();
        if (0 !== $hopComparison) {
            return $hopComparison;
        }

        $signatureComparison = $left->routeSignature()->compare($right->routeSignature());
        if (0 !== $signatureComparison) {
            return $signatureComparison;
        }

        return $left->insertionOrder() <=> $right->insertionOrder();
    }
}
