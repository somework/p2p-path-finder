<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchOutcome;
use SomeWork\P2PPathFinder\Application\Result\MoneyMap;
use SomeWork\P2PPathFinder\Application\Result\PathLeg;
use SomeWork\P2PPathFinder\Application\Result\PathLegCollection;
use SomeWork\P2PPathFinder\Application\Result\PathResult;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalTolerance;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function is_int;

/**
 * Tests that verify JSON serialization contracts match the documented API contracts.
 *
 * These tests ensure that JSON structure remains stable and matches the documentation
 * in docs/api-contracts.md. Any failures here indicate breaking changes to the API.
 *
 * @see docs/api-contracts.md
 */
final class SerializationContractTest extends TestCase
{
    public function test_money_json_structure_matches_documentation(): void
    {
        // Money is serialized through PathResult/PathLeg, not directly
        // We test it through a PathResult to verify the structure
        $result = new PathResult(
            Money::fromString('USD', '100.50', 2),
            Money::fromString('EUR', '92.00', 2),
            DecimalTolerance::zero(),
        );

        $json = $result->jsonSerialize();
        $moneyJson = $json['totalSpent'];

        // Verify structure
        $this->assertIsArray($moneyJson);
        $this->assertArrayHasKey('currency', $moneyJson);
        $this->assertArrayHasKey('amount', $moneyJson);
        $this->assertArrayHasKey('scale', $moneyJson);
        $this->assertCount(3, $moneyJson, 'Money should have exactly 3 fields');

        // Verify types
        $this->assertIsString($moneyJson['currency'], 'currency must be string');
        $this->assertIsString($moneyJson['amount'], 'amount must be string for precision');
        $this->assertIsInt($moneyJson['scale'], 'scale must be integer');

        // Verify values
        $this->assertSame('USD', $moneyJson['currency']);
        $this->assertSame('100.50', $moneyJson['amount']);
        $this->assertSame(2, $moneyJson['scale']);
    }

    public function test_money_json_preserves_trailing_zeros(): void
    {
        $result = new PathResult(
            Money::fromString('USD', '100.00', 2),
            Money::fromString('EUR', '92.000000', 6),
            DecimalTolerance::zero(),
        );

        $json = $result->jsonSerialize();
        $moneyJson = $json['totalReceived'];

        $this->assertSame('92.000000', $moneyJson['amount'], 'Trailing zeros must be preserved');
        $this->assertSame(6, $moneyJson['scale']);
    }

    public function test_money_json_handles_large_amounts(): void
    {
        $result = new PathResult(
            Money::fromString('BTC', '999999999.123456789012345678', 18),
            Money::fromString('USD', '1000000000.00', 2),
            DecimalTolerance::zero(),
        );

        $json = $result->jsonSerialize();
        $moneyJson = $json['totalSpent'];

        $this->assertIsString($moneyJson['amount'], 'Large amounts must be strings');
        $this->assertSame('BTC', $moneyJson['currency']);
        $this->assertSame(18, $moneyJson['scale']);
    }

    public function test_money_json_handles_zero_amounts(): void
    {
        $result = new PathResult(
            Money::fromString('USD', '0.00', 2),
            Money::fromString('EUR', '0.00', 2),
            DecimalTolerance::zero(),
        );

        $json = $result->jsonSerialize();
        $moneyJson = $json['totalSpent'];

        $this->assertSame('0.00', $moneyJson['amount']);
        $this->assertSame('USD', $moneyJson['currency']);
        $this->assertSame(2, $moneyJson['scale']);
    }

    public function test_money_map_json_structure_matches_documentation(): void
    {
        $map = MoneyMap::fromList([
            Money::fromString('USD', '1.50', 2),
            Money::fromString('EUR', '0.45', 2),
        ]);

        $json = $map->jsonSerialize();

        // Verify structure
        $this->assertIsArray($json);
        $this->assertArrayHasKey('USD', $json);
        $this->assertArrayHasKey('EUR', $json);

        // Verify keys are sorted
        $keys = array_keys($json);
        $this->assertSame(['EUR', 'USD'], $keys, 'Keys must be sorted alphabetically');

        // Verify each value is a Money structure
        foreach ($json as $currency => $moneyJson) {
            $this->assertIsArray($moneyJson);
            $this->assertArrayHasKey('currency', $moneyJson);
            $this->assertArrayHasKey('amount', $moneyJson);
            $this->assertArrayHasKey('scale', $moneyJson);
            $this->assertSame($currency, $moneyJson['currency'], 'Key must match currency field');
        }
    }

