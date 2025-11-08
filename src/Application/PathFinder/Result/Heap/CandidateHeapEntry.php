<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Heap;

use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath;

/**
 * @internal
 */
final class CandidateHeapEntry
{
    public function __construct(
        private readonly CandidatePath $candidate,
        private readonly CandidatePriority $priority
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
}
