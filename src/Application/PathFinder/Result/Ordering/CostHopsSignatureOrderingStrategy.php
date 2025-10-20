<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering;

use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;

final class CostHopsSignatureOrderingStrategy implements PathOrderStrategy
{
    public function __construct(private readonly int $costScale)
    {
    }

    public function compare(PathOrderKey $left, PathOrderKey $right): int
    {
        BcMath::ensureNumeric($left->cost(), $right->cost());

        $costComparison = BcMath::comp($left->cost(), $right->cost(), $this->costScale);
        if (0 !== $costComparison) {
            return $costComparison;
        }

        $hopComparison = $left->hops() <=> $right->hops();
        if (0 !== $hopComparison) {
            return $hopComparison;
        }

        $signatureComparison = $left->routeSignature() <=> $right->routeSignature();
        if (0 !== $signatureComparison) {
            return $signatureComparison;
        }

        return $left->insertionOrder() <=> $right->insertionOrder();
    }
}
