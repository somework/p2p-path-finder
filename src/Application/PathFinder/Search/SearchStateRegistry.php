<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

final class SearchStateRegistry
{
    /**
     * @param array<string, list<SearchStateRecord>> $records
     */
    private function __construct(private array $records)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function withInitial(string $node, SearchStateRecord $record): self
    {
        return new self([$node => [$record]]);
    }

    /**
     * @return list<SearchStateRecord>
     */
    public function recordsFor(string $node): array
    {
        return $this->records[$node] ?? [];
    }

    public function register(string $node, SearchStateRecord $record, int $scale): int
    {
        $existing = $this->records[$node] ?? [];
        $removed = 0;

        foreach ($existing as $index => $candidate) {
            if ($candidate->signature() !== $record->signature()) {
                continue;
            }

            if ($record->dominates($candidate, $scale)) {
                unset($existing[$index]);
                ++$removed;
            }
        }

        $existing[] = $record;
        $this->records[$node] = array_values($existing);

        return 1 - $removed;
    }

    public function isDominated(string $node, SearchStateRecord $record, int $scale): bool
    {
        foreach ($this->records[$node] ?? [] as $existing) {
            if ($existing->signature() !== $record->signature()) {
                continue;
            }

            if ($existing->dominates($record, $scale)) {
                return true;
            }
        }

        return false;
    }

    public function hasSignature(string $node, string $signature): bool
    {
        foreach ($this->records[$node] ?? [] as $existing) {
            if ($existing->signature() === $signature) {
                return true;
            }
        }

        return false;
    }
}
