# Decimal strategy

This document codifies the decimal guarantees promised by the public API and serves as the
migration plan for the forthcoming BigDecimal refactors. All component-level work should
link back to the relevant section so that new invariants remain self-contained.

## Canonical scale and rounding policy

| Concern                     | Specification                                                                                                                                                                                                                                            | Notes                                                                                                                                                                                                                                                  |
|-----------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Monetary amounts            | `Money::fromString()` defaults to two decimals but callers may raise the scale per currency. Normalization occurs directly via `BigDecimal::toScale()` and falls back to eight fractional digits when a value object does not declare its own precision. | Arithmetic between `Money` instances derives the maximum scale of both operands before rounding, ensuring mixed-scale inputs never lose precision mid-operation.【F:src/Domain/ValueObject/Money.php†L24-L116】                                          |
| Tolerances and search costs | The path finder enforces a canonical scale of 18 decimal places for tolerance ratios, best-path costs and amplification values (`PathFinder::SCALE`).                                                                                                    | `SearchState`, `CandidatePath`, `PathCost`, and `DecimalTolerance` must continue to normalize to 18 decimals so residual reporting remains comparable across environments.【F:src/Application/PathFinder/PathFinder.php†L66-L137】                       |
| Ratio working precision     | Ratio derivation adds four fractional digits beyond the canonical tolerance scale (`PathFinder::RATIO_EXTRA_SCALE = 4`).                                                                                                                                 | This protects the `base->quote` conversion math from truncation when evaluating thin-liquidity edges.                                                                                                                                                  |
| Sum working precision       | Amount accumulation applies an additional two digits of working precision before rounding back to the requested scale (`PathFinder::SUM_EXTRA_SCALE = 2`).                                                                                               | The guard prevents rounding drift when repeatedly summing partially-filled segments.                                                                                                                                                                   |
| Rounding mode               | Every normalization and arithmetic helper uses `RoundingMode::HALF_UP`.                                                                                                                                                                                  | Decimal ties (`±0.5`) therefore always round away from zero, matching the legacy BCMath behaviour while remaining deterministic across PHP builds.【F:src/Domain/ValueObject/Money.php†L24-L116】【F:src/Application/PathFinder/PathFinder.php†L166-L212】 |

## BigDecimal ownership matrix

| Component group                                                                                      | BigDecimal ownership plan                                                                                                                                                                                                              | Public interface plan                                                                                                                    |
|------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------|
| Domain value objects (`Money`, `ExchangeRate`, `DecimalTolerance`, `ToleranceWindow`, `OrderBounds`) | Store `BigDecimal` instances internally for amounts, rates, and tolerance ratios. Builders keep accepting numeric strings but immediately convert to `BigDecimal`.                                                                     | Getter methods emit normalized numeric strings so downstream integrations work with familiar string representations.   |
| Order aggregates (`Order`, `OrderBook`, `OrderBounds`)                                               | Orders reuse the BigDecimal-backed value objects; no additional storage changes are required beyond adopting the upgraded value object APIs.                                                                                           | Public constructors remain string-first for backwards compatibility.                                                                     |
| Graph primitives (`GraphEdge`, `EdgeCapacity`, `EdgeSegmentCollection`)                              | Consume BigDecimal-backed value objects and store BigDecimal copies for computed ratios (capacity-to-rate multipliers, per-hop ratios).                                                                                                | Debug/inspection helpers (`toArray()`) convert BigDecimals to strings via the shared formatter.                       |
| Search core (`PathFinder`, `SearchState`, `SearchStateRecord`, `CandidatePath`, `PathCost`)          | Cost, product, and ratio properties become `BigDecimal` fields to avoid repeated string parsing. Working precision constants (`SCALE`, `RATIO_EXTRA_SCALE`, `SUM_EXTRA_SCALE`) define the normalization boundary. | The queue ordering and result materialization layers emit numeric strings for consistent API behavior. |
| Services (`PathFinderService`, `ToleranceEvaluator`, `LegMaterializer`)                              | Operate entirely on `BigDecimal` inputs produced by the upgraded value objects and search states. Reusable helpers (e.g. residual tolerance computation) accept/return `BigDecimal` instances to avoid repeated conversions.           | DTOs returned by services (guard reports, path results) keep exposing strings and `Money` aggregates for API callers.                    |
| API Layer (`Path`, `PathHop`, `PathHopCollection`, `MoneyMap`)                                        | Receive BigDecimal-backed value objects and format them as strings for API consumption. `Path` aggregates hop collections to expose totals/residual tolerance while preserving hop-level inspection. | This ensures clients receive normalized numeric strings through the hop-centric object API methods.                  |

## Serialization boundaries and helper plan

