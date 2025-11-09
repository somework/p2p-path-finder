# p2p-path-finder

[![Tests](https://img.shields.io/github/actions/workflow/status/somework/p2p-path-finder/tests.yml?branch=main&label=Tests)](https://github.com/somework/p2p-path-finder/actions/workflows/tests.yml)
[![Quality](https://img.shields.io/github/actions/workflow/status/somework/p2p-path-finder/quality.yml?branch=main&label=Quality)](https://github.com/somework/p2p-path-finder/actions/workflows/quality.yml)

A small toolkit for discovering optimal peer-to-peer conversion paths across a set of
orders. The package focuses on deterministic arithmetic, declarative configuration and
clear separation between the domain model and application services.

## Requirements

* PHP 8.2 or newer.
* The [BCMath extension](https://www.php.net/manual/en/book.bc.php) (`ext-bcmath`). Install it before running `composer install`; otherwise `composer check-platform-reqs` will fail and dependencies will not be installed. See [docs/local-development.md](docs/local-development.md) for an optional polyfill workflow when the native extension is unavailable.

## Architecture overview

The codebase is intentionally split into two layers:

* **Domain layer** – Contains value objects such as `Money`, `ExchangeRate`, `OrderBounds`
  and domain entities like `Order`. These classes are immutable, validate their input and
  provide a thin abstraction around BCMath so that all monetary calculations have
  predictable precision.
* **Application layer** – Hosts services that orchestrate the domain model. Notable
  components include:
  * `OrderBook` and a small set of reusable `OrderFilterInterface` implementations used to
    prune irrelevant liquidity.
  * `GraphBuilder`, which converts domain orders into a weighted graph representation.
  * `PathFinderService`, a façade that applies filters, builds the search graph and returns
    `PathResult` aggregates complete with `PathLeg` breakdowns. It is backed by an internal
    search engine that implements the tolerance-aware route discovery logic without being
    part of the supported surface.

The internal path finder accepts tolerance values exclusively as decimal strings. Supplying
numeric-string tolerances (for example `'0.9999999999999999'`) preserves the full
precision of the input without depending on floating-point formatting. Internally all
tolerances are normalised to 18 decimal places before calculating the amplifier used by the
search heuristic.

The separation allows you to extend or replace either layer (e.g. load orders from an API
or swap in a different search algorithm) without leaking implementation details.

## Public API surface

The package intentionally keeps its entry points compact:

* `PathSearchRequest` is the mandatory DTO passed to
  `PathFinderService::findBestPaths()`. It normalises the target asset, derives spend
  constraints and ensures callers supply all dependencies required to launch a search.
* `PathFinderService` orchestrates filtering, graph construction and search. It is the
  primary façade exposed to consumers integrating the library into their own
  applications. The constructor accepts the public `GraphBuilder` plus optional ordering
  and factory hooks while instantiating its internal helpers itself so the `@internal`
  services remain encapsulated.【F:src/Application/Service/PathFinderService.php†L41-L165】
* `PathSearchConfig` represents the declarative inputs accepted by the search engine. The
  builder surfaced via `PathSearchConfig::builder()` is part of the supported API and
  allows consumers to construct validated configurations fluently.【F:src/Application/Config/PathSearchConfig.php†L35-L266】
* `SearchOutcome::paths()` returns a `PathResultSet`, an immutable collection that
  provides iteration, slicing and `jsonSerialize()`/`toArray()` helpers so you can pipe the
  results straight into response DTOs or JSON encoders.【F:src/Application/PathFinder/Result/SearchOutcome.php†L9-L71】【F:src/Application/PathFinder/Result/PathResultSet.php†L1-L205】

Support services that exist only to back the façade&mdash;for example
`OrderSpendAnalyzer`, `LegMaterializer` and `ToleranceEvaluator`&mdash;are marked with
`@internal` annotations and should not be depended upon directly by userland code. They
are omitted from the generated API reference to reinforce that they may change without
notice.【F:src/Application/Service/OrderSpendAnalyzer.php†L17-L23】【F:src/Application/Service/LegMaterializer.php†L19-L25】【F:src/Application/Service/ToleranceEvaluator.php†L16-L23】

## Design Notes

* **Stable priority queue semantics.** The internal `SearchStateQueue` always prefers
  lower cumulative costs. Finalized path lists extend that determinism by comparing
  candidates using cost, hop count, a lexicographical route signature (e.g.
  `EUR->USD->...`) and finally discovery order when all other keys match.【F:src/Application/PathFinder/PathFinder.php†L635-L708】【F:src/Application/Service/PathFinderService.php†L170-L264】【F:tests/Application/PathFinder/PathFinderTest.php†L205-L255】
* **Mandatory segment pruning.** Each edge carries a list of mandatory and optional
  liquidity segments. During expansion the path finder aggregates the mandatory portion of
  the relevant amounts (gross base for buys, quote for sells) and discards candidates that
  cannot cover that floor before considering optional capacity, preventing undersized
  requests from progressing further into the search.【F:src/Application/PathFinder/PathFinder.php†L692-L745】
* **Deterministic decimal policy.** All tolerances, costs and search ratios are normalized
  to 18 decimal places using half-up rounding so the same input produces identical routing
  decisions across environments. The `BcMath` helper centralises this behaviour and backs
  the tolerance validation performed by `PathFinder`.【F:src/Domain/ValueObject/BcMath.php†L79-L121】【F:src/Application/PathFinder/PathFinder.php†L166-L212】
* **Wall-clock guard rails.** `PathSearchConfig::withSearchTimeBudget()` injects a
  millisecond budget directly into the search loop so that runaway expansions halt even when
  structural guardrails are relaxed. The resulting report is surfaced via
  `SearchGuardReport::timeBudgetReached()` alongside concrete counters for expansions,
  visited states and elapsed milliseconds, and participates in the same metadata/exception
  pathways as the other guard knobs.【F:src/Application/Config/PathSearchConfigBuilder.php†L89-L129】【F:src/Application/PathFinder/PathFinder.php†L206-L407】【F:tests/Application/Service/PathFinder/PathFinderServiceGuardsTest.php†L235-L309】

```php
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Application\Service\PathSearchRequest;

// Assume $pathFinderService, $orderBook and $spendMoney were injected by your framework.

$config = PathSearchConfig::builder()
    ->withSpendAmount($spendMoney)
    ->withToleranceBounds('0.05', '0.10')
    ->withHopLimits(1, 3)
    ->build();

$request = new PathSearchRequest($orderBook, $config, 'BTC');
$outcome = $pathFinderService->findBestPaths($request);

foreach ($outcome->paths() as $path) {
    // $path is a PathResult implementing JsonSerializable.
    $payload = $path->jsonSerialize();
}

$report = $outcome->guardLimits();
if ($report->anyLimitReached()) {
    // React to guard-rail breaches (expansion/visit limits or time budget).
}

$guardPayload = $report->jsonSerialize();
```

See [docs/guarded-search-example.md](docs/guarded-search-example.md) for a guided example
that combines these invariants with guard-rail configuration and demonstrates the
`PathFinderService::findBestPaths(new PathSearchRequest($orderBook, $config, 'BTC'))` invocation flow.

## Configuring a path search

`PathSearchConfig` captures the parameters used during graph exploration. Build the
configuration fluently, wrap it in a `PathSearchRequest` and iterate the resulting
`PathResultSet` and guard report:

```php
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Application\Service\PathSearchRequest;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

// Assume $pathFinderService and $orderBook were injected by your framework.

$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds('0.05', '0.10')
    ->withHopLimits(1, 3)
    ->withSearchGuards(10000, 25000) // visited states, expansions
    ->withSearchTimeBudget(50)
    ->build();

$request = new PathSearchRequest($orderBook, $config, 'BTC');
$outcome = $pathFinderService->findBestPaths($request);

$paths = [];
foreach ($outcome->paths() as $result) {
    // Each PathResult is JsonSerializable; store it as an array payload.
    $paths[] = $result->jsonSerialize();
}

$guardReport = $outcome->guardLimits()->jsonSerialize();
if ($guardReport['breached']['any']) {
    // Decide how to surface guard-rail breaches to callers.
}
```

`withToleranceBounds()` accepts only numeric-string values. Providing a string keeps the
original precision intact when it is passed to the internal search engine. Pair those boundaries with
`withGuardLimitException()` when you want guard-limit breaches to throw instead of being
reported through metadata.

`SearchOutcome::jsonSerialize()` returns the materialised paths alongside the serialized
`SearchGuardReport`. The structure mirrors the guard terminology:

```json
{
  "paths": [
    {
      "totalSpent": {"currency": "USD", "amount": "100.00", "scale": 2},
      "totalReceived": {"currency": "BTC", "amount": "0.00420000", "scale": 8},
      "residualTolerance": "0.075000000000000000",
      "feeBreakdown": {
        "USD": {"currency": "USD", "amount": "0.75", "scale": 2}
      },
      "legs": [
        {
          "from": "USD",
          "to": "BTC",
          "spent": {"currency": "USD", "amount": "100.00", "scale": 2},
          "received": {"currency": "BTC", "amount": "0.00420000", "scale": 8},
          "fees": {}
        }
      ]
    }
  ],
  "guards": {
    "limits": {
      "expansions": 25000,
      "visited_states": 10000,
      "time_budget_ms": 50
    },
    "metrics": {
      "expansions": 874,
      "visited_states": 1623,
      "elapsed_ms": 12.4
    },
    "breached": {
      "expansions": false,
      "visited_states": false,
      "time_budget": false,
      "any": false
    }
  }
}
```

To escalate breaches to exceptions instead, call `withGuardLimitException()`:

```php
$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds('0.02', '0.10')
    ->withHopLimits(1, 4)
    ->withSearchGuards(10000, 25000)
    ->withSearchTimeBudget(50)
    ->withGuardLimitException()
    ->build();

// GuardLimitExceeded is thrown when any guard threshold is hit and the report contains
// the counters that triggered the guard.
```

See [docs/guarded-search-example.md](docs/guarded-search-example.md) for a complete,
ready-to-run integration walkthrough that demonstrates these guard limits in context.

## Community and project health

- Read the [Contributing guide](CONTRIBUTING.md) before opening issues or pull requests.
- Review our [Code of Conduct](CODE_OF_CONDUCT.md) to understand the expectations for
  participating in the community.
- Follow the [Security policy](SECURITY.md) for responsible vulnerability disclosure.
- Track progress toward the `1.0.0-rc` milestone in the [Changelog](CHANGELOG.md).

### Choosing search guard limits

The defaults (`250000` visited states and expansions) work well for moderately dense
markets while still short-circuiting pathologically broad graphs.【F:src/Application/PathFinder/PathFinder.php†L166-L212】 Use
smaller bounds when exploring adversarial inputs: the dense-graph test suite demonstrates
that a 3-layer graph with fan-out 3 exhausts a single-expansion guard immediately, whereas
raising both guards to `20000` allows the same topology to converge.【F:tests/Application/PathFinder/PathFinderTest.php†L1782-L1813】 Likewise, limiting
visited states to `1` halts a 2-layer × 4-fan-out graph instantly, but relaxing the guard
to `10000` lets it produce viable paths.【F:tests/Application/PathFinder/PathFinderTest.php†L1815-L1845】 Benchmarks reuse
search guards in the `20000–30000` range to keep synthetic dense graphs under control,
which is a good starting point when profiling locally.【F:benchmarks/PathFinderBench.php†L61-L148】

For scenarios where those structural limits are insufficient, pair them with
`PathSearchConfig::withSearchTimeBudget()` to enforce a wall-clock cap on the search. Tiny
budgets (1–2ms) are ideal for tests that need to observe guard metadata or forced
exceptions, while looser budgets in the tens of milliseconds tame bursty workloads without
impacting typical queries.【F:tests/Application/PathFinder/PathFinderTest.php†L1951-L1965】【F:tests/Application/Service/PathFinder/PathFinderServiceGuardsTest.php†L296-L332】

The builder enforces presence and validity of each piece of configuration. Internally the
configuration pre-computes minimum/maximum spend amounts derived from the tolerance window,
which are then used when filtering the order book.

During graph exploration the internal path finder also aggregates the mandatory minimum of every
edge segment and drops candidates that would undershoot those thresholds before checking
capacity. As a result, requests that fall just below an order's minimum are pruned earlier
in the search rather than reaching the materialisation phase.

## BCMath-based precision

All arithmetic is delegated to `SomeWork\P2PPathFinder\Domain\ValueObject\BcMath`, a thin
wrapper around the BCMath extension. It provides:

* Input validation helpers that fail fast when encountering malformed numeric strings.
* Deterministic rounding that keeps scale under control without leaking trailing digits.
* Utility methods (`add`, `sub`, `mul`, `div`, `comp`) that normalise operands so that
  value objects such as `Money` can work with mixed scales.

By routing every calculation through this helper the library avoids floating-point
rounding drift and guarantees that two identical operations will always yield the same
string representation.

### Decimal policy

The path finder consistently normalises tolerances, costs and ratios to 18 decimal places
using half-up rounding. Normalising via `BcMath::normalize()` ensures that tie-breaking
values such as `0.5` and `-0.5` deterministically round away from zero, keeping matching
behaviour stable across PHP versions and environments.

## Exceptions

The library ships with domain-specific exceptions under the
`SomeWork\\P2PPathFinder\\Exception` namespace:

* `ExceptionInterface` &mdash; a marker implemented by every custom exception so you can
  catch all library-originated failures in one clause.
* `InvalidInput` &mdash; emitted when configuration, path legs, or fee breakdowns fail
  validation.
* `PrecisionViolation` &mdash; signals arithmetic inputs that cannot be represented within the
  configured BCMath scale.
* `GuardLimitExceeded` &mdash; thrown when `PathSearchConfig::withGuardLimitException()` is used
  and the configured search guardrails (visited states, expansions, or time budget) are
  reached.
* `InfeasiblePath` &mdash; indicates that no route satisfies the requested constraints.

Search guardrails (expansion/visited-state/time-budget limits) surface through the
`SearchOutcome::guardLimits()` method. The returned `SearchGuardReport` aggregate exposes
helpers such as `anyLimitReached()` and captures the actual expansion/visited counts and
elapsed milliseconds so callers can inspect whether searches exhausted their configured
protections without relying on exceptions. Opt-in escalation via
`withGuardLimitException()` converts those guard-limit breaches into a `GuardLimitExceeded`
throwable instead of metadata.

Consumers can mix coarse- and fine-grained handling strategies:

```php
use SomeWork\\P2PPathFinder\\Application\\Service\\PathSearchRequest;
use SomeWork\\P2PPathFinder\\Exception\\ExceptionInterface;
use SomeWork\\P2PPathFinder\\Exception\\InvalidInput;
use SomeWork\\P2PPathFinder\\Exception\\PrecisionViolation;

$request = new PathSearchRequest($orderBook, $config, '  usdt  '); // normalized to "USDT"

try {
    $outcome = $service->findBestPaths($request);

    $guardStatus = $outcome->guardLimits();
    if ($guardStatus->anyLimitReached()) {
        // Surface that the configured search guardrails were hit without halting execution.
    }
} catch (InvalidInput|PrecisionViolation $validationError) {
    // Alert callers that supplied data is malformed.
} catch (ExceptionInterface $libraryError) {
    // Catch-all for other library-specific exceptions (e.g. InfeasiblePath).
}

// Enable PathSearchConfig::withGuardLimitException() to escalate guard-limit breaches.
```

## Quick-start scenarios

Below are two end-to-end examples that showcase the typical workflow. In both snippets the
order book is pre-populated with synthetic orders, but you can plug in any data source.

### Scenario 1 – Buying a target asset directly

```php
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Application\Service\PathSearchRequest;
use SomeWork\P2PPathFinder\Exception\InfeasiblePath;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

$order = new Order(
    OrderSide::SELL,
    AssetPair::fromString('USD', 'USDT'),
    OrderBounds::from(
        Money::fromString('USD', '10.00', 2),
        Money::fromString('USD', '1000.00', 2),
    ),
    ExchangeRate::fromString('USD', 'USDT', '1.0000', 4),
);

$orderBook = new OrderBook([$order]);
$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds('0.00', '0.01')
    ->withHopLimits(1, 2)
    ->withResultLimit(3)
    ->build();

$service = new PathFinderService(new GraphBuilder());
$request = new PathSearchRequest($orderBook, $config, "  usdt  "); // normalized to "USDT"
$resultOutcome = $service->findBestPaths($request);

if (!$resultOutcome->hasPaths()) {
    throw new InfeasiblePath('No viable routes found.');
}

$result = $resultOutcome->paths()->toArray()[0];
```

The resulting `SearchOutcome` contains `PathResult` objects ordered from lowest to highest cost.
When multiple candidates share the same cost the default strategy breaks ties by preferring fewer
hops, then lexicographically smaller route signatures (for example `EUR->USD->GBP`) via the
`RouteSignature::compare()` helper, and finally the discovery order reported by the search. This
deterministic cascade keeps results stable across processes.

`PathFinderService` accepts a configurable ordering strategy via its constructor. Implement
`PathOrderStrategy::compare()` to inject your own prioritisation logic and pass it to the façade when
registering the service with your dependency injection container:

```php
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderStrategy;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;

$ordering = new class implements PathOrderStrategy {
    public function compare(PathOrderKey $left, PathOrderKey $right): int
    {
        return $left->routeSignature()->compare($right->routeSignature());
    }
};

$service = new PathFinderService(new GraphBuilder(), orderingStrategy: $ordering);
```

Custom strategies receive lightweight `PathOrderKey` value objects that expose the computed cost,
hop count, route signature and discovery order (plus any payload provided by the caller). Returning
a negative value favours the left operand, positive favours the right, and zero defers to the next
tie-breaker.

For one-off scenarios—such as wiring different strategies per request—you can supply a
`pathFinderFactory` callable that produces bespoke runners. The factory receives the
`PathSearchRequest` and must return a closure matching the signature used internally by the façade.
Most integrations should prefer the constructor-level strategy injection shown above so that the
internal search engine remains hidden behind the service API.

In this example the first entry contains a single `PathLeg` reflecting the direct USD→USDT
conversion.

### Scenario 2 – Selling through an intermediate asset with tight tolerance

```php
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Application\Service\PathSearchRequest;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

$orderBook = new OrderBook([
    new Order(
        OrderSide::SELL,
        AssetPair::fromString('BTC', 'USDT'),
        OrderBounds::from(
            Money::fromString('BTC', '0.01000000', 8),
            Money::fromString('BTC', '1.00000000', 8),
        ),
        ExchangeRate::fromString('BTC', 'USDT', '63000.00000000', 8),
    ),
    new Order(
        OrderSide::BUY,
        AssetPair::fromString('USDT', 'EUR'),
        OrderBounds::from(
            Money::fromString('USDT', '100.00', 2),
            Money::fromString('USDT', '100000.00', 2),
        ),
        ExchangeRate::fromString('USDT', 'EUR', '0.92', 8),
    ),
]);

$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('BTC', '0.10000000', 8))
    ->withToleranceBounds('0.00', '0.02')
    ->withHopLimits(2, 3)
    ->build();

$request = new PathSearchRequest($orderBook, $config, 'EUR');
$resultOutcome = (new PathFinderService(new GraphBuilder()))
    ->findBestPaths($request);

$topTwo = $resultOutcome->paths()->slice(0, 2);
```

Because the tolerance window is narrow the service will only accept paths that stay close
to the configured BTC spend amount while allowing up to three hops. By requesting the first
two results you can present both the optimal and a fallback route to downstream consumers.

Use `PathResultFormatter` to turn the results into machine- or human-friendly output:

```php
$formatter = new PathResultFormatter();
$payload = $formatter->formatMachineCollection($topTwo);
echo $formatter->formatHumanCollection($topTwo);
```

## API documentation

Docblocks are available throughout the public API. To generate browseable documentation
run:

```bash
composer phpdoc
```

The command will populate HTML output under `docs/api/`.

## Running tests and quality checks

```bash
composer phpunit
composer phpstan
composer php-cs-fixer
```

All commands rely on the development dependencies declared in `composer.json`.

## Benchmarking path search performance

Path search performance is tracked with [PhpBench](https://phpbench.readthedocs.io/).
The benchmark suite exercises two real-world usage patterns:

* `benchFindBestPaths` covers shallow books with repeat liquidity, capturing:
  * `light-depth-hop-3` – ~15 orders with a maximum hop count of three.
  * `moderate-depth-hop-4` – ~45 orders with a maximum hop count of four.
* `benchFindBestPathsDenseGraph` synthesises increasingly dense graphs, covering:
  * `dense-4x4-hop-5` – four layers of fanout (256 synthetic assets) and five-hop cap.
  * `dense-3x7-hop-6` – three layers of fanout (343 synthetic assets) and six-hop cap.
* `benchFindKBestPaths` stresses the k-best search routine with disjoint, two-hop paths:
  * `k-best-n1e2` – 100 deterministic orders (50 disjoint routes) targeting the best 16.
  * `k-best-n1e3` – 1,000 deterministic orders (500 routes) targeting the best 16.
  * `k-best-n1e4` – 10,000 deterministic orders (5,000 routes) targeting the best 16.

Latest reference numbers on PHP 8.3 (Ubuntu 22.04, Xeon vCPU) are summarised below. The
target columns establish the KPIs enforced by CI via PhpBench regression assertions.

| Scenario (orders)      | Mean (ms) | Peak memory | KPI target (mean) | KPI target (peak memory) |
|------------------------|-----------|-------------|-------------------|--------------------------|
| k-best-n1e2 (100)      | 30.7      | 5.8 MB      | ≤ 35 ms           | ≤ 7 MB                   |
| k-best-n1e3 (1,000)    | 323.5     | 12.5 MB     | ≤ 350 ms          | ≤ 15 MB                  |
| k-best-n1e4 (10,000)   | 4,344.2   | 79.8 MB     | ≤ 4.5 s           | ≤ 96 MB                  |

Run the suite locally and compare against the stored baseline with:

```bash
php -d memory_limit=-1 -d xdebug.mode=off vendor/bin/phpbench run \
    --config=phpbench.json \
    --ref=baseline \
    --progress=plain \
    --assert="mean(variant.time.avg) <= mean(baseline.time.avg) +/- 20%" \
    --assert="mean(variant.mem.peak) <= mean(baseline.mem.peak) +/- 20%"
```

> ℹ️  Append `--report=p2p_aggregate` when you want a human-readable summary.
> It produces the same results but forces PhpBench to hold more state in memory,
> so the regression command above keeps it disabled by default. The assertions
> cover both runtime and peak memory usage to avoid silent regressions. Ensure
> Xdebug is disabled (for example via `-d xdebug.mode=off` or `XDEBUG_MODE=off`)
> when running benchmarks so results align with the stored baseline and CI.

The baseline lives under `.phpbench/storage/`. When intentional optimisations shift
performance, refresh it by rerunning:

```bash
php -d memory_limit=-1 -d xdebug.mode=off vendor/bin/phpbench run \
    --config=phpbench.json \
    --tag=baseline \
    --store \
    --progress=plain
```

The flags mirror the GitHub Actions job so the stored XML matches what CI expects.

The CI “PhpBench” job executes the same comparison to guard against regressions.
