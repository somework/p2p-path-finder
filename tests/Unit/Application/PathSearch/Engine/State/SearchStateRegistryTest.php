<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine\State;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchStateRecord;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchStateRecordCollection;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchStateRegistry;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchStateSignature;

/**
 * Unit tests for SearchStateRegistry behavioral functionality.
 */
#[CoversClass(SearchStateRegistry::class)]
final class SearchStateRegistryTest extends TestCase
{
    private const SCALE = 18;

    public function test_registry_register_returns_correct_delta_values(): void
    {
        $registry = SearchStateRegistry::empty();

        // First registration should return delta = 1 (new signature)
        $record1 = new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:new'));
        [$registry, $delta1] = $registry->register('USD', $record1, self::SCALE);
        self::assertSame(1, $delta1);

        // Second registration with same signature but worse cost should return delta = 0 (update)
        $record2 = new SearchStateRecord(BigDecimal::of('1.5'), 2, SearchStateSignature::fromString('sig:new'));
        [$registry, $delta2] = $registry->register('USD', $record2, self::SCALE);
        self::assertSame(0, $delta2);

        // Third registration with same signature but better cost should return delta = 0 (update)
        $record3 = new SearchStateRecord(BigDecimal::of('0.5'), 0, SearchStateSignature::fromString('sig:new'));
        [$registry, $delta3] = $registry->register('USD', $record3, self::SCALE);
        self::assertSame(0, $delta3);

        // Fourth registration with different signature should return delta = 1 (new)
        $record4 = new SearchStateRecord(BigDecimal::of('2.0'), 1, SearchStateSignature::fromString('sig:different'));
        [$registry, $delta4] = $registry->register('USD', $record4, self::SCALE);
        self::assertSame(1, $delta4);

        // Registration with worse record that doesn't dominate should return delta = 0 (skip)
        $record5 = new SearchStateRecord(BigDecimal::of('3.0'), 3, SearchStateSignature::fromString('sig:new'));
        [$registry, $delta5] = $registry->register('USD', $record5, self::SCALE);
        self::assertSame(0, $delta5);
    }

    public function test_collection_register_returns_correct_delta_values(): void
    {
        $collection = SearchStateRecordCollection::empty();

        // First registration should return delta = 1 (new signature)
        $record1 = new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:new'));
        [$collection, $delta1] = $collection->register($record1, self::SCALE);
        self::assertSame(1, $delta1);

        // Second registration with same signature but worse cost should return delta = 0 (update)
        $record2 = new SearchStateRecord(BigDecimal::of('1.5'), 2, SearchStateSignature::fromString('sig:new'));
        [$collection, $delta2] = $collection->register($record2, self::SCALE);
        self::assertSame(0, $delta2);

        // Third registration with same signature but better cost should return delta = 0 (update)
        $record3 = new SearchStateRecord(BigDecimal::of('0.5'), 0, SearchStateSignature::fromString('sig:new'));
        [$collection, $delta3] = $collection->register($record3, self::SCALE);
        self::assertSame(0, $delta3);

        // Fourth registration with different signature should return delta = 1 (new)
        $record4 = new SearchStateRecord(BigDecimal::of('2.0'), 1, SearchStateSignature::fromString('sig:different'));
        [$collection, $delta4] = $collection->register($record4, self::SCALE);
        self::assertSame(1, $delta4);

        // Registration with worse record that doesn't dominate should return delta = 0 (skip)
        $record5 = new SearchStateRecord(BigDecimal::of('3.0'), 3, SearchStateSignature::fromString('sig:new'));
        [$collection, $delta5] = $collection->register($record5, self::SCALE);
        self::assertSame(0, $delta5);
    }

    public function test_registry_operations_with_multiple_nodes(): void
    {
        $registry = SearchStateRegistry::empty();

        // Add records for multiple nodes
        $usdRecord = new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:usd'));
        [$registry, $delta1] = $registry->register('USD', $usdRecord, self::SCALE);
        self::assertSame(1, $delta1);

        $eurRecord = new SearchStateRecord(BigDecimal::of('0.8'), 1, SearchStateSignature::fromString('sig:eur'));
        [$registry, $delta2] = $registry->register('EUR', $eurRecord, self::SCALE);
        self::assertSame(1, $delta2);

        $gbpRecord = new SearchStateRecord(BigDecimal::of('0.7'), 1, SearchStateSignature::fromString('sig:gbp'));
        [$registry, $delta3] = $registry->register('GBP', $gbpRecord, self::SCALE);
        self::assertSame(1, $delta3);

        // Verify each node has its records
        self::assertCount(1, $registry->recordsFor('USD'));
        self::assertCount(1, $registry->recordsFor('EUR'));
        self::assertCount(1, $registry->recordsFor('GBP'));
        self::assertCount(0, $registry->recordsFor('JPY')); // Non-existent node

        // Verify signatures exist for correct nodes
        self::assertTrue($registry->hasSignature('USD', SearchStateSignature::fromString('sig:usd')));
        self::assertTrue($registry->hasSignature('EUR', SearchStateSignature::fromString('sig:eur')));
        self::assertTrue($registry->hasSignature('GBP', SearchStateSignature::fromString('sig:gbp')));

        // Verify signatures don't exist for wrong nodes
        self::assertFalse($registry->hasSignature('USD', SearchStateSignature::fromString('sig:eur')));
        self::assertFalse($registry->hasSignature('EUR', SearchStateSignature::fromString('sig:usd')));
    }

