<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

use SomeWork\P2PPathFinder\Application\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Application\Graph\EdgeSegmentCollection;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function array_filter;
use function array_values;
use function in_array;
use function sprintf;
use function usort;

/**
 * @internal
 */
final class SegmentPruner
{
    /**
     * @var EdgeSegmentCollection::MEASURE_BASE|EdgeSegmentCollection::MEASURE_QUOTE|EdgeSegmentCollection::MEASURE_GROSS_BASE
     */
    private readonly string $measure;

    public function __construct(string $measure)
    {
        $this->measure = $this->assertMeasure($measure);
    }

    public function prune(EdgeSegmentCollection $segments): EdgeSegmentCollection
    {
        if ($segments->isEmpty()) {
            return $segments;
        }

        $totals = $segments->capacityTotals($this->measure, 0);
        if (null === $totals) {
            return $segments;
        }

        if ($totals->optionalHeadroom()->isZero()) {
            $mandatory = array_values(array_filter(
                $segments->toArray(),
                static fn (EdgeSegment $segment): bool => $segment->isMandatory(),
            ));

            return EdgeSegmentCollection::fromArray($mandatory);
        }

        $targetScale = $totals->scale();
        $filtered = [];

        foreach ($segments as $segment) {
            if ($segment->isMandatory()) {
                $filtered[] = $segment;

                continue;
            }

            $maxCapacity = $this->capacityFor($segment)->max()->withScale($targetScale);
            if ($maxCapacity->isZero()) {
                continue;
            }

            $filtered[] = $segment;
        }

        usort(
            $filtered,
            function (EdgeSegment $left, EdgeSegment $right) use ($targetScale): int {
                if ($left->isMandatory() !== $right->isMandatory()) {
                    return $left->isMandatory() ? -1 : 1;
                }

                $rightMax = $this->capacityFor($right)->max()->withScale($targetScale);
                $leftMax = $this->capacityFor($left)->max()->withScale($targetScale);
                $comparison = $rightMax->compare($leftMax, $targetScale);

                if (0 !== $comparison) {
                    return $comparison;
                }

                $rightMin = $this->capacityFor($right)->min()->withScale($targetScale);
                $leftMin = $this->capacityFor($left)->min()->withScale($targetScale);

                return $rightMin->compare($leftMin, $targetScale);
            },
        );

        return EdgeSegmentCollection::fromArray($filtered);
    }

    private function capacityFor(EdgeSegment $segment): EdgeCapacity
    {
        return match ($this->measure) {
            EdgeSegmentCollection::MEASURE_BASE => $segment->base(),
            EdgeSegmentCollection::MEASURE_QUOTE => $segment->quote(),
            EdgeSegmentCollection::MEASURE_GROSS_BASE => $segment->grossBase(),
        };
    }

    /**
     * @return EdgeSegmentCollection::MEASURE_BASE|EdgeSegmentCollection::MEASURE_QUOTE|EdgeSegmentCollection::MEASURE_GROSS_BASE
     */
    private function assertMeasure(string $measure): string
    {
        if (!in_array($measure, EdgeSegmentCollection::MEASURES, true)) {
            throw new InvalidInput(sprintf('Unsupported segment capacity measure "%s".', $measure));
        }

        return $measure;
    }
}