    public function test_money_map_empty_json_is_empty_object(): void
    {
        $map = MoneyMap::empty();
        $json = $map->jsonSerialize();

        $this->assertIsArray($json);
        $this->assertCount(0, $json, 'Empty map should be empty array');
    }

    public function test_decimal_tolerance_json_is_numeric_string(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.0500000000000000000', 18);
        $json = $tolerance->jsonSerialize();

        // Verify it's a plain string, not an object
        $this->assertIsString($json, 'DecimalTolerance must serialize to string');
        $this->assertSame('0.050000000000000000', $json);
    }

    public function test_decimal_tolerance_zero_json(): void
    {
        $tolerance = DecimalTolerance::zero();
        $json = $tolerance->jsonSerialize();

        $this->assertIsString($json);
        $this->assertMatchesRegularExpression('/^0\.0+$/', $json, 'Zero tolerance must be string of 0.0...');
    }

    public function test_path_leg_json_structure_matches_documentation(): void
    {
        $leg = new PathLeg(
            'USD',
            'EUR',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('EUR', '92.00', 2),
            MoneyMap::fromList([Money::fromString('EUR', '0.46', 2)]),
        );

        $json = $leg->jsonSerialize();

        // Verify structure
        $this->assertIsArray($json);
        $this->assertArrayHasKey('from', $json);
        $this->assertArrayHasKey('to', $json);
        $this->assertArrayHasKey('spent', $json);
        $this->assertArrayHasKey('received', $json);
        $this->assertArrayHasKey('fees', $json);
        $this->assertCount(5, $json, 'PathLeg should have exactly 5 fields');

        // Verify types
        $this->assertIsString($json['from'], 'from must be string');
        $this->assertIsString($json['to'], 'to must be string');
        $this->assertIsArray($json['spent'], 'spent must be Money object');
        $this->assertIsArray($json['received'], 'received must be Money object');
        $this->assertIsArray($json['fees'], 'fees must be MoneyMap object');

        // Verify values
        $this->assertSame('USD', $json['from']);
        $this->assertSame('EUR', $json['to']);

        // Verify Money structures
        $this->assertArrayHasKey('currency', $json['spent']);
        $this->assertSame('USD', $json['spent']['currency']);
        $this->assertArrayHasKey('currency', $json['received']);
        $this->assertSame('EUR', $json['received']['currency']);
    }

    public function test_path_leg_json_with_no_fees(): void
    {
        $leg = new PathLeg(
            'USD',
            'EUR',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('EUR', '92.00', 2),
            MoneyMap::empty(),
        );

        $json = $leg->jsonSerialize();

        $this->assertIsArray($json['fees']);
        $this->assertCount(0, $json['fees'], 'Empty fees should be empty array');
    }

    public function test_path_leg_collection_json_is_array_of_legs(): void
    {
        $legs = PathLegCollection::fromList([
            new PathLeg(
                'USD',
                'GBP',
                Money::fromString('USD', '100.00', 2),
                Money::fromString('GBP', '80.00', 2),
            ),
            new PathLeg(
                'GBP',
                'EUR',
                Money::fromString('GBP', '80.00', 2),
                Money::fromString('EUR', '93.60', 2),
            ),
        ]);

        $json = $legs->jsonSerialize();

        // Verify it's an array
        $this->assertIsArray($json);
        $this->assertCount(2, $json);

        // Verify each element is a PathLeg structure
        foreach ($json as $index => $legJson) {
            $this->assertIsArray($legJson, "Leg $index must be array");
            $this->assertArrayHasKey('from', $legJson);
            $this->assertArrayHasKey('to', $legJson);
            $this->assertArrayHasKey('spent', $legJson);
            $this->assertArrayHasKey('received', $legJson);
            $this->assertArrayHasKey('fees', $legJson);
        }

        // Verify path continuity (first leg's 'to' = second leg's 'from')
        $this->assertSame($json[0]['to'], $json[1]['from'], 'Path legs must be continuous');
    }

    public function test_path_leg_collection_empty_json_is_empty_array(): void
    {
        $legs = PathLegCollection::empty();
        $json = $legs->jsonSerialize();

        $this->assertIsArray($json);
        $this->assertCount(0, $json);
    }

