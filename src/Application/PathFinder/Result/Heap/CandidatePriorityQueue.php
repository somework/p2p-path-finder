<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Heap;

use SplPriorityQueue;

/**
 * @internal
 *
 * @extends SplPriorityQueue<CandidatePriority, CandidateHeapEntry>
 */
final class CandidatePriorityQueue extends SplPriorityQueue
{
    public function __construct(private readonly int $scale)
    {
        $this->setExtractFlags(self::EXTR_DATA);
    }

    /**
     * @psalm-suppress ImplementedParamTypeMismatch
     *
     * @param CandidatePriority $priority1
     * @param CandidatePriority $priority2
     */
    public function compare(mixed $priority1, mixed $priority2): int
    {
        return $priority1->compare($priority2, $this->scale);
    }
}
