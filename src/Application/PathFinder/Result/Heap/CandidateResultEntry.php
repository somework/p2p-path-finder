<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Heap;

use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath;

final class CandidateResultEntry
{
    public function __construct(
        private readonly CandidatePath $candidate,
        private readonly CandidatePriority $priority,
        private readonly string $routeSignature,
        private readonly PathOrderKey $orderKey
    ) {
    }

    public function candidate(): CandidatePath
    {
        return $this->candidate;
    }

    public function priority(): CandidatePriority
    {
        return $this->priority;
    }

    public function routeSignature(): string
    {
        return $this->routeSignature;
    }

    public function orderKey(): PathOrderKey
    {
        return $this->orderKey;
    }
}