* **Inbound data** – Builders (`Money::fromString`, `ExchangeRate::fromString`,
  `PathSearchConfig::builder()`, DTO hydration) accept numeric strings and immediately
  convert to `BigDecimal`. Validation remains string-based so error messages stay familiar.
* **Internal processing** – Application services, the graph, and the search core only pass
  `BigDecimal` instances once constructed. This removes redundant parsing and guarantees
  that all working-precision adjustments live alongside the owning value objects instead
  of flowing through a shared facade.
* **Outbound formatting** – Public DTOs (`Path`, `PathHop`, `PathHopCollection`,
  `MoneyMap`, `PathResultSet`, guard reports) convert their `BigDecimal` payloads to numeric strings at
  the moment they are accessed through the API, ensuring hop-based paths are formatted consistently.
* **Helper utilities** – Introduce a `DecimalFormatter` with methods like
  `DecimalFormatter::toString(BigDecimal $value, int $scale, bool $trimTrailingZeros = false)`
  and `DecimalFormatter::percentage(BigDecimal $ratio, int $scale = 2)` so every outbound
  string honours the canonical policy. `SerializesMoney` will call into this formatter when
  emitting tolerance or ratio metadata alongside `Money` payloads.
* **API consumers** – DTOs such as `Path` and `PathHop` continue their
  current role as serialization boundaries for hop-centric paths. They will invoke the formatter (rather than
  ad-hoc string helpers) to maintain consistent numeric-string representations when
  emitting tolerances, costs, guard counters, and per-hop breakdowns.

## BrickDecimalMath retirement

BrickDecimalMath has been removed now that each value object owns its `BigDecimal`
normalization rules. Production code constructs decimals directly inside `Money`,
`ExchangeRate`, tolerance windows, and the search states, eliminating the shared facade.
Tests and benchmarks that need deterministic numeric strings rely on the 
`SomeWork\P2PPathFinder\Tests\Unit\Support\DecimalMath` helper instead, keeping the
canonical rounding policy available without reintroducing a production dependency.【F:tests/Support/DecimalMath.php†L1-L120】

## Scale Application Examples

Understanding how scales work in practice is key to using the library correctly. Here are concrete examples showing scale usage across different scenarios.

### Example 1: Fiat Currency (USD)

```php
use SomeWork\P2PPathFinder\Domain\Money\Money;

// USD typically uses 2 decimal places (cents)
$amount = Money::fromString('USD', '123.45', 2);

echo $amount->amount();    // "123.45"
echo $amount->currency();  // "USD"
echo $amount->scale();     // 2
```

**Why scale 2?** Most fiat currencies are divisible to 2 decimal places (cents, pence, etc.). This matches real-world transaction precision.

### Example 2: Cryptocurrency (BTC)

```php
use SomeWork\P2PPathFinder\Domain\Money\Money;

// Bitcoin typically uses 8 decimal places (satoshis)
$btc = Money::fromString('BTC', '0.12345678', 8);

echo $btc->amount();    // "0.12345678"
echo $btc->currency();  // "BTC"
echo $btc->scale();     // 8
```

**Why scale 8?** Bitcoin's smallest unit (1 satoshi) is 0.00000001 BTC. Scale 8 ensures we can represent exact satoshi amounts.

### Example 3: Exchange Rates

```php
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;use SomeWork\P2PPathFinder\Domain\Money\Money;

// EUR/USD rate with high precision
$rate = ExchangeRate::fromString('EUR', 'USD', '1.085432', 6);

$euros = Money::fromString('EUR', '100.00', 2);
$dollars = $rate->convert($euros);

echo $dollars->amount();  // "108.54" (rounded to target currency scale)
echo $dollars->scale();   // 2 (inherits USD scale)
```

**Why scale 6 for rates?** Exchange rates need higher precision than amounts to avoid cumulative rounding errors during conversion.

### Example 4: Tolerance Windows

```php
use SomeWork\P2PPathFinder\Domain\Tolerance\ToleranceWindow;

// Tolerance: 0% to 5% (5 decimal places for precision)
$tolerance = ToleranceWindow::fromScalars('0.00000', '0.05000', 5);

echo $tolerance->minTolerance(); // "0.00000"
echo $tolerance->maxTolerance(); // "0.05000"
```

**Why scale 5?** Tolerances are ratios (0 to < 1). Higher scales allow fine-grained control (e.g., 0.001% = 0.00001).

### Example 5: Multi-Hop Path with Mixed Scales

```php
// Scenario: BTC (scale 8) → USD (scale 2) → EUR (scale 2)

// Order 1: BTC/USD
$btcUsdRate = ExchangeRate::fromString('BTC', 'USD', '42350.25', 2);
$btc = Money::fromString('BTC', '0.10000000', 8);
$usd = $btcUsdRate->convert($btc);
// $usd = "4235.03" USD (scale 2)

// Order 2: USD/EUR
$usdEurRate = ExchangeRate::fromString('USD', 'EUR', '0.921874', 6);
$eur = $usdEurRate->convert($usd);
// $eur = "3903.71" EUR (scale 2)
```

