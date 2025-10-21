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

## Iteration budgets

The property suites cap their iteration counts to keep the wider test suite
snappy. Use these environment variables when a slower machine needs fewer
iterations:

- `P2P_SCENARIO_GENERATOR_ITERATIONS`
- `P2P_PATH_FINDER_PROPERTY_ITERATIONS`
- `P2P_PATH_FINDER_SERVICE_ITERATIONS`

The defaults still exercise every template at least once thanks to the new
dataset assertions.【F:tests/Application/Support/Generator/PathFinderScenarioGeneratorTest.php†L88-L136】【F:tests/Application/PathFinder/PathFinderPropertyTest.php†L57-L137】【F:tests/Application/Service/PathFinder/PathFinderServicePropertyTest.php†L44-L159】
