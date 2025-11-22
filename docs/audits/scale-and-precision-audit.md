# Scale Usage and Working Precision Audit - 2025-11-22

**Audit Tasks**: 0003.5 (PathFinder Scale), 0003.6 (Precision Constants), 0003.7 (Value Object Scale)  
**Auditor**: Combined architectural review  
**Date**: 2025-11-22  
**Status**: ✅ PASS - Consistent scale management

## Executive Summary

- **Canonical Scale (18)**: Used consistently across all components
- **Working Precision Constants**: Applied correctly (RATIO_EXTRA_SCALE: 4, SUM_EXTRA_SCALE: 2)
- **Value Object Scale Handling**: Correct derivation rules verified
- **Recommendation**: ✅ No action required - excellent scale discipline

---

## Part 1: Canonical Scale Usage (Task 0003.5)

### CANONICAL_SCALE = 18

**Definition**: `DecimalHelperTrait::CANONICAL_SCALE = 18`

### Usage Verification

**Core Components Using CANONICAL_SCALE**:

1. **PathFinder.php**
   ```php
   private const SCALE = self::CANONICAL_SCALE; // 18
   ```
   - All cost calculations: 18 decimals
   - All tolerance bounds: 18 decimals
   - All conversion rates: 18 decimals

2. **ToleranceWindow.php**
   ```php
   private const SCALE = self::CANONICAL_SCALE; // 18
   ```
   - All tolerance values: 18 decimals
   - Minimum/maximum bounds: 18 decimals

3. **DecimalTolerance.php**
   ```php
   private const SCALE = self::CANONICAL_SCALE; // 18
   ```
   - Ratio calculations: 18 decimals
   - Percentage conversions: 18 decimals

4. **PathFinderService.php**
   ```php
   private const COST_SCALE = 18; // References CANONICAL_SCALE in docs
   ```

5. **SpendConstraints.php**
   ```php
   private const SCALAR_SCALE = 18; // References CANONICAL_SCALE in docs
   ```

6. **CandidatePath.php**
   ```php
   $this->cost->toScale(18, RoundingMode::HALF_UP)
   $this->product->toScale(18, RoundingMode::HALF_UP)
   ```

7. **SearchStateRecord.php**
   - Normalizes costs to 18 decimals for state tracking

### Why 18 Decimals?

**Rationale** (from DecimalHelperTrait docs):

1. **Precision**: Sufficient for financial calculations with high precision requirements
2. **Ethereum Compatibility**: Matches Ethereum's wei (10^18) precision
3. **Cryptocurrency Support**: Handles Bitcoin (8) and Ethereum (18) with room to spare
4. **Intermediate Calculations**: Allows working precision additions (up to +6) without hitting MAX_SCALE (50)
5. **Determinism**: Consistent scale prevents precision mismatches across components

### Verification: All PathFinder Calculations ✅

| Operation | Scale Used | Verified |
|-----------|------------|----------|
| Cost accumulation | 18 | ✅ |
| Tolerance bounds | 18 | ✅ |
| Conversion rates | 18 | ✅ |
| State costs | 18 | ✅ |
| Candidate path costs | 18 | ✅ |
| Residual calculations | 18 | ✅ |

---

## Part 2: Working Precision Constants (Task 0003.6)

### Extra Precision Constants

#### PathFinder.php

**1. RATIO_EXTRA_SCALE = 4**

```php
/**
 * Extra precision used when converting target and source deltas into a ratio
 * to avoid premature rounding.
 */
private const RATIO_EXTRA_SCALE = 4;
```

**Usage** (Line 654):
```php
$ratio = self::scaleDecimal(
    $targetDeltaDecimal->dividedBy(
        $sourceDeltaDecimal,
        $ratioScale + self::RATIO_EXTRA_SCALE, // +4 extra precision
        RoundingMode::HALF_UP,
    ),
    $ratioScale + self::RATIO_EXTRA_SCALE,
);
```

**Purpose**: When computing ratios for interpolation, adding 4 extra decimal places prevents premature rounding that would accumulate errors in subsequent multiplication.

**2. SUM_EXTRA_SCALE = 2**

```php
/**
 * Extra precision used when applying the ratio to offsets before normalizing
 * to the target scale.
 */
private const SUM_EXTRA_SCALE = 2;
```

**Usage** (Lines 664-668):
```php
$increment = self::scaleDecimal(
    $offsetDecimal->multipliedBy($ratio),
    $ratioScale + self::SUM_EXTRA_SCALE, // +2 extra precision
);
$baseDecimal = self::scaleDecimal(
    $targetMinDecimal->plus($increment),
    $ratioScale + self::SUM_EXTRA_SCALE, // +2 extra precision
);
```

