<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

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
        return new self([$record->signature() => $record]);
    }

    public function register(SearchStateRecord $record, int $scale): int
    {
        $signature = $record->signature();
        $existing = $this->records[$signature] ?? null;

        if (null === $existing) {
            $this->records[$signature] = $record;

            return 1;
        }

        if ($record->dominates($existing, $scale)) {
            $this->records[$signature] = $record;
        }

        return 0;
    }

    public function isDominated(SearchStateRecord $record, int $scale): bool
    {
        $existing = $this->records[$record->signature()] ?? null;

        if (null === $existing) {
            return false;
        }

        return $existing->dominates($record, $scale);
    }

    public function hasSignature(string $signature): bool
    {
        return array_key_exists($signature, $this->records);
    }

    /**
     * @return list<SearchStateRecord>
     */
    public function all(): array
    {
        return array_values($this->records);
    }
}
