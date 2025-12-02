<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Helpers\Generator;

use Random\Engine\Mt19937;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchStateSignature;

use function array_key_exists;
use function chr;
use function count;
use function implode;
use function ord;
use function str_contains;

/**
 * Generates {@see SearchStateSignature} instances with realistic segments.
 */
final class SearchStateSignatureGenerator
{
    use ProvidesRandomizedValues;

    private Randomizer $randomizer;
    private PathEdgeSequenceGenerator $pathEdges;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer(new Mt19937());
        $this->pathEdges = new PathEdgeSequenceGenerator($this->randomizer);
    }

    protected function randomizer(): Randomizer
    {
        return $this->randomizer;
    }

    /**
     * @return array{0: SearchStateSignature, 1: array<string, string>}
     */
    public function signature(int $maxSegments = 4): array
    {
        $segments = $this->segments($maxSegments);

        return [SearchStateSignature::compose($segments), $segments];
    }

    /**
     * @return array<string, string>
     */
    public function segments(int $maxSegments = 4): array
    {
        $target = max(2, $maxSegments);
        $segments = [];

        $this->appendSpendSegments($segments);

        while (count($segments) < $target) {
            $choice = $this->randomizer->getInt(0, 99);

            if ($choice < 35 && !array_key_exists('signature', $segments)) {
                $segments['signature'] = $this->routeSignatureValue();

                continue;
            }

            if ($choice < 65 && !array_key_exists('pruned', $segments)) {
                $segments['pruned'] = $this->randomizer->getInt(0, 1) ? 'true' : 'false';

                continue;
            }

            $label = $this->uniqueLabel($segments);
            $segments[$label] = $this->customSegmentValue();
        }

        return $segments;
    }

    /**
     * @param array<string, string> $segments
     */
    private function appendSpendSegments(array &$segments): void
    {
        $currency = $this->randomCurrencyCode();
        $scale = $this->randomizer->getInt(0, 6);
        $upper = $this->safeUnitsUpperBound($scale, 7);
        $minUpper = max(0, $upper - 2);
        $minUnits = $this->randomizer->getInt(0, $minUpper);
        $maxUnits = $this->randomizer->getInt(max($minUnits + 1, 1), $upper);

        $minAmount = $this->formatUnits($minUnits, $scale);
        $maxAmount = $this->formatUnits($maxUnits, $scale);

        $segments['range'] = implode(':', [$currency, $minAmount, $maxAmount, $scale]);

        if ($this->randomizer->getInt(0, 100) < 35) {
            $segments['desired'] = 'null';

            return;
        }

        $desiredUnits = $this->randomizer->getInt($minUnits, $maxUnits);
        $desiredAmount = $this->formatUnits($desiredUnits, $scale);
        $segments['desired'] = implode(':', [$currency, $desiredAmount, $scale]);
    }

    private function routeSignatureValue(): string
    {
        $sequence = $this->pathEdges->sequence(4);
        $signature = RouteSignature::fromPathEdgeSequence($sequence);

        if ('' !== $signature->value()) {
            return $signature->value();
        }

        $node = $this->randomCurrencyCode();

        return RouteSignature::fromNodes([$node])->value();
    }

    /**
     * @param array<string, string> $existing
     *
     * @return non-empty-string
     */
    private function uniqueLabel(array $existing): string
    {
        do {
            $length = $this->randomizer->getInt(3, 8);
            $label = '';

            for ($index = 0; $index < $length; ++$index) {
                $label .= chr($this->randomizer->getInt(ord('a'), ord('z')));
            }
        } while (array_key_exists($label, $existing) || str_contains($label, ':') || str_contains($label, '|'));

        return $label;
    }

    private function customSegmentValue(): string
    {
        $parts = [];
        $count = $this->randomizer->getInt(1, 3);

        for ($index = 0; $index < $count; ++$index) {
            if (0 === $this->randomizer->getInt(0, 1)) {
                $parts[] = (string) $this->randomizer->getInt(0, 999);

                continue;
            }

            $parts[] = $this->randomCurrencyCode();
        }

        return implode(':', $parts);
    }
}
