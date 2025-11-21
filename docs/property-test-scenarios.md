# Property-test scenario coverage

`tests/Application/Support/Generator/PathFinderScenarioGenerator.php` drives the
high-level property suites. The generator favours dense graphs with
mandatory-minimum segments so the search guards are exercised every run. Three
scenario templates back the generator:

| Label | Depth | Branching range | Mandatory zero-headroom edges | Tolerance choices | Result limit range |
| --- | --- | --- | --- | --- | --- |
| `fanout-4-hop-3` | 3 | 3–4 | 3 | 0.0, 0.005, 0.010 | 2–4 |
| `mandatory-hop-4` | 4 | 3–3 | 4 | 0.0, 0.005, 0.010, 0.015 | 2–5 |
| `wide-fanout-bounded-headroom` | 3 | 3–5 | 2 | 0.0, 0.010, 0.020, 0.050 | 3–5 |

Each template is seeded so that `PathFinderScenarioGenerator::dataset()` returns
stable fixtures for regression tests, while `scenario()` keeps mixing the
profiles to explore more permutations.【F:tests/Application/Support/Generator/PathFinderScenarioGenerator.php†L38-L110】【F:tests/Application/Support/Generator/PathFinderScenarioGenerator.php†L122-L177】

Property suites pull canonical decimal values from `tests/Support/DecimalMath.php` and
`tests/Application/Support/DecimalFactory.php` so every generated amount, tolerance, and
conversion rate starts life as a `Brick\Math\BigDecimal`. This mirrors the production
value objects and avoids BCMath-era normalisation helpers.

## Iteration budgets

The property suites cap their iteration counts to keep the wider test suite
snappy. Use these environment variables when a slower machine needs fewer
iterations:

- `P2P_SCENARIO_GENERATOR_ITERATIONS`
- `P2P_PATH_FINDER_PROPERTY_ITERATIONS`
- `P2P_PATH_FINDER_SERVICE_ITERATIONS`

The defaults still exercise every template at least once thanks to the new
dataset assertions.【F:tests/Application/Support/Generator/PathFinderScenarioGeneratorTest.php†L88-L136】【F:tests/Application/PathFinder/PathFinderPropertyTest.php†L57-L137】【F:tests/Application/Service/PathFinder/PathFinderServicePropertyTest.php†L44-L159】

## Using `PathResultSet` helpers in invariants

`SearchOutcome::paths()` returns a `PathResultSet`, so property suites can lean
on its helpers instead of re-implementing k-best checks in raw arrays. The
collection keeps the search order stable, drops duplicate route signatures, and
offers convenience accessors for the top-N slice.【F:src/Application/PathFinder/Result/SearchOutcome.php†L12-L55】【F:src/Application/PathFinder/Result/PathResultSet.php†L31-L175】 Because the
items are first-class `PathResult` instances, invariants can assert on public
APIs such as `residualTolerance()` or traverse legs via
`PathResultFormatter`-friendly structures rather than poking at internal DTOs.
A typical ordering assertion therefore looks like:

```php
$paths = $outcome->paths();

$this->assertGreaterThan(0, $paths->count());
$this->assertFalse($paths->isEmpty());

// `slice()` returns another PathResultSet, so subsequent checks stay fluent.
/** @var list<PathResult> $topThree */
$topThree = $paths->slice(0, 3)->toArray();

// Compare signatures or tolerance budgets to ensure the k-best front is stable.
$actualResiduals = array_map(
    static fn (PathResult $result): string => $result->residualTolerance()->ratio(),
    $topThree,
);
self::assertSame($expectedResiduals, $actualResiduals);

// routeSignatureFromLegs() mirrors the helper defined in PathFinderPropertyTest.
$actualSignatures = array_map(
    fn (PathResult $result) => $this->routeSignatureFromLegs($result->legs()),
    $topThree,
);
foreach ($expectedSignatures as $index => $signature) {
    self::assertTrue($signature->equals($actualSignatures[$index]));
}

// Pull the headline route without unwrapping the collection.
$best = $paths->first();
self::assertInstanceOf(PathResult::class, $best);
self::assertSame('0.010000000000000000', $best->residualTolerance()->ratio());
```

The property suites use the same flow when validating deterministic rankings and
guard invariants after reshuffling order books or scaling spend constraints, so
they stay focused on behavioural rules rather than collection plumbing.【F:tests/Application/PathFinder/PathFinderPropertyTest.php†L57-L192】【F:tests/Application/Service/PathFinder/PathFinderServicePropertyTest.php†L182-L267】【F:tests/Application/PathFinder/Result/PathResultSetTest.php†L21-L112】
