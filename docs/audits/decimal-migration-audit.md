# Decimal migration audit

This report consolidates every `SomeWork\\P2PPathFinder\\Domain\\ValueObject\\BcMath` touchpoint and tracks all numeric-string carriers involved in costs, rates, tolerances, and serialization so the BigDecimal migration can proceed with full coverage.

## 1. BcMath touchpoints

### Production code

| Subsystem | Files / classes | Touchpoints |
| --- | --- | --- |
| Domain value objects | `Money`, `ExchangeRate`, `DecimalTolerance`, `ToleranceWindow`, `BcMath` | All arithmetic, normalization, comparisons, and JSON serialization rely on `BcMath` helpers to normalize inputs, guard numeric strings, and convert between ratios and percentages.【F:src/Domain/ValueObject/Money.php†L21-L229】【F:src/Domain/ValueObject/ExchangeRate.php†L20-L124】【F:src/Domain/ValueObject/DecimalTolerance.php†L25-L150】【F:src/Domain/ValueObject/ToleranceWindow.php†L15-L125】【F:src/Domain/ValueObject/BcMath.php†L12-L123】 |
| Search configuration & filters | `PathSearchConfig`, `ToleranceWindowFilter` | Config derives tolerance-adjusted spend bounds via `BcMath::add`/`sub`, while the order filter normalizes tolerances and clamps effective rates within computed bounds.【F:src/Application/Config/PathSearchConfig.php†L24-L190】【F:src/Application/Filter/ToleranceWindowFilter.php†L18-L70】 |
| Path finder core | `PathFinder`, `SearchState`, `SearchStateRecord`, `CandidatePath`, `PathEdge`, `PathCost` | The search loop, range propagation, edge conversions, tolerance amplification, and ordering logic all depend on `BcMath` for cost/product normalization, comparisons, ratio math, and deterministic rounding.【F:src/Application/PathFinder/PathFinder.php†L68-L728】【F:src/Application/PathFinder/Search/SearchState.php†L21-L216】【F:src/Application/PathFinder/Search/SearchStateRecord.php†L12-L50】【F:src/Application/PathFinder/ValueObject/CandidatePath.php†L21-L111】【F:src/Application/PathFinder/ValueObject/PathEdge.php†L18-L105】【F:src/Application/PathFinder/Result/Ordering/PathCost.php†L9-L54】 |
| Services | `ToleranceEvaluator`, `LegMaterializer` | Residual tolerance computation, sell resolution checks, ratio-driven adjustments, and fee-aware scaling use `BcMath` comparison/division helpers throughout leg materialization and evaluation.【F:src/Application/Service/ToleranceEvaluator.php†L8-L110】【F:src/Application/Service/LegMaterializer.php†L320-L538】 |

### Documentation, tooling, and benchmarks

- README – describes the `BrickDecimalMath`/`BcMath` policy and rounding guarantees.【F:README.md†L263-L286】
- Performance hotspot profile – notes the warm-up cost attributed to `Money::fromString`/`BcMath::normalize`.【F:docs/performance/hotspot-profile.md†L62-L82】
- Public API reference – documents every `BcMath` method signature for integrators.【F:docs/api/index.md†L1003-L1098】
- PhpBench suite – seeds exchange-rate fixtures via `BcMath::normalize`, `sub`, `div`, and `comp`.【F:benchmarks/PathFinderBench.php†L456-L489】

### Tests

