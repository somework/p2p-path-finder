<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Support\Generator;

use Brick\Math\BigDecimal;
use Random\Engine\Mt19937;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateRecord;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateSignature;

use function count;

/**
 * Generates search state records with varied dominance relationships for registry tests.
 */
final class SearchStateRecordGenerator
{
    use ProvidesRandomizedValues;

    private const COST_SCALE = 18;

    private Randomizer $randomizer;
    private SearchStateSignatureGenerator $signatures;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer(new Mt19937());
        $this->signatures = new SearchStateSignatureGenerator($this->randomizer);
    }

    protected function randomizer(): Randomizer
    {
        return $this->randomizer;
    }

    /**
     * @return list<array{node: string, record: SearchStateRecord, signature: SearchStateSignature}>
     */
    public function recordOperations(int $maxNodes = 4, int $maxSignaturesPerNode = 4, int $maxExtraRecords = 3): array
    {
        $nodeCount = $this->randomizer->getInt(1, $maxNodes);
        $nodes = [];

        for ($index = 0; $index < $nodeCount; ++$index) {
            $nodes[] = 'NODE-'.$index;
        }

        $operations = [];

        foreach ($nodes as $node) {
            $signatureCount = $this->randomizer->getInt(1, $maxSignaturesPerNode);

            for ($index = 0; $index < $signatureCount; ++$index) {
                [$signature] = $this->signatures->signature();

                $baseline = $this->baselineRecord($signature);
                $operations[] = ['node' => $node, 'record' => $baseline, 'signature' => $signature];

                $dominating = $this->dominatingRecord($signature, $baseline);
                $operations[] = ['node' => $node, 'record' => $dominating, 'signature' => $signature];

                $dominated = $this->dominatedRecord($signature, $baseline);
                $operations[] = ['node' => $node, 'record' => $dominated, 'signature' => $signature];

                $extras = $this->randomizer->getInt(0, $maxExtraRecords);
                for ($extra = 0; $extra < $extras; ++$extra) {
                    $operations[] = ['node' => $node, 'record' => $this->randomRecord($signature), 'signature' => $signature];
                }
            }
        }

        return $this->shuffle($operations);
    }

    private function baselineRecord(SearchStateSignature $signature): SearchStateRecord
    {
        $upper = $this->safeUnitsUpperBound(self::COST_SCALE, 6);
        $units = $this->randomizer->getInt(1, max(1, $upper - 3));
        $cost = $this->formatUnits($units, self::COST_SCALE);
        $hops = $this->randomizer->getInt(1, 6);

        return new SearchStateRecord(BigDecimal::of($cost), $hops, $signature);
    }

    private function dominatingRecord(SearchStateSignature $signature, SearchStateRecord $baseline): SearchStateRecord
    {
        $baseUnits = $this->parseUnits($baseline->cost(), self::COST_SCALE);
        $units = $baseUnits > 0 ? $this->randomizer->getInt(0, $baseUnits - 1) : 0;
        $cost = $this->formatUnits($units, self::COST_SCALE);
        $hops = max(0, $baseline->hops() - 1);

        return new SearchStateRecord(BigDecimal::of($cost), $hops, $signature);
    }

    private function dominatedRecord(SearchStateSignature $signature, SearchStateRecord $baseline): SearchStateRecord
    {
        $baseUnits = $this->parseUnits($baseline->cost(), self::COST_SCALE);
        $upper = $this->safeUnitsUpperBound(self::COST_SCALE, 6);
        $units = $this->randomizer->getInt($baseUnits + 1, $upper);
        $cost = $this->formatUnits($units, self::COST_SCALE);
        $hops = $baseline->hops() + 1;

        return new SearchStateRecord(BigDecimal::of($cost), $hops, $signature);
    }

    private function randomRecord(SearchStateSignature $signature): SearchStateRecord
    {
        $units = $this->randomizer->getInt(0, $this->safeUnitsUpperBound(self::COST_SCALE, 6));
        $cost = $this->formatUnits($units, self::COST_SCALE);
        $hops = $this->randomizer->getInt(0, 8);

        return new SearchStateRecord(BigDecimal::of($cost), $hops, $signature);
    }

    /**
     * @template T
     *
     * @param list<T> $values
     *
     * @return list<T>
     */
    private function shuffle(array $values): array
    {
        for ($index = count($values) - 1; $index > 0; --$index) {
            $swap = $this->randomizer->getInt(0, $index);

            if ($swap === $index) {
                continue;
            }

            $temporary = $values[$index];
            $values[$index] = $values[$swap];
            $values[$swap] = $temporary;
        }

        return $values;
    }
}