**Purpose**: When applying ratios and summing values, 2 extra decimal places provide a precision buffer before final normalization.

#### LegMaterializer.php

**1. SELL_RESOLUTION_RATIO_EXTRA_SCALE = 6**

```php
private const SELL_RESOLUTION_RATIO_EXTRA_SCALE = 6;
```

**Usage** (Lines 557, 589):
```php
// Relative difference calculation
$relativeScale = $comparisonScale + self::SELL_RESOLUTION_RATIO_EXTRA_SCALE;
$relative = $difference->dividedBy($targetDecimal->abs(), $relativeScale, RoundingMode::HALF_UP);

// Target/actual ratio calculation
$ratioScale = $scale + self::SELL_RESOLUTION_RATIO_EXTRA_SCALE;
$ratio = $targetDecimal->dividedBy($actualDecimal, $ratioScale, RoundingMode::HALF_UP);
```

**Purpose**: Sell-side resolution requires higher precision (6 extra decimals) for accurate quote amount adjustments.

**2. BUY_ADJUSTMENT_RATIO_EXTRA_SCALE = 4**

```php
private const BUY_ADJUSTMENT_RATIO_EXTRA_SCALE = 4;
```

**Usage** (Line 420):
```php
$divisionScale = $ratioScale + self::BUY_ADJUSTMENT_RATIO_EXTRA_SCALE;
$ratio = $ceilingDecimal->dividedBy($grossDecimal, $divisionScale, RoundingMode::HALF_UP);
```

**Purpose**: Buy-side adjustments use 4 extra decimals for ratio precision during iterative refinement.

### Pattern: Working Precision → Final Scale

All extra precision follows this pattern:

1. **Add extra precision** for intermediate calculations
2. **Perform operations** at higher precision
3. **Normalize** to target scale with HALF_UP
4. **Final result** at original scale

**Example**:
```php
// Start: Scale 18
// Add: +4 for ratio precision → Scale 22
$ratio = compute_at_scale(22);
// Normalize: Back to Scale 18
$result = $ratio->toScale(18, HALF_UP);
```

### Verification: Precision Constants ✅

| Constant | Value | Usage Context | Verified |
|----------|-------|---------------|----------|
| RATIO_EXTRA_SCALE | 4 | PathFinder ratio calculations | ✅ |
| SUM_EXTRA_SCALE | 2 | PathFinder sum operations | ✅ |
| SELL_RESOLUTION_RATIO_EXTRA_SCALE | 6 | Sell-side adjustments | ✅ |
| BUY_ADJUSTMENT_RATIO_EXTRA_SCALE | 4 | Buy-side adjustments | ✅ |

---

## Part 3: Value Object Scale Handling (Task 0003.7)

### Money Scale Derivation Rules

**Rule 1: Addition/Subtraction**
```php
// Money.php:165, 183
$scale ??= max($this->scale, $other->scale);
```

- Result scale = `max(left.scale, right.scale)`
- Preserves precision from both operands
- Example: `scale(2) + scale(8) = scale(8)`

**Rule 2: Multiplication/Division by Scalar**
```php
// Money.php:200, 220
$scale ??= $this->scale;
```

- Result scale = `Money instance scale` (unless overridden)
- Scalar doesn't influence scale
- Example: `Money(scale=2) * "1.5" = Money(scale=2)`

**Rule 3: Comparison**
```php
// Money.php:246
$comparisonScale = max($scale ?? max($this->scale, $other->scale), $this->scale, $other->scale);
```

- Comparison scale = `max(explicitScale, left.scale, right.scale)`
- Ensures accurate comparison at highest precision
- Normalizes both values to comparison scale

**Rule 4: Explicit Override**

All operations accept optional `?int $scale` parameter:
```php
public function add(self $other, ?int $scale = null): self
public function multiply(string $multiplier, ?int $scale = null): self
```

- Explicit scale takes precedence
- Allows caller control when needed

### ExchangeRate Conversion Scale

**Rule**: `max(money.scale, rate.scale)`

```php
// ExchangeRate.php:74
$scale ??= max($this->scale, $money->scale());
```

**Example**:
```php
$money = Money::fromString('USD', '100.00', 2);     // scale 2
$rate = ExchangeRate::fromString('USD', 'EUR', '0.85', 8); // scale 8

$converted = $rate->convert($money);
// Result scale: max(2, 8) = 8
```

**Rationale**: Preserves precision from both rate and amount.

### OrderBounds Scale Normalization

**Rule**: `max(min.scale, max.scale)`

