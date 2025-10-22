<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use JsonSerializable;

/**
 * Describes a segmented portion of edge capacity, indicating mandatory and optional fills.
 */
final class EdgeSegment implements JsonSerializable
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

    /**
     * @return array{
     *     isMandatory: bool,
     *     base: array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}},
     *     quote: array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}},
     *     grossBase: array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}},
     * }
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'isMandatory' => $this->isMandatory,
            'base' => $this->base->jsonSerialize(),
            'quote' => $this->quote->jsonSerialize(),
            'grossBase' => $this->grossBase->jsonSerialize(),
        ];
    }
}
