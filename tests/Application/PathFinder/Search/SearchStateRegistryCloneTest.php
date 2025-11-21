<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Search;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateRecord;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateRecordCollection;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateRegistry;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateSignature;

/**
 * Tests verifying proper deep cloning behavior for search state structures.
 */
final class SearchStateRegistryCloneTest extends TestCase
{
    private const SCALE = 18;

    public function test_cloned_registry_is_independent_from_original(): void
    {
        $original = SearchStateRegistry::withInitial(
            'USD',
            new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:alpha')),
        );

        $clone = clone $original;

        // Verify clone is a different object
        self::assertNotSame($original, $clone);

        // Register on clone returns new instance - doesn't affect original or clone
        [$newClone] = $clone->register(
            'EUR',
            new SearchStateRecord(BigDecimal::of('2.0'), 2, SearchStateSignature::fromString('sig:beta')),
            self::SCALE,
        );

        // Original should only have USD
        self::assertCount(1, $original->recordsFor('USD'));
        self::assertCount(0, $original->recordsFor('EUR'));

        // Original clone (before register) should also only have USD
        self::assertCount(1, $clone->recordsFor('USD'));
        self::assertCount(0, $clone->recordsFor('EUR'));

        // New clone instance should have both
        self::assertCount(1, $newClone->recordsFor('USD'));
        self::assertCount(1, $newClone->recordsFor('EUR'));
    }

    public function test_cloned_registry_nested_collections_are_independent(): void
    {
        $original = SearchStateRegistry::withInitial(
            'USD',
            new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:initial')),
        );

        $clone = clone $original;

        // Update original by creating a new instance
        [$newOriginal] = $original->register(
            'USD',
            new SearchStateRecord(BigDecimal::of('0.5'), 0, SearchStateSignature::fromString('sig:improved')),
            self::SCALE,
        );

        // Clone should still have original record (unchanged)
        $cloneRecords = $clone->recordsFor('USD');
        self::assertCount(1, $cloneRecords);
        self::assertTrue(BigDecimal::of('1.0')->isEqualTo($cloneRecords[0]->cost()));
        self::assertSame('sig:initial', $cloneRecords[0]->signature()->value());

        // New original should have updated record
        $newOriginalRecords = $newOriginal->recordsFor('USD');
        self::assertCount(2, $newOriginalRecords);
        $signatures = array_map(
            static fn (SearchStateRecord $r): string => $r->signature()->value(),
            $newOriginalRecords,
        );
        self::assertContains('sig:initial', $signatures);
        self::assertContains('sig:improved', $signatures);
    }

    public function test_cloned_collection_is_independent_from_original(): void
    {
        $original = SearchStateRecordCollection::withInitial(
            new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:alpha')),
        );

        $clone = clone $original;

        // Verify clone is a different object
        self::assertNotSame($original, $clone);

        // Register on clone returns new instance - doesn't affect original or clone
        [$newClone] = $clone->register(
            new SearchStateRecord(BigDecimal::of('2.0'), 2, SearchStateSignature::fromString('sig:beta')),
            self::SCALE,
        );

        // Original should only have one record
        self::assertCount(1, $original->all());
        self::assertSame('sig:alpha', $original->all()[0]->signature()->value());

        // Clone should also only have original record
        self::assertCount(1, $clone->all());
        self::assertSame('sig:alpha', $clone->all()[0]->signature()->value());

        // New collection from register should have both
        self::assertCount(2, $newClone->all());
        $signatures = array_map(
            static fn (SearchStateRecord $r): string => $r->signature()->value(),
            $newClone->all(),
        );
        self::assertContains('sig:alpha', $signatures);
        self::assertContains('sig:beta', $signatures);
    }

    public function test_cloned_collection_dominance_checks_are_independent(): void
    {
        $original = SearchStateRecordCollection::withInitial(
            new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:test')),
        );

        $clone = clone $original;

        // Add better record to clone
        [$newClone] = $clone->register(
            new SearchStateRecord(BigDecimal::of('0.5'), 0, SearchStateSignature::fromString('sig:test')),
            self::SCALE,
        );

        // Original should consider the worse record dominated
        $worseRecord = new SearchStateRecord(BigDecimal::of('1.5'), 2, SearchStateSignature::fromString('sig:test'));
        self::assertTrue($original->isDominated($worseRecord, self::SCALE));

        // Clone should also use original baseline
        self::assertTrue($clone->isDominated($worseRecord, self::SCALE));

        // New clone with better record should dominate even the original record
        $originalRecord = new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:test'));
        self::assertTrue($newClone->isDominated($originalRecord, self::SCALE));
    }

    public function test_registry_clone_preserves_empty_state(): void
    {
        $original = SearchStateRegistry::empty();
        $clone = clone $original;

        self::assertNotSame($original, $clone);
        self::assertTrue($original->isEmpty());
        self::assertTrue($clone->isEmpty());

        // Add to clone
        [$newClone] = $clone->register(
            'USD',
            new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:new')),
            self::SCALE,
        );

        // Original and old clone remain empty
        self::assertTrue($original->isEmpty());
        self::assertTrue($clone->isEmpty());
        self::assertFalse($newClone->isEmpty());
    }

    public function test_collection_clone_preserves_signature_checks(): void
    {
        $signature = SearchStateSignature::fromString('sig:unique');
        $original = SearchStateRecordCollection::withInitial(
            new SearchStateRecord(BigDecimal::of('1.0'), 1, $signature),
        );

        $clone = clone $original;

        self::assertTrue($original->hasSignature($signature));
        self::assertTrue($clone->hasSignature($signature));

        // Different signature should not exist
        $otherSignature = SearchStateSignature::fromString('sig:other');
        self::assertFalse($original->hasSignature($otherSignature));
        self::assertFalse($clone->hasSignature($otherSignature));
    }
}