    public function test_dominance_checks_with_scale_variations(): void
    {
        // Test dominance checks with different scales
        $collection = SearchStateRecordCollection::withInitial(
            new SearchStateRecord(BigDecimal::of('1.005'), 1, SearchStateSignature::fromString('sig:test')),
        );

        // At scale 2, 1.005 rounds to 1.01, so 1.004 rounds to 1.00
        // The existing record (1.01, hops=1) does NOT dominate the lower record (1.00, hops=0)
        // because the lower record has better cost and same/fewer hops
        $lowerRecord = new SearchStateRecord(BigDecimal::of('1.004'), 0, SearchStateSignature::fromString('sig:test'));
        self::assertFalse($collection->isDominated($lowerRecord, 2));

        // At scale 3, 1.005 stays 1.005, 1.004 stays 1.004
        // Existing record still does NOT dominate lower record
        self::assertFalse($collection->isDominated($lowerRecord, 3));

        // At scale 1, both round to 1.0, existing has hops=1, lower has hops=0
        // Existing record does NOT dominate (same cost but more hops)
        self::assertFalse($collection->isDominated($lowerRecord, 1));

        // Test with a record that should be dominated
        $worseRecord = new SearchStateRecord(BigDecimal::of('1.006'), 2, SearchStateSignature::fromString('sig:test'));
        // At scale 3: existing (1.005, hops=1) vs worse (1.006, hops=2)
        // Existing dominates worse because lower cost and fewer hops
        self::assertTrue($collection->isDominated($worseRecord, 3));
    }

    public function test_records_with_same_cost_different_hops(): void
    {
        $collection = SearchStateRecordCollection::withInitial(
            new SearchStateRecord(BigDecimal::of('1.0'), 2, SearchStateSignature::fromString('sig:same-cost')),
        );

        // Record with same cost but fewer hops should dominate
        $betterHops = new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:same-cost'));
        [$newCollection, $delta] = $collection->register($betterHops, self::SCALE);
        self::assertSame(0, $delta); // Updated existing
        self::assertCount(1, $newCollection->all());
        self::assertSame(1, $newCollection->all()[0]->hops());

        // Record with same cost but more hops should not dominate
        $worseHops = new SearchStateRecord(BigDecimal::of('1.0'), 3, SearchStateSignature::fromString('sig:same-cost'));
        [$finalCollection, $finalDelta] = $newCollection->register($worseHops, self::SCALE);
        self::assertSame(0, $finalDelta); // No change
        self::assertSame($newCollection, $finalCollection); // Same instance returned
    }

    public function test_empty_collection_edge_cases(): void
    {
        $empty = SearchStateRecordCollection::empty();

        self::assertCount(0, $empty->all());
        self::assertFalse($empty->hasSignature(SearchStateSignature::fromString('sig:any')));

        // Any record should not be dominated in empty collection
        $record = new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:test'));
        self::assertFalse($empty->isDominated($record, self::SCALE));

        // Registering to empty collection should return delta = 1
        [$newCollection, $delta] = $empty->register($record, self::SCALE);
        self::assertSame(1, $delta);
        self::assertCount(1, $newCollection->all());
    }

    public function test_registry_is_empty_method(): void
    {
        $emptyRegistry = SearchStateRegistry::empty();
        self::assertTrue($emptyRegistry->isEmpty());

        // Add a record
        $record = new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:test'));
        [$nonEmptyRegistry] = $emptyRegistry->register('USD', $record, self::SCALE);
        self::assertFalse($nonEmptyRegistry->isEmpty());

        // Remove all records by registering a better one for a different node
        $betterRecord = new SearchStateRecord(BigDecimal::of('0.5'), 0, SearchStateSignature::fromString('sig:test'));
        [$stillNonEmpty] = $nonEmptyRegistry->register('EUR', $betterRecord, self::SCALE);
        self::assertFalse($stillNonEmpty->isEmpty());
    }

    public function test_registry_is_dominated_method(): void
    {
        $registry = SearchStateRegistry::empty();

        // Add an initial record
        $existingRecord = new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:test'));
        [$registry] = $registry->register('USD', $existingRecord, self::SCALE);

        // Test with non-existent node
        $anyRecord = new SearchStateRecord(BigDecimal::of('0.5'), 0, SearchStateSignature::fromString('sig:any'));
        self::assertFalse($registry->isDominated('EUR', $anyRecord, self::SCALE));

        // Test with existing node but different signature
        $differentSigRecord = new SearchStateRecord(BigDecimal::of('0.5'), 0, SearchStateSignature::fromString('sig:different'));
        self::assertFalse($registry->isDominated('USD', $differentSigRecord, self::SCALE));

        // Test with existing node and same signature but worse record
        $worseRecord = new SearchStateRecord(BigDecimal::of('1.5'), 2, SearchStateSignature::fromString('sig:test'));
        self::assertTrue($registry->isDominated('USD', $worseRecord, self::SCALE));

        // Test with existing node and same signature but better record
        $betterRecord = new SearchStateRecord(BigDecimal::of('0.5'), 0, SearchStateSignature::fromString('sig:test'));
        self::assertFalse($registry->isDominated('USD', $betterRecord, self::SCALE));
    }

