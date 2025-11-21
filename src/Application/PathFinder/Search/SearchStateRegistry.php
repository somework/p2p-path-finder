<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

/**
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
