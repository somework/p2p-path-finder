<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Engine\Queue;

use SomeWork\P2PPathFinder\Application\PathSearch\Model\CandidatePath;

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