**Key insight:** The library automatically derives the correct scale at each hop, ensuring precision is maintained while matching the target currency's scale.

## Scale Application by Component

This table shows how scales are applied across different components of the library:

| Component             | Scale         | Rationale                                     | Example                                            |
|-----------------------|---------------|-----------------------------------------------|----------------------------------------------------|
| **Money (Fiat)**      | 2             | Matches real-world currency precision (cents) | USD: `"123.45"`                                    |
| **Money (Crypto)**    | 8-18          | Matches blockchain precision (satoshis, wei)  | BTC: `"0.12345678"`, ETH: `"1.234567890123456789"` |
| **ExchangeRate**      | 6-12          | Higher precision for accurate conversion      | `"1.085432"` (6 decimals)                          |
| **OrderBounds**       | Same as Money | Bounds must match amount precision            | Min: `"100.00"`, Max: `"1000.00"` (scale 2)        |
| **ToleranceWindow**   | 5-10          | Fine-grained tolerance control                | `"0.05000"` (5% tolerance, scale 5)                |
| **PathFinder Costs**  | 18            | Internal working precision for comparisons    | `"1.234567890123456789"`                           |
| **PathFinder Ratios** | 22 (18+4)     | Extra precision for thin-liquidity math       | Internal only                                      |
| **PathFinder Sums**   | 20 (18+2)     | Extra precision for accumulation              | Internal only                                      |

### Scale Derivation Rules

The library follows these automatic scale derivation rules:

