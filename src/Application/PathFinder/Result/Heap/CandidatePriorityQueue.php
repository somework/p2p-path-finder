<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Heap;

use InvalidArgumentException;
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
        if (!$priority1 instanceof CandidatePriority) {
            throw new InvalidArgumentException('Candidate priority queue expects candidate priorities.');
        }

        if (!$priority2 instanceof CandidatePriority) {
            throw new InvalidArgumentException('Candidate priority queue expects candidate priorities.');
        }

        return $priority1->compare($priority2, $this->scale);
    }
}
