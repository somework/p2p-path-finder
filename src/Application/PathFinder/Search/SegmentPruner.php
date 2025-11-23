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
 * Filters and sorts edge segments based on capacity availability.
 *
 * ## Purpose
 *
 * The SegmentPruner removes zero-capacity segments and orders segments to prioritize
 * those that can best fulfill path requirements. Mandatory segments (representing
 * minimum order bounds) are always preserved and placed first.
 *
 * ## Pruning Strategy
 *
 * 1. **Zero Optional Headroom**: If `mandatory == maximum`, discard all optional segments
 * 2. **Positive Headroom**: Keep mandatory segments + non-zero optional segments
 * 3. **Zero-Capacity**: Always discard optional segments with max capacity == 0
 *
 * ## Sorting Strategy
 *
 * Segments are ordered by:
 * 1. **Type**: Mandatory segments before optional segments
 * 2. **Max Capacity**: Higher max capacity first (DESC)
 * 3. **Min Capacity**: Higher min capacity first (DESC, tie-breaker)
 *
 * ## Mandatory vs Optional Segments
 *
 * - **Mandatory**: Represents capacity that MUST be filled (e.g., order minimums due to fees)
 * - **Optional**: Represents additional capacity that MAY be filled up to the maximum
 *
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
