<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Support\Generator;

use Random\Engine\Mt19937;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function array_filter;
use function array_unique;
use function array_values;
use function count;

/**
 * Generates {@see PathEdgeSequence} fixtures with valid adjacency and alignment.
 */
final class PathEdgeSequenceGenerator
{
    use ProvidesRandomizedValues;

    private const CONVERSION_SCALE = 18;

    private Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer(new Mt19937());
    }

    protected function randomizer(): Randomizer
    {
        return $this->randomizer;
    }

    public function sequence(int $maxEdges = 6): PathEdgeSequence
    {
        return PathEdgeSequence::fromList($this->edges($maxEdges));
    }

    /**
     * @return list<PathEdge>
     */
    public function edges(int $maxEdges = 6): array
    {
        if ($maxEdges < 0) {
            $maxEdges = 0;
        }

        $edgeCount = $this->randomizer->getInt(0, $maxEdges);
        if (0 === $edgeCount) {
            return [];
        }

        $edges = [];
        $current = $this->randomCurrencyCode();
        $known = [$current];

        for ($index = 0; $index < $edgeCount; ++$index) {
            $next = $this->nextCurrency($known, $current);
            $side = 0 === $this->randomizer->getInt(0, 1) ? OrderSide::BUY : OrderSide::SELL;

            $amountScale = $this->randomizer->getInt(0, 6);
            $rateScale = $this->randomizer->getInt(0, 8);
            $amountBounds = $this->amountBounds($amountScale);
            $rateUnits = $this->randomizer->getInt(1, $this->safeUnitsUpperBound($rateScale, 7));
            $conversionUnits = $this->randomizer->getInt(1, $this->safeUnitsUpperBound(self::CONVERSION_SCALE, 3));

            $minAmount = $this->formatUnits($amountBounds['min'], $amountScale);
            $maxAmount = $this->formatUnits($amountBounds['max'], $amountScale);
            $rate = $this->formatUnits($rateUnits, $rateScale);
            $conversionRate = $this->formatUnits($conversionUnits, self::CONVERSION_SCALE);

            if (OrderSide::BUY === $side) {
                $base = $current;
                $quote = $next;
                $from = $base;
                $to = $quote;
            } else {
                $base = $next;
                $quote = $current;
                $from = $quote;
                $to = $base;
            }

            $order = OrderFactory::createOrder(
                $side,
                $base,
                $quote,
                $minAmount,
                $maxAmount,
                $rate,
                $amountScale,
                $rateScale,
            );

            $edges[] = PathEdge::create(
                $from,
                $to,
                $order,
                $order->effectiveRate(),
                $side,
                $conversionRate,
            );

            $current = $to;
            $known = array_values(array_unique([...$known, $next]));
        }

        return $edges;
    }

    /**
     * @param list<non-empty-string> $known
     *
     * @return non-empty-string
     */
    private function nextCurrency(array $known, string $current): string
    {
        if ([] !== $known && $this->randomizer->getInt(0, 100) < 55) {
            $candidates = array_values(array_unique(array_filter(
                $known,
                static fn (string $code): bool => $code !== $current,
            )));

            if ([] !== $candidates) {
                return $candidates[$this->randomizer->getInt(0, count($candidates) - 1)];
            }
        }

        do {
            $candidate = $this->randomCurrencyCode();
        } while ($candidate === $current);

        return $candidate;
    }

    /**
     * @return array{min: int, max: int}
     */
    private function amountBounds(int $scale): array
    {
        $upper = $this->safeUnitsUpperBound($scale, 9);
        $minUpper = max(1, $upper - 1);
        $minUnits = $this->randomizer->getInt(1, $minUpper);
        $maxUnits = $this->randomizer->getInt($minUnits + 1, $upper);

        return ['min' => $minUnits, 'max' => $maxUnits];
    }
}
