<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use Traversable;

use function array_is_list;
use function count;
use function in_array;
use function sprintf;

/**
 * Immutable ordered collection of {@see EdgeSegment} instances attached to a graph edge.
 *
 * @internal
 *
 * @implements IteratorAggregate<int, EdgeSegment>
 */
final class EdgeSegmentCollection implements Countable, IteratorAggregate, JsonSerializable
{
    public const MEASURE_BASE = 'base';
    public const MEASURE_QUOTE = 'quote';
    public const MEASURE_GROSS_BASE = 'grossBase';

    public const MEASURES = [
        self::MEASURE_BASE,
        self::MEASURE_QUOTE,
        self::MEASURE_GROSS_BASE,
    ];

    /**
     * @param list<EdgeSegment>                                                                                   $segments
     * @param array<string, array{currency: string|null, scale: int, mandatory: Money|null, maximum: Money|null}> $capacityMetrics
     */
    private function __construct(private array $segments, private readonly array $capacityMetrics)
    {
    }

    public static function empty(): self
    {
        return new self([], self::initializeCapacityMetrics());
    }

    /**
     * @param array<array-key, EdgeSegment> $segments
     */
    public static function fromArray(array $segments): self
    {
        if (!array_is_list($segments)) {
            throw new InvalidInput('Graph edge segments must be provided as a list.');
        }

        foreach ($segments as $segment) {
            if (!$segment instanceof EdgeSegment) {
                throw new InvalidInput('Graph edge segments must be instances of EdgeSegment.');
            }
        }

        $metrics = self::initializeCapacityMetrics();

        foreach ($segments as $segment) {
            self::accumulateMetrics($metrics[self::MEASURE_BASE], $segment->base(), $segment->isMandatory());
            self::accumulateMetrics($metrics[self::MEASURE_QUOTE], $segment->quote(), $segment->isMandatory());
            self::accumulateMetrics($metrics[self::MEASURE_GROSS_BASE], $segment->grossBase(), $segment->isMandatory());
        }

        /* @var list<EdgeSegment> $segments */

        return new self($segments, $metrics);
    }

    public function count(): int
    {
        return count($this->segments);
    }

    public function isEmpty(): bool
    {
        return [] === $this->segments;
    }

    /**
     * @return Traversable<int, EdgeSegment>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->segments);
    }

    /**
     * @return list<EdgeSegment>
     */
    public function toArray(): array
    {
        return $this->segments;
    }

    public function capacityTotals(string $measure, int $scale): ?SegmentCapacityTotals
    {
        $this->assertSupportedMeasure($measure);

        $metric = $this->capacityMetrics[$measure];
        if (null === $metric['mandatory'] || null === $metric['maximum']) {
            return null;
        }

        $targetScale = max($scale, $metric['scale']);

        return new SegmentCapacityTotals(
            $metric['mandatory']->withScale($targetScale),
            $metric['maximum']->withScale($targetScale),
        );
    }

    public function capacityScale(string $measure): int
    {
        $this->assertSupportedMeasure($measure);

        return $this->capacityMetrics[$measure]['scale'];
    }

    /**
     * @return list<array{
     *     isMandatory: bool,
     *     base: array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}},
     *     quote: array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}},
     *     grossBase: array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}},
     * }>
     */
    public function jsonSerialize(): array
    {
        $serialized = [];

        foreach ($this->segments as $segment) {
            $serialized[] = $segment->jsonSerialize();
        }

        return $serialized;
    }

    /**
     * @return array<string, array{currency: string|null, scale: int, mandatory: Money|null, maximum: Money|null}>
     */
    private static function initializeCapacityMetrics(): array
    {
        return [
            self::MEASURE_BASE => [
                'currency' => null,
                'scale' => 0,
                'mandatory' => null,
                'maximum' => null,
            ],
            self::MEASURE_QUOTE => [
                'currency' => null,
                'scale' => 0,
                'mandatory' => null,
                'maximum' => null,
            ],
            self::MEASURE_GROSS_BASE => [
                'currency' => null,
                'scale' => 0,
                'mandatory' => null,
                'maximum' => null,
            ],
        ];
    }

    /**
     * @param array{currency: string|null, scale: int, mandatory: Money|null, maximum: Money|null} $metric
     */
    private static function accumulateMetrics(array &$metric, EdgeCapacity $capacity, bool $isMandatory): void
    {
        $currency = $metric['currency'] ?? $capacity->min()->currency();
        $metric['currency'] = $currency;
        $segmentScale = max(
            $metric['scale'],
            $capacity->min()->scale(),
            $capacity->max()->scale(),
        );

        if (null === $metric['mandatory'] || null === $metric['maximum']) {
            $metric['mandatory'] = Money::zero($currency, $segmentScale);
            $metric['maximum'] = Money::zero($currency, $segmentScale);
        } elseif ($segmentScale !== $metric['scale']) {
            $metric['mandatory'] = $metric['mandatory']->withScale($segmentScale);
            $metric['maximum'] = $metric['maximum']->withScale($segmentScale);
        }

        if ($isMandatory) {
            $metric['mandatory'] = $metric['mandatory']->add($capacity->min()->withScale($segmentScale));
        }

        $metric['maximum'] = $metric['maximum']->add($capacity->max()->withScale($segmentScale));

        $metric['scale'] = $segmentScale;
    }

    private function assertSupportedMeasure(string $measure): void
    {
        if (!in_array($measure, self::MEASURES, true)) {
            throw new InvalidInput(sprintf('Unsupported segment capacity measure "%s".', $measure));
        }
    }
}