- Path finder heuristics & integration suites exercise BcMath-backed comparisons (`PathFinderHeuristicsTest`, `PathFinderHeuristicsPropertyTest`, `PathFinderInternalsTest`, `PathFinderMetamorphicTest`, `PathFinderPropertyTest`, `PathFinderTest`).【F:tests/Application/PathFinder/PathFinderHeuristicsTest.php†L51-L931】【F:tests/Application/PathFinder/PathFinderHeuristicsPropertyTest.php†L149-L163】【F:tests/Application/PathFinder/PathFinderInternalsTest.php†L103-L1207】【F:tests/Application/PathFinder/PathFinderMetamorphicTest.php†L116-L168】【F:tests/Application/PathFinder/PathFinderPropertyTest.php†L283-L931】【F:tests/Application/PathFinder/PathFinderTest.php†L148-L1898】
- Result heap, search bootstrap, and queue ordering/unit tests normalize candidate costs with `BcMath`.【F:tests/Application/PathFinder/Result/CandidateResultHeapPropertyTest.php†L141-L218】【F:tests/Application/PathFinder/SearchBootstrapTest.php†L42-L168】【F:tests/Application/PathFinder/SearchStateQueueOrderingPropertyTest.php†L104-L161】【F:tests/Application/PathFinder/SearchStateQueueTest.php†L50-L102】
- PathFinder value-object tests assert `BcMath` validations on candidate paths and edge sequences.【F:tests/Application/PathFinder/ValueObject/CandidatePathTest.php†L32-L161】【F:tests/Application/PathFinder/ValueObject/PathEdgeSequenceTest.php†L33-L167】
- Service-level suites cover leg materialization and PathFinderService tolerances/fees (`LegMaterializerTest`, `FeesPathFinderServiceTest`, `PathFinderServiceGuardsTest`, `PathFinderServicePropertyTest`, `PathFinderServiceTestCase`, `TolerancePathFinderServiceTest`).【F:tests/Application/Service/LegMaterializerTest.php†L341-L773】【F:tests/Application/Service/PathFinder/FeesPathFinderServiceTest.php†L405-L415】【F:tests/Application/Service/PathFinder/PathFinderServiceGuardsTest.php†L433-L479】【F:tests/Application/Service/PathFinder/PathFinderServicePropertyTest.php†L101-L397】【F:tests/Application/Service/PathFinder/PathFinderServiceTestCase.php†L85-L143】【F:tests/Application/Service/PathFinder/TolerancePathFinderServiceTest.php†L106-L349】
- Support tooling and harnesses (`PathFinderScenarioGeneratorTest`, `SearchQueueTieBreakHarness`) normalize test rates with `BcMath`.【F:tests/Application/Support/Generator/PathFinderScenarioGeneratorTest.php†L151-L151】【F:tests/Application/Support/Harness/SearchQueueTieBreakHarness.php†L45-L130】
- Domain-level tests validate the `BcMath` facade and `DecimalTolerance`.【F:tests/Domain/ValueObject/BcMathTest.php†L22-L215】【F:tests/Domain/ValueObject/DecimalToleranceTest.php†L22-L56】

## 2. Numeric-string carrier inventory

