# Decimal strategy

This document codifies the decimal guarantees promised by the public API and serves as the
migration plan for the forthcoming BigDecimal refactors. All component-level work should
link back to the relevant section so that new invariants remain self-contained.

## Canonical scale and rounding policy

| Concern | Specification | Notes |
| --- | --- | --- |
| Monetary amounts | `Money::fromString()` defaults to two decimals but callers may raise the scale per currency. Normalization always flows through `BrickDecimalMath::normalize()`, which falls back to `BrickDecimalMath::DEFAULT_SCALE` (8) when a value object does not declare its own precision. | Arithmetic between `Money` instances derives the maximum scale of both operands before rounding, ensuring mixed-scale inputs never lose precision mid-operation.【F:src/Domain/ValueObject/Money.php†L24-L116】 |
| Tolerances and search costs | The path finder enforces a canonical scale of 18 decimal places for tolerance ratios, best-path costs and amplification values (`PathFinder::SCALE`). | `SearchState`, `CandidatePath`, `PathCost`, and `DecimalTolerance` must continue to normalize to 18 decimals so residual reporting remains comparable across environments.【F:src/Application/PathFinder/PathFinder.php†L66-L137】 |
| Ratio working precision | Ratio derivation adds four fractional digits beyond the canonical tolerance scale (`PathFinder::RATIO_EXTRA_SCALE = 4`). | This protects the `base->quote` conversion math from truncation when evaluating thin-liquidity edges. |
| Sum working precision | Amount accumulation applies an additional two digits of working precision before rounding back to the requested scale (`PathFinder::SUM_EXTRA_SCALE = 2`). | The guard prevents rounding drift when repeatedly summing partially-filled segments. |
| Rounding mode | Every normalization and arithmetic helper uses `RoundingMode::HALF_UP`. | Decimal ties (`±0.5`) therefore always round away from zero, matching the legacy BCMath behaviour while remaining deterministic across PHP builds.【F:src/Application/Math/BrickDecimalMath.php†L23-L176】 |

## BigDecimal ownership matrix

| Component group | BigDecimal ownership plan | Public interface plan |
| --- | --- | --- |
| Domain value objects (`Money`, `ExchangeRate`, `DecimalTolerance`, `ToleranceWindow`, `OrderBounds`) | Store `BigDecimal` instances internally for amounts, rates, and tolerance ratios. Builders keep accepting numeric strings but immediately convert to `BigDecimal`. | Getter and JSON helpers continue to emit normalized numeric strings so downstream integrations do not need to understand `BigDecimal`. |
| Order aggregates (`Order`, `OrderBook`, `OrderBounds`) | Orders reuse the BigDecimal-backed value objects; no additional storage changes are required beyond adopting the upgraded value object APIs. | Public constructors remain string-first for backwards compatibility. |
| Graph primitives (`GraphEdge`, `EdgeCapacity`, `EdgeSegmentCollection`) | Consume BigDecimal-backed value objects and store BigDecimal copies for computed ratios (capacity-to-rate multipliers, per-leg ratios). | Debug/inspection helpers (`toArray()`, `jsonSerialize()`) convert BigDecimals to strings via the shared formatter. |
| Search core (`PathFinder`, `SearchState`, `SearchStateRecord`, `CandidatePath`, `PathCost`) | Cost, product, and ratio properties become `BigDecimal` fields to avoid repeated string parsing. Working precision constants (`SCALE`, `RATIO_EXTRA_SCALE`, `SUM_EXTRA_SCALE`) define the normalization boundary before serialization. | The queue ordering and result materialization layers still emit numeric strings so property-based tests and JSON payloads remain stable. |
| Services (`PathFinderService`, `ToleranceEvaluator`, `LegMaterializer`) | Operate entirely on `BigDecimal` inputs produced by the upgraded value objects and search states. Reusable helpers (e.g. residual tolerance computation) accept/return `BigDecimal` instances to avoid repeated conversions. | DTOs returned by services (guard reports, path results) keep exposing strings and `Money` aggregates for API callers. |
| Serialization (`PathResult`, `PathLeg`, `MoneyMap`, `SerializesMoney`) | Receive BigDecimal-backed value objects and call a shared formatter before emitting arrays/JSON. `SerializesMoney` continues to centralize `Money` serialization while the new formatter handles tolerances and ratios. | This isolates string conversion to the serialization boundary and ensures clients continue to consume normalized numeric strings. |

## Serialization boundaries and helper plan

* **Inbound data** – Builders (`Money::fromString`, `ExchangeRate::fromString`,
  `PathSearchConfig::builder()`, DTO hydration) accept numeric strings and immediately
  convert to `BigDecimal`. Validation remains string-based so error messages stay familiar.
* **Internal processing** – Application services, the graph, and the search core only pass
  `BigDecimal` instances once constructed. This removes redundant parsing and guarantees
  that all working-precision adjustments take place via `BrickDecimalMath`.
* **Outbound formatting** – Public DTOs (`PathResult`, `PathLeg`, `MoneyMap`,
  `PathResultSet`, guard reports) convert their `BigDecimal` payloads to numeric strings at
  the moment they serialize to arrays or JSON.
* **Helper utilities** – Introduce a `DecimalFormatter` with methods like
  `DecimalFormatter::toString(BigDecimal $value, int $scale, bool $trimTrailingZeros = false)`
  and `DecimalFormatter::percentage(BigDecimal $ratio, int $scale = 2)` so every outbound
  string honours the canonical policy. `SerializesMoney` will call into this formatter when
  emitting tolerance or ratio metadata alongside `Money` payloads.
* **JSON encoders** – The `SerializesMoney` trait and DTOs such as `PathResult` retain their
  current role as serialization boundaries. They will invoke the formatter (rather than
  `BcMath`) to maintain consistent numeric-string representations when emitting tolerances,
  costs, guard counters, and per-leg breakdowns.

## BrickDecimalMath and helper strategy

BrickDecimalMath remains the canonical place for enforcing validation, working-scale
selection, and rounding. To align it with the BigDecimal migration we will:

1. **Evolve the helper instead of retiring it.** The class will expose both `BigDecimal`
   operations (addition, subtraction, multiplication, division, comparison) and string
   adapters for the few boundary points that still need to accept numeric strings.
2. **Fold the static `BcMath` facade into the helper.** Value objects and services will
   depend on an injectable `BrickDecimalMath` (or `DecimalContext`) instance so tests can
   swap in deterministic fixtures and we can eventually delete `SomeWork\\...\\BcMath`.
3. **Share formatter utilities.** `DecimalFormatter` will live alongside `BrickDecimalMath`
   so both string normalization and BigDecimal creation use the same rounding mode and
   working-precision heuristics.
4. **Stage the rollout.**
   - Update value objects to request `BigDecimal` results from `BrickDecimalMath`.
   - Convert application services and search states to store those objects directly.
   - Remove the legacy `BcMath` facade once no production code depends on its static API.

This approach keeps the deterministic arithmetic guarantees intact while reducing the
surface area that still manipulates numeric strings internally.