```php
// OrderBounds.php:35-37
$scale = max($min->scale(), $max->scale());
return new self($min->withScale($scale), $max->withScale($scale));
```

- Both bounds normalized to higher scale
- Ensures consistent comparison precision
- Prevents scale mismatches in contains/clamp

### Verification: Scale Derivation ✅

| Operation | Rule | Verified |
|-----------|------|----------|
| Money + Money | max(left, right) | ✅ |
| Money - Money | max(left, right) | ✅ |
| Money * scalar | left.scale | ✅ |
| Money / scalar | left.scale | ✅ |
| Money comparison | max(left, right, explicit) | ✅ |
| ExchangeRate conversion | max(money, rate) | ✅ |
| OrderBounds creation | max(min, max) | ✅ |

---

## Cross-Component Scale Consistency

### Money → ExchangeRate → Money

```php
$money1 = Money::fromString('USD', '100.00', 2);           // scale 2
$rate = ExchangeRate::fromString('USD', 'EUR', '0.85', 8); // scale 8
$money2 = $rate->convert($money1);                         // scale max(2,8) = 8
```

✅ **Consistent**: Scale increases to preserve precision

### PathFinder → CandidatePath → Result

```php
// PathFinder: All calculations at scale 18
$cost = BigDecimal::of('123.456789012345678901')->toScale(18, HALF_UP);

// CandidatePath: Serializes at scale 18
$costString = $this->cost->toScale(18, HALF_UP)->__toString();

// Result: Maintains scale 18 throughout
```

✅ **Consistent**: Scale 18 maintained through pipeline

### Config → Bounds → Validation

```php
// PathSearchConfig: Derives bounds at scale max(spend.scale, BOUND_SCALE)
$scale = max($this->spendAmount->scale(), self::BOUND_SCALE);

// OrderBounds: Normalizes to max(min.scale, max.scale)
$scale = max($min->scale(), $max->scale());
```

✅ **Consistent**: Scale derivation preserves maximum precision

---

## Recommendations

### Immediate Actions
✅ **None required** - Scale management is exemplary

### Optional Documentation Enhancements

1. **Add Scale Decision Tree** to `docs/decimal-strategy.md`:
   ```
   Scale Selection Guide:
   - Monetary amounts: User-specified (typically 2 for fiat, 8 for crypto)
   - Exchange rates: 8+ for precision
   - Path costs: 18 (CANONICAL_SCALE)
   - Tolerances: 18 (CANONICAL_SCALE)
   - Working precision: +2 to +6 extra decimals
   ```

2. **Document Working Precision Rationale**:
   - Why RATIO_EXTRA_SCALE = 4?
   - Why SUM_EXTRA_SCALE = 2?
   - When to add extra precision?

3. **Add Examples** to code comments showing scale flow

### Long-term Monitoring

1. **PHPStan Rule**: Detect scale inconsistencies
2. **Test Coverage**: Add property tests for scale preservation
3. **Performance**: Monitor if higher scales impact performance

---

## Conclusion

**Result**: ✅ **PASS** (All Three Tasks)

**Task 0003.5 - PathFinder Scale**: ✅ PASS
- CANONICAL_SCALE (18) used consistently
- All path calculations at scale 18
- No deviations found

**Task 0003.6 - Precision Constants**: ✅ PASS
- RATIO_EXTRA_SCALE (4) applied correctly
- SUM_EXTRA_SCALE (2) applied correctly
- LegMaterializer constants (6, 4) applied correctly
- Clear purpose documentation

**Task 0003.7 - Value Object Scale**: ✅ PASS
- Money scale derivation correct
- ExchangeRate conversion scale correct
- OrderBounds normalization correct
- All rules consistently applied

**Key Success Factors**:
- Centralized CANONICAL_SCALE definition
- Consistent use of max() for scale derivation
- Working precision applied systematically
- Clear documentation of rationale
- Proper normalization after extra-precision calculations

**No remediation required.**

## Related Audits

- Task 0003.1: Float Literals ✅
- Task 0003.2: BCMath Remnants ✅
- Task 0003.3: PHP Math Functions ✅
- Task 0003.4: RoundingMode ✅
- Task 0003.8: Comparison Operations (see comparison-audit.md)
- Task 0003.9: Serialization Boundaries (see serialization-audit.md)

## Audit Trail

- **Audit Date**: 2025-11-22
- **Commit**: [current]
- **Branch**: main
- **Canonical Scale**: 18 (verified ✅)
- **Working Precision Constants**: 4 constants (verified ✅)
- **Scale Derivation Rules**: 7 rules (verified ✅)
- **Auditor**: Manual architectural review
- **Conclusion**: ✅ **PASS** - Excellent scale management

