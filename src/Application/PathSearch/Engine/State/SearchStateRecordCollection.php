<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Engine\State;

use function array_key_exists;

/**
 * @internal
 */
final class SearchStateRecordCollection
{
    /**
     * @var array<string, SearchStateRecord>
     */
    private array $records;

    /**
     * @param array<string, SearchStateRecord> $records
     */
    private function __construct(array $records)
    {
        $this->records = $records;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function withInitial(SearchStateRecord $record): self
    {
        return new self([$record->signature()->value() => $record]);
    }

    /**
     * @return array{self, int} Returns new instance and registration delta (1 if new, 0 if update/skip)
     */
    public function register(SearchStateRecord $record, int $scale): array
    {
        $signature = $record->signature();
        $key = $signature->value();
        $existing = $this->records[$key] ?? null;

        if (null === $existing) {
            $records = $this->records;
            $records[$key] = $record;

            return [new self($records), 1];
        }

        if ($record->dominates($existing, $scale)) {
            $records = $this->records;
            $records[$key] = $record;

            return [new self($records), 0];
        }

        return [$this, 0];
    }

    public function isDominated(SearchStateRecord $record, int $scale): bool
    {
        $key = $record->signature()->value();
        $existing = $this->records[$key] ?? null;

        if (null === $existing) {
            return false;
        }

        return $existing->dominates($record, $scale);
    }

    public function hasSignature(SearchStateSignature $signature): bool
    {
        return array_key_exists($signature->value(), $this->records);
    }

    /**
     * @return list<SearchStateRecord>
     */
    public function all(): array
    {
        return array_values($this->records);
    }

    public function __clone()
    {
        // Deep clone: copy the records array
        // SearchStateRecord objects are immutable value objects, so we only need to copy the array
        $this->records = [...$this->records];
    }
}
