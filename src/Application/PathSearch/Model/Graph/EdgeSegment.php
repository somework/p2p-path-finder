<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph;

/**
 * Describes a segmented portion of edge capacity, indicating mandatory and optional fills.
 *
 * @internal
 */
final class EdgeSegment
{
    public function __construct(
        private readonly bool $isMandatory,
        private readonly EdgeCapacity $base,
        private readonly EdgeCapacity $quote,
        private readonly EdgeCapacity $grossBase,
    ) {
    }

    public function isMandatory(): bool
    {
        return $this->isMandatory;
    }

    public function base(): EdgeCapacity
    {
        return $this->base;
    }

    public function quote(): EdgeCapacity
    {
        return $this->quote;
    }

    public function grossBase(): EdgeCapacity
    {
        return $this->grossBase;
    }
}