| Carrier | File(s) | Ingress (creation) | Egress / consumers |
| --- | --- | --- | --- |
| Money amount & scale | `Money::$amount` normalized via `BcMath::normalize` in `fromString`, `withScale`, math operations.【F:src/Domain/ValueObject/Money.php†L24-L189】 | Accepts numeric strings from builders, ensures uppercase currency codes, normalizes to requested scale. | Exposed through `amount()`, JSON helpers, arithmetic methods returning new `Money` instances, and serialization via `SerializesMoney`.【F:src/Application/Support/SerializesMoney.php†L13-L19】 |
| Exchange rates | `ExchangeRate::$rate` normalized/validated at construction and during conversions/inversion.【F:src/Domain/ValueObject/ExchangeRate.php†L20-L83】 | `fromString` enforces >0, `convert`/`invert` produce normalized strings. | Consumers read via `rate()`, `scale()`, `ToleranceWindowFilter`, and graph edge serialization. |
| Tolerance ratios | `DecimalTolerance::$ratio` (includes `percentage()` and JSON serialization).【F:src/Domain/ValueObject/DecimalTolerance.php†L25-L140】 | `fromNumericString` clamps ratios within [0,1], `zero()` seeds defaults. | Used by `ToleranceEvaluator`, `PathResult::residualTolerance`, JSON payloads, and percentage formatting. |
| Tolerance windows | `ToleranceWindow::$minimum/$maximum/$heuristicTolerance` normalized at creation.【F:src/Domain/ValueObject/ToleranceWindow.php†L15-L125】 | `fromStrings` + `normalizeTolerance` validate [0,1). | Read by `PathSearchConfig`, `ToleranceEvaluator`, filters, and heuristics via `minimum()`, `maximum()`, `heuristicTolerance()`. |
| Path search config tolerance override | `PathSearchConfig::$pathFinderTolerance` computed from window or override, stored as numeric string.【F:src/Application/Config/PathSearchConfig.php†L24-L190】 | Derived by `resolvePathFinderTolerance` and multipliers for spend bounds. | Consumed by `PathFinderService` when seeding `PathFinder` and by downstream diagnostics via `pathFinderTolerance()`. |
| Tolerance filter bounds | `ToleranceWindowFilter::$lowerBound/$upperBound` computed from reference rate and tolerance.【F:src/Application/Filter/ToleranceWindowFilter.php†L18-L70】 | Constructor normalizes tolerance and clamps min to zero. | Used in `accepts()` comparisons before orders enter the graph. |
| Search state costs/products | `PathFinder::$unitValue`, `SearchState::$cost/$product`, `SearchStateRecord::$cost`, `CandidatePath::$cost/$product`, `PathEdge::$conversionRate`, `PathCost::$value`. | `PathFinder` normalizes unit cost and tolerance amplifier; states inherit normalized cost/product during transitions; `CandidatePath::from` ensures numeric; `PathEdge::create` validates conversion rates; `PathCost` enforces scale 18 normalization.【F:src/Application/PathFinder/PathFinder.php†L68-L728】【F:src/Application/PathFinder/Search/SearchState.php†L21-L216】【F:src/Application/PathFinder/Search/SearchStateRecord.php†L12-L50】【F:src/Application/PathFinder/ValueObject/CandidatePath.php†L21-L111】【F:src/Application/PathFinder/ValueObject/PathEdge.php†L18-L105】【F:src/Application/PathFinder/Result/Ordering/PathCost.php†L9-L54】 | Exposed through getters, result ordering (`PathCost` comparisons), queue prioritization, and candidate/result serialization (`CandidatePath::toArray()`). |
| Tolerance/residual math outputs | `PathFinder::normalizeTolerance()`, `calculateToleranceAmplifier()`, `edgeBaseToQuoteRatio()`, `calculateNextRange()`, `edgeEffectiveConversionRate()`, `ToleranceEvaluator::calculateResidualTolerance()`, `LegMaterializer::calculateSellAdjustmentRatio()` etc. | Each helper derives numeric strings via `BcMath` operations (normalize, sub, div, mul) when computing tolerances, ratios, residuals, and sell adjustments.【F:src/Application/PathFinder/PathFinder.php†L540-L728】【F:src/Application/Service/ToleranceEvaluator.php†L40-L110】【F:src/Application/Service/LegMaterializer.php†L332-L530】 | Results gate candidate pruning, amplification, guard rails, sell resolution, and tolerance evaluation before serialization to clients. |
| Result serialization | `CandidatePath::toArray()` returns cost/product strings, `PathResult::jsonSerialize()` emits tolerance strings while `SerializesMoney` surfaces amount strings; `DecimalTolerance::jsonSerialize()` returns the ratio. | Candidate and result arrays carry numeric strings without conversion, ensuring downstream consumers can parse precise costs/tolerances.【F:src/Application/PathFinder/ValueObject/CandidatePath.php†L91-L111】【F:src/Application/Result/PathResult.php†L17-L108】【F:src/Application/Support/SerializesMoney.php†L13-L19】【F:src/Domain/ValueObject/DecimalTolerance.php†L65-L140】 | API consumers, tests, and storage layers rely on these normalized string representations as the serialization boundary. |

## 3. Decimal helper behaviour

### BrickDecimalMath

- Default scale: `DEFAULT_SCALE` is 8, ensuring value objects without explicit scale use 8 decimal places.【F:src/Application/Math/BrickDecimalMath.php†L23-L26】
- Rounding: `normalize()`, `round()`, and all math helpers convert to `BigDecimal` and use half-up rounding via `toScale($scale, RoundingMode::HALF_UP)`, guaranteeing deterministic tie-breaking for values like ±0.5.【F:src/Application/Math/BrickDecimalMath.php†L41-L123】
- Working scales: addition/subtraction pick the max of operand scales and requested scale, multiplication adds operand fractional digits, and division accounts for both operand fractional digits plus the requested precision to avoid premature rounding.【F:src/Application/Math/BrickDecimalMath.php†L128-L173】
- Comparison scale: `comp()` and `scaleForComparison()` derive the max scale of the operands/fallback, so comparisons never lose precision, and helper `scaleOf()` strips signs/zeros before counting fractional digits.【F:src/Application/Math/BrickDecimalMath.php†L175-L209】

### BcMath facade

`SomeWork\P2PPathFinder\Domain\ValueObject\BcMath` simply proxies to `BrickDecimalMath`, preserving the legacy static API while inheriting its validation, rounding, and comparison heuristics (including `ensureNumeric`, `isNumeric`, and working-scale strategies).【F:src/Domain/ValueObject/BcMath.php†L12-L123】 This façade is the migration seam targeted by BigDecimal work.

## 4. Tolerance, ratio, and serialization workflows