    public function test_path_result_json_structure_matches_documentation(): void
    {
        $result = new PathResult(
            Money::fromString('USD', '100.00', 2),
            Money::fromString('EUR', '92.50', 2),
            DecimalTolerance::fromNumericString('0.0123456789', 10),
            PathLegCollection::empty(),
            MoneyMap::empty(),
        );

        $json = $result->jsonSerialize();

        // Verify structure
        $this->assertIsArray($json);
        $this->assertArrayHasKey('totalSpent', $json);
        $this->assertArrayHasKey('totalReceived', $json);
        $this->assertArrayHasKey('residualTolerance', $json);
        $this->assertArrayHasKey('feeBreakdown', $json);
        $this->assertArrayHasKey('legs', $json);
        $this->assertCount(5, $json, 'PathResult should have exactly 5 fields');

        // Verify types
        $this->assertIsArray($json['totalSpent'], 'totalSpent must be Money object');
        $this->assertIsArray($json['totalReceived'], 'totalReceived must be Money object');
        $this->assertIsString($json['residualTolerance'], 'residualTolerance must be string');
        $this->assertIsArray($json['feeBreakdown'], 'feeBreakdown must be MoneyMap object');
        $this->assertIsArray($json['legs'], 'legs must be array');

        // Verify Money structures
        $this->assertSame('USD', $json['totalSpent']['currency']);
        $this->assertSame('EUR', $json['totalReceived']['currency']);

        // Verify tolerance is numeric string
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $json['residualTolerance']);
    }

    public function test_path_result_json_with_complete_path(): void
    {
        $legs = PathLegCollection::fromList([
            new PathLeg(
                'USD',
                'JPY',
                Money::fromString('USD', '100.00', 2),
                Money::fromString('JPY', '14250.00', 2),
                MoneyMap::fromList([Money::fromString('JPY', '750.00', 2)]),
            ),
            new PathLeg(
                'JPY',
                'EUR',
                Money::fromString('JPY', '14250.00', 2),
                Money::fromString('EUR', '93.59', 2),
                MoneyMap::fromList([Money::fromString('EUR', '0.47', 2)]),
            ),
        ]);

        $feeBreakdown = MoneyMap::fromList([
            Money::fromString('JPY', '750.00', 2),
            Money::fromString('EUR', '0.47', 2),
        ]);

        $result = new PathResult(
            Money::fromString('USD', '100.00', 2),
            Money::fromString('EUR', '93.12', 2),
            DecimalTolerance::zero(),
            $legs,
            $feeBreakdown,
        );

        $json = $result->jsonSerialize();

        // Verify complete structure
        $this->assertCount(2, $json['legs'], 'Should have 2 legs');
        $this->assertCount(2, $json['feeBreakdown'], 'Should have 2 fee currencies');

        // Verify fees are sorted
        $feeKeys = array_keys($json['feeBreakdown']);
        $this->assertSame(['EUR', 'JPY'], $feeKeys, 'Fee currencies must be sorted');
    }

    public function test_search_guard_report_json_structure_matches_documentation(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 342,
            visitedStates: 156,
            elapsedMilliseconds: 12.456,
            expansionLimit: 10000,
            visitedStateLimit: 5000,
            timeBudgetLimit: 1000,
        );

        $json = $report->jsonSerialize();

        // Verify top-level structure
        $this->assertIsArray($json);
        $this->assertArrayHasKey('limits', $json);
        $this->assertArrayHasKey('metrics', $json);
        $this->assertArrayHasKey('breached', $json);
        $this->assertCount(3, $json, 'SearchGuardReport should have exactly 3 fields');

        // Verify 'limits' object
        $this->assertIsArray($json['limits']);
        $this->assertArrayHasKey('expansions', $json['limits']);
        $this->assertArrayHasKey('visited_states', $json['limits']);
        $this->assertArrayHasKey('time_budget_ms', $json['limits']);
        $this->assertCount(3, $json['limits']);

        $this->assertIsInt($json['limits']['expansions']);
        $this->assertIsInt($json['limits']['visited_states']);
        $this->assertTrue(is_int($json['limits']['time_budget_ms']) || null === $json['limits']['time_budget_ms']);

        // Verify 'metrics' object
        $this->assertIsArray($json['metrics']);
        $this->assertArrayHasKey('expansions', $json['metrics']);
        $this->assertArrayHasKey('visited_states', $json['metrics']);
        $this->assertArrayHasKey('elapsed_ms', $json['metrics']);
        $this->assertCount(3, $json['metrics']);

        $this->assertIsInt($json['metrics']['expansions']);
        $this->assertIsInt($json['metrics']['visited_states']);
        $this->assertIsFloat($json['metrics']['elapsed_ms'], 'elapsed_ms must be float');

        // Verify 'breached' object
        $this->assertIsArray($json['breached']);
        $this->assertArrayHasKey('expansions', $json['breached']);
        $this->assertArrayHasKey('visited_states', $json['breached']);
        $this->assertArrayHasKey('time_budget', $json['breached']);
        $this->assertArrayHasKey('any', $json['breached']);
        $this->assertCount(4, $json['breached']);

        $this->assertIsBool($json['breached']['expansions']);
        $this->assertIsBool($json['breached']['visited_states']);
        $this->assertIsBool($json['breached']['time_budget']);
        $this->assertIsBool($json['breached']['any']);
    }

    public function test_search_guard_report_json_with_null_time_budget(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 100,
            visitedStates: 50,
            elapsedMilliseconds: 5.5,
            expansionLimit: 1000,
            visitedStateLimit: 500,
            timeBudgetLimit: null,
        );

        $json = $report->jsonSerialize();

        $this->assertNull($json['limits']['time_budget_ms'], 'time_budget_ms can be null');
        $this->assertFalse($json['breached']['time_budget'], 'time_budget breach must be false when limit is null');
    }

    public function test_search_guard_report_json_detects_breaches(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 10001,
            visitedStates: 5001,
            elapsedMilliseconds: 1001.0,
            expansionLimit: 10000,
            visitedStateLimit: 5000,
            timeBudgetLimit: 1000,
        );

        $json = $report->jsonSerialize();

        $this->assertTrue($json['breached']['expansions'], 'expansions should be breached');
        $this->assertTrue($json['breached']['visited_states'], 'visited_states should be breached');
        $this->assertTrue($json['breached']['time_budget'], 'time_budget should be breached');
        $this->assertTrue($json['breached']['any'], 'any should be true when limits breached');
    }

    public function test_search_outcome_json_structure_matches_documentation(): void
    {
        $paths = \SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSet::fromPaths(
            new \SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\CostHopsSignatureOrderingStrategy(6),
            [],
            fn ($p) => new \SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey(
                new \SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost('0'),
                0,
                \SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature::fromNodes([]),
                0
            )
        );

        $guards = SearchGuardReport::idle(5000, 10000);

        $outcome = new SearchOutcome($paths, $guards);

        $json = $outcome->jsonSerialize();

        // Verify top-level structure
        $this->assertIsArray($json);
        $this->assertArrayHasKey('paths', $json);
        $this->assertArrayHasKey('guards', $json);
        $this->assertCount(2, $json, 'SearchOutcome should have exactly 2 fields');

        // Verify 'paths' is array
        $this->assertIsArray($json['paths'], 'paths must be array');

        // Verify 'guards' is SearchGuardReport structure
        $this->assertIsArray($json['guards'], 'guards must be object');
        $this->assertArrayHasKey('limits', $json['guards']);
        $this->assertArrayHasKey('metrics', $json['guards']);
        $this->assertArrayHasKey('breached', $json['guards']);
    }

    public function test_search_outcome_json_with_empty_paths(): void
    {
        $paths = \SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSet::empty();
        $guards = SearchGuardReport::none();

        $outcome = new SearchOutcome($paths, $guards);

        $json = $outcome->jsonSerialize();

        $this->assertIsArray($json['paths']);
        $this->assertCount(0, $json['paths'], 'Empty paths should be empty array');
    }

    public function test_json_serialization_round_trip_preserves_structure(): void
    {
        $result = new PathResult(
            Money::fromString('USD', '100.00', 2),
            Money::fromString('EUR', '92.50', 2),
            DecimalTolerance::fromNumericString('0.05', 2),
            PathLegCollection::empty(),
            MoneyMap::empty(),
        );

        $json = $result->jsonSerialize();
        $encoded = json_encode($json);
        $decoded = json_decode($encoded, true);

        // Verify structure is preserved through encoding/decoding
        $this->assertSame($json, $decoded, 'JSON structure must survive round-trip');
    }

    public function test_json_field_names_use_snake_case_where_appropriate(): void
    {
        $report = SearchGuardReport::idle(5000, 10000, 1000);
        $json = $report->jsonSerialize();

        // Verify field naming conventions
        $this->assertArrayHasKey('visited_states', $json['limits'], 'Should use snake_case');
        $this->assertArrayHasKey('time_budget_ms', $json['limits'], 'Should use snake_case');
        $this->assertArrayHasKey('elapsed_ms', $json['metrics'], 'Should use snake_case');
    }
}
