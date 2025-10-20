<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering;

/**
 * @psalm-type Payload = array<string, mixed>
 *
 * @phpstan-type Payload array<string, mixed>
 */
final class PathOrderKey
{
    /**
     * @param Payload $payload
     *
     * @phpstan-param string $cost
     *
     * @psalm-param numeric-string $cost
     */
    public function __construct(
        private readonly string $cost,
        private readonly int $hops,
        private readonly string $routeSignature,
        private readonly int $insertionOrder,
        private readonly array $payload = [],
    ) {
    }

    /**
     * @phpstan-return string
     *
     * @psalm-return numeric-string
     */
    public function cost(): string
    {
        return $this->cost;
    }

    public function hops(): int
    {
        return $this->hops;
    }

    public function routeSignature(): string
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