### 4.1 Configuration & filtering

1. `ToleranceWindow::fromStrings()` normalizes min/max tolerances into [0,1) and records the heuristic tolerance source (`minimum` vs `maximum`).【F:src/Domain/ValueObject/ToleranceWindow.php†L44-L118】
2. `PathSearchConfig` consumes that window to compute spend multipliers (`1 - min`, `1 + max`) and derives `minimumSpendAmount`/`maximumSpendAmount` plus the path finder tolerance override stored as a numeric string.【F:src/Application/Config/PathSearchConfig.php†L24-L190】
3. Optional `ToleranceWindowFilter` clamps order effective rates within the reference rate ± tolerance offset before the graph is built, preventing out-of-band rates from entering the search.【F:src/Application/Filter/ToleranceWindowFilter.php†L18-L70】

### 4.2 Search-time heuristics

1. `PathFinder` seeds `unitValue` (`'1'` at scale 18) and `toleranceAmplifier` (`1 / (1 - tolerance)`), then tracks whether any tolerance is active (`hasTolerance`).【F:src/Application/PathFinder/PathFinder.php†L68-L118】
2. Each `SearchState` and `SearchStateRecord` stores normalized cost/product strings, verified via `BcMath::ensureNumeric`, ensuring priority queues compare consistent strings.【F:src/Application/PathFinder/Search/SearchState.php†L21-L216】【F:src/Application/PathFinder/Search/SearchStateRecord.php†L12-L50】
3. Edge expansion (`edgeEffectiveConversionRate`, `edgeBaseToQuoteRatio`, `convertEdgeAmount`) uses ratios derived from order capacities and conversion rates to propagate spend ranges, clamping via Money operations only after intermediate `BcMath` math finishes at high precision.【F:src/Application/PathFinder/PathFinder.php†L540-L675】
4. Tolerance normalization and amplification guard the heuristic prune step; `normalizeTolerance()` validates inputs and caps at repeating 9s, while `calculateToleranceAmplifier()` converts tolerances to multipliers.【F:src/Application/PathFinder/PathFinder.php†L683-L728】
5. Candidates record cost/product strings via `CandidatePath::from()`, and `PathCost` snapshots comparison-ready normalized values for the ordering strategy.【F:src/Application/PathFinder/ValueObject/CandidatePath.php†L21-L111】【F:src/Application/PathFinder/Result/Ordering/PathCost.php†L9-L54】

### 4.3 Materialization & residual evaluation

1. `LegMaterializer` iteratively adjusts buy/sell fills using `BcMath::div`/`mul` ratios, guards sell-resolution tolerances, and returns `Money` aggregates for gross/net spend alongside per-leg fee maps.【F:src/Application/Service/LegMaterializer.php†L320-L538】
2. `PathFinderService` invokes the leg materializer per candidate, derives total spent/received, and requests a residual tolerance from `ToleranceEvaluator` before accepting a path; any null residual rejects the candidate early.【F:src/Application/Service/PathFinderService.php†L34-L206】
3. `ToleranceEvaluator::calculateResidualTolerance()` computes |actual - desired| / desired using target-scale normalization and clamps comparisons to the configured tolerance window, finally returning a `DecimalTolerance` aggregate consumed by `PathResult`.【F:src/Application/Service/ToleranceEvaluator.php†L40-L110】

### 4.4 Serialization boundaries

1. Accepted candidates are materialized into `PathResult` instances containing `Money` totals, `DecimalTolerance` residuals, and fee/leg collections; `jsonSerialize()` emits normalized numeric strings for tolerance ratios and Money amounts via the `SerializesMoney` trait.【F:src/Application/Result/PathResult.php†L17-L108】【F:src/Application/Support/SerializesMoney.php†L13-L19】
2. `CandidatePath::toArray()` exposes raw cost/product strings and each `PathEdge`’s numeric conversion rate for debugging or downstream processing before conversion to public DTOs.【F:src/Application/PathFinder/ValueObject/CandidatePath.php†L91-L111】【F:src/Application/PathFinder/ValueObject/PathEdge.php†L18-L105】
3. `DecimalTolerance::jsonSerialize()` and `percentage()` define the exact string boundary for residual tolerances shared with clients or logs.【F:src/Domain/ValueObject/DecimalTolerance.php†L65-L140】

These flow descriptions now cover every tolerance, ratio, and serialization touchpoint so the BigDecimal migration can replicate behaviour without missing hidden `BcMath` dependencies.