    public function test_registry_has_signature_method(): void
    {
        $registry = SearchStateRegistry::empty();

        // Test with empty registry
        self::assertFalse($registry->hasSignature('USD', SearchStateSignature::fromString('sig:any')));

        // Add a record
        $record = new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:test'));
        [$registry] = $registry->register('USD', $record, self::SCALE);

        // Test existing signature on existing node
        self::assertTrue($registry->hasSignature('USD', SearchStateSignature::fromString('sig:test')));

        // Test non-existing signature on existing node
        self::assertFalse($registry->hasSignature('USD', SearchStateSignature::fromString('sig:other')));

        // Test any signature on non-existing node
        self::assertFalse($registry->hasSignature('EUR', SearchStateSignature::fromString('sig:test')));
    }

    public function test_records_for_method_edge_cases(): void
    {
        $registry = SearchStateRegistry::empty();

        // Test with empty registry
        self::assertSame([], $registry->recordsFor('USD'));
        self::assertSame([], $registry->recordsFor('nonexistent'));

        // Add records to different nodes
        $usdRecord1 = new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:usd1'));
        $usdRecord2 = new SearchStateRecord(BigDecimal::of('2.0'), 2, SearchStateSignature::fromString('sig:usd2'));
        $eurRecord = new SearchStateRecord(BigDecimal::of('0.8'), 1, SearchStateSignature::fromString('sig:eur'));

        [$registry] = $registry->register('USD', $usdRecord1, self::SCALE);
        [$registry] = $registry->register('USD', $usdRecord2, self::SCALE);
        [$registry] = $registry->register('EUR', $eurRecord, self::SCALE);

        // Test records for existing nodes
        $usdRecords = $registry->recordsFor('USD');
        self::assertCount(2, $usdRecords);

        $eurRecords = $registry->recordsFor('EUR');
        self::assertCount(1, $eurRecords);

        // Test records for non-existing node
        self::assertSame([], $registry->recordsFor('GBP'));
    }

    public function test_register_identical_records(): void
    {
        $registry = SearchStateRegistry::empty();

        // Register the same record twice
        $record = new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:same'));
        [$registry1, $delta1] = $registry->register('USD', $record, self::SCALE);
        [$registry2, $delta2] = $registry1->register('USD', $record, self::SCALE);

        self::assertSame(1, $delta1); // First registration
        self::assertSame(0, $delta2); // Second registration with same record

        // Should have only one record
        $records = $registry2->recordsFor('USD');
        self::assertCount(1, $records);
    }

    public function test_complex_dominance_scenarios(): void
    {
        $registry = SearchStateRegistry::empty();

        // Add initial records with different signatures
        $record1 = new SearchStateRecord(BigDecimal::of('2.0'), 3, SearchStateSignature::fromString('sig:1'));
        $record2 = new SearchStateRecord(BigDecimal::of('1.5'), 2, SearchStateSignature::fromString('sig:2'));

        [$registry] = $registry->register('USD', $record1, self::SCALE);
        [$registry] = $registry->register('USD', $record2, self::SCALE);

        // Try to register records that should be dominated by existing ones
        $dominated1 = new SearchStateRecord(BigDecimal::of('2.5'), 4, SearchStateSignature::fromString('sig:1')); // Worse than record1
        $dominated2 = new SearchStateRecord(BigDecimal::of('2.0'), 4, SearchStateSignature::fromString('sig:2')); // Worse than record2

        [$registry, $delta1] = $registry->register('USD', $dominated1, self::SCALE);
        self::assertSame(0, $delta1); // Should be dominated, no new state

        [$registry, $delta2] = $registry->register('USD', $dominated2, self::SCALE);
        self::assertSame(0, $delta2); // Should be dominated, no new state

        // Should still have only 2 records
        self::assertCount(2, $registry->recordsFor('USD'));

        // Try to register records that should dominate existing ones
        $dominant1 = new SearchStateRecord(BigDecimal::of('1.0'), 1, SearchStateSignature::fromString('sig:1')); // Better than record1
        $dominant2 = new SearchStateRecord(BigDecimal::of('1.0'), 0, SearchStateSignature::fromString('sig:2')); // Better than record2

        [$registry, $delta3] = $registry->register('USD', $dominant1, self::SCALE);
        self::assertSame(0, $delta3); // Updates existing, no new state

        [$registry, $delta4] = $registry->register('USD', $dominant2, self::SCALE);
        self::assertSame(0, $delta4); // Updates existing, no new state

        // Should still have only 2 records
        self::assertCount(2, $registry->recordsFor('USD'));
    }
}
