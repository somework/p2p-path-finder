<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

/**
 * Global registry tracking the best states seen at each node across all search paths.
 *
 * ## Purpose
 *
 * The registry maintains the best (lowest cost, fewest hops) states per node per signature.
 * This enables:
 * 1. **Dominance pruning**: Skip expanding states worse than known states
 * 2. **State counting**: Track unique (node, signature) pairs for guard limits
 * 3. **Efficiency**: Avoid re-exploring dominated paths
 *
 * ## Structure
 *
 * ```
 * SearchStateRegistry
 *   ├─ Node "USD" → SearchStateRecordCollection
 *   │    ├─ Signature "range:100-200" → Record(cost:1.0, hops:0)
 *   │    └─ Signature "range:50-100" → Record(cost:1.5, hops:1)
 *   └─ Node "GBP" → SearchStateRecordCollection
 *        └─ Signature "range:80-160" → Record(cost:0.8, hops:1)
 * ```
 *
 * ## Delta Tracking
 *
 * `register()` returns a delta indicating state count changes:
 * - **+1**: New signature registered (increases visited states)
 * - **0**: Existing signature updated or skipped (no count change)
 *
 * @internal
 */
final class SearchStateRegistry
{
    /**
     * @var array<string, SearchStateRecordCollection>
     */
    private array $records;

    /**
     * @param array<string, SearchStateRecordCollection> $records
     */
    private function __construct(array $records)
    {
        $this->records = $records;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function withInitial(string $node, SearchStateRecord $record): self
    {
        return new self([$node => SearchStateRecordCollection::withInitial($record)]);
    }

    public function isEmpty(): bool
    {
        return [] === $this->records;
    }

    /**
     * @return list<SearchStateRecord>
     */
    public function recordsFor(string $node): array
    {
        $collection = $this->records[$node] ?? null;

        if (null === $collection) {
            return [];
        }

        return $collection->all();
    }

    /**
     * @return array{self, int} Returns new instance and registration delta (1 if new, 0 if update/skip)
     */
    public function register(string $node, SearchStateRecord $record, int $scale): array
    {
        $collection = $this->records[$node] ?? SearchStateRecordCollection::empty();
        [$newCollection, $delta] = $collection->register($record, $scale);

        $records = $this->records;
        $records[$node] = $newCollection;

        return [new self($records), $delta];
    }

    public function isDominated(string $node, SearchStateRecord $record, int $scale): bool
    {
        $collection = $this->records[$node] ?? null;

        if (null === $collection) {
            return false;
        }

        return $collection->isDominated($record, $scale);
    }

    public function hasSignature(string $node, SearchStateSignature $signature): bool
    {
        $collection = $this->records[$node] ?? null;

        if (null === $collection) {
            return false;
        }

        return $collection->hasSignature($signature);
    }

    public function __clone()
    {
        // Deep clone: clone each collection in the records array
        $clonedRecords = [];
        foreach ($this->records as $node => $collection) {
            $clonedRecords[$node] = clone $collection;
        }
        $this->records = $clonedRecords;
    }
}