1. **Conversion output scale** = Target currency's scale
   - Converting BTC (scale 8) to USD → result has scale 2 (USD's scale)
   
2. **Arithmetic result scale** = `max(operand1.scale, operand2.scale)`
   - Adding USD (scale 2) + EUR (scale 2) → result has scale 2
   - Adding BTC (scale 8) + USD (scale 2) → **ERROR** (different currencies)

3. **Internal cost scale** = 18 (fixed for comparisons)
   - All path costs normalized to scale 18 for deterministic ordering

4. **Working precision** = Base scale + extra digits
   - Ratio calculations: +4 extra digits (prevents truncation)
   - Sum calculations: +2 extra digits (prevents rounding drift)

## Troubleshooting Common Precision Issues

This section addresses common precision-related problems and their solutions.

### Issue 1: "InvalidInput: Scale must be between 0 and 50"

**Symptom:** Exception thrown when creating Money or ExchangeRate.

**Cause:** Scale parameter is too high (> 50 decimal places).

**Solution:**
```php
// ❌ Wrong: Scale too high
$money = Money::fromString('BTC', '0.123456789012345678901234567890123456789012345678901234567890', 60);
// Throws: InvalidInput

// ✅ Correct: Use scale ≤ 50
$money = Money::fromString('BTC', '0.12345678901234567890123456789012345678901234567890', 50);
```

**Why?** The library enforces a maximum scale of 50 decimal places. This is sufficient for all real-world financial calculations including high-precision cryptocurrency amounts.

### Issue 2: "Results differ slightly from expected"

**Symptom:** Calculated amounts are off by 0.01 or similar small amount.

**Cause:** Rounding occurs when converting between currencies with different scales.

**Example:**
```php
$rate = ExchangeRate::fromString('EUR', 'USD', '1.085', 3);
$euros = Money::fromString('EUR', '100.00', 2);
$dollars = $rate->convert($euros);

// Expected: 108.50 USD
// Actual: 108.50 USD (correct, but intermediate value was 108.5 before rounding)
```

**Solution:** Use higher scale for exchange rates (6-12 decimals) to minimize rounding errors:
```php
// ✅ Better: Higher precision rate
$rate = ExchangeRate::fromString('EUR', 'USD', '1.085432', 6);
$dollars = $rate->convert($euros);
// More accurate conversion
```

**Rule of thumb:** Exchange rate scale should be at least 4 digits higher than the currency scales involved.

### Issue 3: "Tolerance window collapses to zero"

**Symptom:** Tolerance window becomes ineffective, search finds no paths.

**Cause:** Tolerance scale is too low, causing min and max to round to the same value.

**Example:**
```php
// ❌ Wrong: Scale too low
$tolerance = ToleranceWindow::fromScalars('0.00', '0.05', 2);
// Internal representation: min=0.00, max=0.05

// If tolerance is very small (e.g., 0.001):
$tolerance = ToleranceWindow::fromScalars('0.00', '0.001', 2);
// Rounds to: min=0.00, max=0.00 → COLLAPSED!
```

**Solution:** Use higher scale for tolerances (5-10 decimals):
```php
// ✅ Correct: Higher scale preserves small tolerances
$tolerance = ToleranceWindow::fromScalars('0.00000', '0.00100', 5);
// Represents 0.1% tolerance accurately
```

### Issue 4: "Path costs are not deterministic across runs"

**Symptom:** Same inputs produce slightly different costs or path ordering.

**Cause:** Inconsistent scale usage in path cost calculations.

**This should never happen** with the library's current implementation. All internal costs use scale 18 and `RoundingMode::HALF_UP`. If you observe non-deterministic behavior:

1. Verify all input data (orders, amounts, rates) is identical
2. Check for floating-point literals in your code (use string inputs)
3. Report as a bug (this is a critical invariant)

**Example of correct usage:**
```php
// ✅ Always use string inputs
$amount = Money::fromString('USD', '100.00', 2);

// ❌ Never use float literals
$amount = Money::fromString('USD', (string)(100.0 * 1.05), 2); // NON-DETERMINISTIC
```

### Issue 5: "Property tests fail with precision errors"

**Symptom:** Property-based tests fail due to small precision differences.

**Cause:** Property tests with low scales (e.g., scale 2) accumulate rounding errors in complex operations.

**Solution:** Use appropriate tolerances in assertions:
```php
// In property tests
public function testConversionRoundtrip(): void
{
    $rate = $this->randomExchangeRate(minScale: 6); // Higher scale
    $money = $this->randomMoney();
    
    $converted = $rate->convert($money);
    $back = $rate->invert()->convert($converted);
    
    // ✅ Use tolerance for roundtrip (2% acceptable)
    $this->assertMoneyEqualsWithTolerance($money, $back, 0.02);
}
```

**Why?** Roundtrip operations (convert → invert → convert) accumulate rounding at each step. A small tolerance accounts for this while still catching real bugs.

### Issue 6: "Fees exceed spend amount"

**Symptom:** `InvalidInput: Fee percentage results in amount exceeding the input`

**Cause:** Fee is >= 100%, leaving zero or negative effective amount.

**Example:**

```php
use SomeWork\P2PPathFinder\Domain\Order\PercentageFeePolicy;

// ❌ Wrong: 100% fee
$feePolicy = new PercentageFeePolicy('1.00'); // 100%
$spent = Money::fromString('USD', '100.00', 2);
$received = Money::fromString('EUR', '85.00', 2);

$breakdown = $feePolicy->calculate($spent, $received);
// Throws: Fee percentage results in amount exceeding the input
```

**Solution:** Keep fees < 100%, typically < 10%:
```php
// ✅ Correct: Reasonable fee (2.5%)
$feePolicy = new PercentageFeePolicy('0.025');
```

## Quick Reference: Choosing the Right Scale

Use this decision tree to choose appropriate scales:

```
Are you working with money amounts?
├─ Yes
│  ├─ Fiat currency (USD, EUR, JPY, etc.)?
│  │  └─ Use scale 2 (cents)
│  │     Example: Money::fromString('USD', '123.45', 2)
│  │
│  └─ Cryptocurrency?
│     ├─ Bitcoin (BTC): Use scale 8 (satoshis)
│     ├─ Ethereum (ETH): Use scale 18 (wei)
│     └─ Other: Check blockchain's smallest unit
│
├─ Are you working with exchange rates?
│  └─ Use scale 6-12 (higher than currency scales)
│     Example: ExchangeRate::fromString('EUR', 'USD', '1.085432', 6)
│
├─ Are you working with tolerance windows?
│  └─ Use scale 5-10 (fine-grained control)
│     Example: ToleranceWindow::fromScalars('0.00000', '0.05000', 5)
│
└─ Are you working with order bounds?
   └─ Use same scale as the currency
      Example: OrderBounds with USD → scale 2
```

## Best Practices Summary

1. **Always use string inputs** for amounts, rates, and tolerances
   - ✅ `Money::fromString('USD', '123.45', 2)`
   - ❌ `Money::fromString('USD', (string)(123.45), 2)` (float literal)

2. **Match scales to real-world precision**
   - Fiat: 2 decimals
   - Crypto: 8-18 decimals
   - Rates: 6-12 decimals

3. **Use higher scales for rates than amounts**
   - If amounts use scale 2, rates should use scale 6+

4. **Don't exceed scale 50**
   - Maximum enforced by the library

5. **Use tolerances in property tests**
   - Complex operations accumulate rounding
   - Small tolerance (0.1-2%) catches bugs while allowing acceptable rounding

6. **Never use native PHP math functions for money**
   - ❌ `$amount * 1.05` (float math)
   - ✅ `$amount->multipliedBy('1.05')` (BigDecimal math)

For more troubleshooting guidance, see [docs/troubleshooting.md](troubleshooting.md).
