<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering;

/**
 * @psalm-type Payload = array<string, mixed>
 *
 * @phpstan-type Payload array<string, mixed>
 */
final class PathOrderKey
{
    /**
     * @param Payload $payload
     */
    public function __construct(
        private readonly PathCost $cost,
        private readonly int $hops,
        private readonly RouteSignature $routeSignature,
        private readonly int $insertionOrder,
        private readonly array $payload = [],
    ) {
    }

    public function cost(): PathCost
    {
        return $this->cost;
    }

    public function hops(): int
    {
        return $this->hops;
    }

    public function routeSignature(): RouteSignature
    {
        return $this->routeSignature;
    }

    public function insertionOrder(): int
    {
        return $this->insertionOrder;
    }

    /**
     * @return Payload
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
