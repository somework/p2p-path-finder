<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Search;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateRecord;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateRegistry;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateSignature;
use SomeWork\P2PPathFinder\Tests\Application\Support\Generator\SearchStateRecordGenerator;

/**
 * Property tests ensuring registry pruning maintains dominance invariants.
 */
final class SearchStateRegistryPropertyTest extends TestCase
{
    private const SCALE = 18;

    /**
     * @return iterable<string, array{list<array{node: string, record: SearchStateRecord, signature: SearchStateSignature}>}>
     */
    public static function provideRecordOperations(): iterable
    {
        for ($seed = 0; $seed < 48; ++$seed) {
            $generator = new SearchStateRecordGenerator(new Randomizer(new Mt19937($seed)));
            $operations = $generator->recordOperations();

            yield 'seed-'.$seed => [$operations];
        }
    }

    /**
     * @dataProvider provideRecordOperations
     *
     * @param list<array{node: string, record: SearchStateRecord, signature: SearchStateSignature}> $operations
     */
    public function test_registry_prunes_dominated_records(array $operations): void
    {
        $registry = SearchStateRegistry::empty();
        $history = [];

        foreach ($operations as $operation) {
            $node = $operation['node'];
            $record = $operation['record'];

            $registry->register($node, $record, self::SCALE);

            $signatureValue = $record->signature()->value();
            $history[$node][$signatureValue][] = $record;
        }

        foreach ($history as $node => $recordsBySignature) {
            $stored = [];
            foreach ($registry->recordsFor($node) as $record) {
                $stored[$record->signature()->value()] = $record;
            }

            foreach ($recordsBySignature as $signatureValue => $records) {
                self::assertArrayHasKey($signatureValue, $stored, 'Expected stored record for signature.');
                $current = $stored[$signatureValue];

                foreach ($records as $record) {
                    if ($record !== $current) {
                        self::assertFalse(
                            $record->dominates($current, self::SCALE),
                            'Registry must not retain dominated records.',
                        );
                    }

                    self::assertSame(
                        $current->dominates($record, self::SCALE),
                        $registry->isDominated($node, $record, self::SCALE),
                        'Dominance checks should mirror stored record comparisons.',
                    );

                    self::assertTrue(
                        $registry->hasSignature($node, $record->signature()),
                        'Registry should report existing signatures.',
                    );
                }
            }
        }
    }
}
