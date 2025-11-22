# Domain Model Invariants

This document defines the invariants enforced by value objects and domain entities in the p2p-path-finder library. These invariants are fundamental constraints that are always maintained by the domain model, ensuring data integrity and preventing invalid states.

## Overview

Value objects in this library enforce invariants through:
- **Immutability**: All value objects are immutable after construction
- **Validation in constructors**: Invalid states are rejected early with `InvalidInput` exceptions
- **Named constructors**: Factory methods ensure validation before object creation
- **Type safety**: Strong typing and PHPDoc annotations prevent type errors

---

## Money Invariants

**Class**: `SomeWork\P2PPathFinder\Domain\ValueObject\Money`

Money represents a monetary amount in a specific currency with arbitrary precision decimal arithmetic. It enforces strict constraints to maintain correctness in financial calculations.

### Amount Range: Non-Negative Only

**Policy**: Money amounts **MUST be non-negative** (>= 0).

**Rationale**: 
In the path-finding domain, all monetary amounts have clear semantic meaning as non-negative values:
- **Orders** represent offers to exchange currency A for currency B (both amounts >= 0)
- **Spend amounts** are what users want to spend (>= 0)
- **Received amounts** are what users get back (>= 0)
- **Fees** are costs that reduce proceeds (>= 0)
- **Path costs** are cumulative cost metrics (>= 0)

Negative amounts have no semantic meaning in this domain. Allowing negatives would permit nonsensical states like "negative spend" or "negative fee" that have no real-world interpretation in peer-to-peer currency exchange.

Additionally, rejecting negatives provides **fail-fast behavior**: if a bug causes subtraction to produce a negative amount (e.g., fee > traded amount), the system immediately throws an exception with a clear error message, making debugging trivial.

**Validation**: 
- Enforced in `Money::fromString()` 
- Throws `InvalidInput` with message: "Money amount cannot be negative. Got: {currency} {amount}"
- Check performed after parsing but before scaling to catch the issue as early as possible

**Valid Examples**:
```php
✅ Money::fromString('USD', '100.00', 2);      // Positive amount
✅ Money::fromString('USD', '0.00', 2);        // Zero is allowed
✅ Money::fromString('BTC', '0.00000001', 8);  // Small positive
```

**Invalid Examples**:
```php
❌ Money::fromString('USD', '-10.00', 2);      // Throws InvalidInput
❌ Money::fromString('EUR', '-0.01', 2);       // Throws InvalidInput
❌ Money::fromString('BTC', '-1.5', 8);        // Throws InvalidInput
```

**Arithmetic Operations**:
All arithmetic operations preserve the non-negative invariant:
- `add()`: Adding two non-negative values always produces non-negative result
- `subtract()`: If subtraction would produce negative, it indicates a bug in the caller (e.g., fee > amount)
- `multiply()`: Multiplying non-negative by non-negative always produces non-negative
- `divide()`: Dividing non-negative by positive always produces non-negative

### Currency Code Format

**Policy**: Currency codes must be 3-12 uppercase alphabetic characters.

**Validation**: 
- Pattern: `/^[A-Z]{3,12}$/i` (case-insensitive input, normalized to uppercase)
- Enforced in `assertCurrency()` private method
- Throws `InvalidInput` for invalid codes

**Valid Examples**:
```php
✅ Money::fromString('USD', '100.00', 2);   // Standard ISO 4217
✅ Money::fromString('btc', '1.5', 8);      // Crypto (normalized to BTC)
✅ Money::fromString('CUSTOMCOIN', '50', 0); // Custom 10-char code
```

**Invalid Examples**:
```php
❌ Money::fromString('', '100', 2);         // Empty
❌ Money::fromString('US', '100', 2);       // Too short (< 3)
❌ Money::fromString('TOOLONGCURRENCY', '100', 2); // Too long (> 12)
❌ Money::fromString('US$', '100', 2);      // Contains special char
```

**Rationale**: This allows standard ISO 4217 codes (USD, EUR, GBP) while also supporting cryptocurrency tickers (BTC, ETH) and custom tokens. The 3-12 character range provides flexibility while preventing abuse.

### Scale Boundaries

**Policy**: Scale (decimal places) must be between 0 and 50 inclusive.

**Validation**:
- Minimum: 0 (allows integer-only amounts)
- Maximum: 50 (prevents memory exhaustion and performance degradation)
- Enforced in `assertScale()` private method
- Throws `InvalidInput` for out-of-range values

**Valid Examples**:
```php
✅ Money::fromString('USD', '100', 0);      // Scale 0 (integer)
✅ Money::fromString('USD', '100.00', 2);   // Scale 2 (standard fiat)
✅ Money::fromString('BTC', '0.12345678', 8); // Scale 8 (Bitcoin)
✅ Money::fromString('ETH', '1.234567890123456789', 18); // Scale 18 (Ethereum wei)
```

**Invalid Examples**:
```php
❌ Money::fromString('USD', '100', -1);     // Negative scale
❌ Money::fromString('USD', '100', 51);     // Exceeds maximum
```

**Rationale**: 
- Scale 0 supports integer-based currencies
- Scale 2 is standard for most fiat currencies  
- Scale 8-18 supports cryptocurrencies (Bitcoin uses 8, Ethereum uses 18)
- Maximum of 50 prevents potential memory/performance issues with arbitrary precision math
- The limit is generous enough for any real-world use case

### Precision and Rounding

**Policy**: All amounts are normalized to the specified scale using HALF_UP rounding.

**Behavior**:
- Internal representation uses `Brick\Math\BigDecimal` for arbitrary precision
- Normalization applies `toScale($scale, RoundingMode::HALF_UP)`
- Trailing zeros are preserved in string representation
- Same amount at same scale is always represented identically (deterministic)

**Examples**:
```php
Money::fromString('USD', '100.999', 2)->amount();
// Returns: "101.00" (rounded up)

Money::fromString('USD', '100.004', 2)->amount();
// Returns: "100.00" (rounded down)

Money::fromString('USD', '100.005', 2)->amount();
// Returns: "100.01" (half-up: rounds up on 5)

Money::fromString('USD', '100', 2)->amount();
// Returns: "100.00" (trailing zeros preserved)
```

**Rationale**: HALF_UP is the standard commercial rounding mode and provides deterministic results across all platforms.

---

## Scale Derivation in Arithmetic Operations

When performing arithmetic between Money instances with different scales, the library automatically derives an appropriate result scale.

**Policy**: Result scale is `max(leftScale, rightScale)` unless explicitly overridden.

**Examples**:
```php
$a = Money::fromString('USD', '100.00', 2);    // scale 2
$b = Money::fromString('USD', '50.5', 1);      // scale 1

$sum = $a->add($b);
// Result scale: max(2, 1) = 2
// $sum->amount() === "150.50"

$diff = $a->subtract($b);
// Result scale: max(2, 1) = 2
// $diff->amount() === "49.50"

// Explicit scale override
$sum = $a->add($b, 4);
// Result scale: 4 (explicit)
// $sum->amount() === "150.5000"
```

**Rationale**: Using the maximum scale preserves the most precision from either operand. Explicit scale override allows consumers to control precision when needed.

---

## ExchangeRate Invariants

**Class**: `SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate`

ExchangeRate represents a conversion rate between two distinct currencies. It ensures rates are always positive and that the currency pair is well-defined.

### Distinct Currency Requirement

**Policy**: Base and quote currencies **MUST be distinct** (case-insensitive comparison).

**Validation**:
- Enforced in `ExchangeRate::fromString()`
- Uses `strcasecmp()` for case-insensitive comparison
- Throws `InvalidInput` with message: "Exchange rate requires distinct currencies."

**Valid Examples**:
```php
✅ ExchangeRate::fromString('USD', 'EUR', '0.85', 8);
✅ ExchangeRate::fromString('BTC', 'USDT', '45000.00', 8);
✅ ExchangeRate::fromString('usd', 'EUR', '0.85', 8);  // Case-insensitive
```

**Invalid Examples**:
```php
❌ ExchangeRate::fromString('USD', 'USD', '1.0', 8);   // Same currency
❌ ExchangeRate::fromString('btc', 'BTC', '1.0', 8);   // Same (case-insensitive)
```

**Rationale**: Exchange rates describe conversions between distinct assets. A rate from USD to USD is nonsensical and likely indicates a bug.

### Positive Rate Requirement

**Policy**: Exchange rates **MUST be strictly positive** (> 0).

**Validation**:
- Enforced in `ExchangeRate::fromString()`
- Compares normalized rate against `BigDecimal::zero()`
- Throws `InvalidInput` with message: "Exchange rate must be greater than zero."

**Valid Examples**:
```php
✅ ExchangeRate::fromString('USD', 'EUR', '0.85', 8);
✅ ExchangeRate::fromString('BTC', 'USD', '0.00000001', 8); // Very small but positive
✅ ExchangeRate::fromString('ETH', 'BTC', '999999.99', 8);  // Very large
```

**Invalid Examples**:
```php
❌ ExchangeRate::fromString('USD', 'EUR', '0', 8);     // Zero
❌ ExchangeRate::fromString('USD', 'EUR', '-0.85', 8); // Negative
```

**Rationale**: Zero or negative exchange rates have no economic meaning. A zero rate would imply infinite devaluation; negative rates are nonsensical.

### Currency Format

**Policy**: Both base and quote currencies must follow the same format rules as `Money` (3-12 uppercase alphabetic characters).

**Validation**: Delegates to `Money::fromString()` for currency validation.

### Scale Boundaries

**Policy**: Same as Money - scale must be between 0 and 50 inclusive.

**Rationale**: Exchange rates need high precision (Bitcoin rates might have 8+ decimals), but the same memory/performance constraints apply.

### Rate Inversion

**Policy**: Inverting a rate produces `1 / rate` with the base and quote currencies swapped.

**Behavior**:
- Uses `BigDecimal::one()->dividedBy($rate, scale + 1, HALF_UP)`
- Extra precision digit added during division, then normalized to original scale
- Ensures double-inversion returns approximately the original rate (within rounding error)

**Example**:
```php
$rate = ExchangeRate::fromString('USD', 'EUR', '0.85000000', 8);
// USD -> EUR at 0.85

$inverted = $rate->invert();
// EUR -> USD at 1.17647059 (approximately 1 / 0.85)

$doubleInverted = $inverted->invert();
// USD -> EUR at ~0.85000000 (close to original within rounding)
```

**@invariant Annotations**:
```php
@invariant baseCurrency != quoteCurrency (case-insensitive)
@invariant rate > 0
@invariant scale >= 0 && scale <= 50
@invariant invert() returns rate with swapped currencies
@invariant invert()->invert() ≈ original (within rounding error)
```

---

## OrderBounds Invariants

**Class**: `SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds`

OrderBounds represents inclusive minimum and maximum fillable amounts for an order's base asset. It ensures bounds are properly ordered and share consistent currencies.

### Currency Consistency

**Policy**: Minimum and maximum bounds **MUST share the same currency**.

**Validation**:
- Enforced in `OrderBounds::from()`
- Throws `InvalidInput` with message: "Bounds must share the same currency."

**Valid Examples**:
```php
✅ OrderBounds::from(
    Money::fromString('USD', '100.00', 2),
    Money::fromString('USD', '500.00', 2)
);

✅ OrderBounds::from(
    Money::fromString('BTC', '0.01', 8),
    Money::fromString('BTC', '10.0', 8)
);
```

**Invalid Examples**:
```php
❌ OrderBounds::from(
    Money::fromString('USD', '100', 2),
    Money::fromString('EUR', '500', 2)  // Different currency
);
```

**Rationale**: Order bounds describe a range of fillable amounts in a single asset. Mixing currencies is nonsensical.

### Bounds Ordering

**Policy**: Minimum amount **MUST NOT exceed** maximum amount (min <= max).

**Validation**:
- Enforced in `OrderBounds::from()`
- Uses `Money::greaterThan()` comparison
- Throws `InvalidInput` with message: "Minimum amount cannot exceed the maximum amount."

**Valid Examples**:
```php
✅ OrderBounds::from(
    Money::fromString('USD', '100.00', 2),
    Money::fromString('USD', '500.00', 2)
);

✅ OrderBounds::from(
    Money::fromString('USD', '100.00', 2),
    Money::fromString('USD', '100.00', 2)  // Equal is allowed
);
```

**Invalid Examples**:
```php
❌ OrderBounds::from(
    Money::fromString('USD', '500.00', 2),
    Money::fromString('USD', '100.00', 2)  // Min > Max
);
```

**Rationale**: Inverted bounds are logically inconsistent and likely indicate a bug.

### Scale Normalization

**Policy**: Internal representation uses `max(min.scale, max.scale)` for both bounds.

**Behavior**:
- Both bounds are normalized to the higher scale
- Ensures consistent comparison precision
- Preserves all precision from both operands

**Example**:
```php
$bounds = OrderBounds::from(
    Money::fromString('USD', '100', 2),    // scale 2
    Money::fromString('USD', '500.5', 1)   // scale 1
);

// Internally both normalized to scale 2:
// min = "100.00", max = "500.50"
```

### Contains Method

**Policy**: `contains()` checks if an amount falls within bounds **inclusively** (min <= amount <= max).

**Validation**:
- Amount must have same currency as bounds
- Amount is normalized to bounds' scale before comparison
- Throws `InvalidInput` if currency mismatch

**Example**:
```php
$bounds = OrderBounds::from(
    Money::fromString('USD', '100.00', 2),
    Money::fromString('USD', '500.00', 2)
);

$bounds->contains(Money::fromString('USD', '100.00', 2)); // ✅ true (at min)
$bounds->contains(Money::fromString('USD', '300.00', 2)); // ✅ true (within)
$bounds->contains(Money::fromString('USD', '500.00', 2)); // ✅ true (at max)
$bounds->contains(Money::fromString('USD', '99.99', 2));  // ❌ false (below min)
$bounds->contains(Money::fromString('USD', '500.01', 2)); // ❌ false (above max)
```

### Clamp Method

**Policy**: `clamp()` adjusts an amount to fit within bounds:
- If amount < min, returns min
- If amount > max, returns max
- Otherwise, returns amount (normalized to bounds' scale)

**@invariant Annotations**:
```php
@invariant min.currency == max.currency
@invariant min <= max
@invariant contains(amount) checks min <= amount <= max (inclusive)
@invariant clamp(amount) returns value within [min, max]
@invariant internal scale = max(min.scale, max.scale)
```

---

## ToleranceWindow Invariants

**Class**: `SomeWork\P2PPathFinder\Domain\ValueObject\ToleranceWindow`

ToleranceWindow represents a relative tolerance range used for path-finding flexibility. It enforces strict bounds on tolerance values and derives heuristic tolerances deterministically.

### Tolerance Range

**Policy**: Both minimum and maximum tolerances **MUST be in the [0, 1) range** (0 inclusive, 1 exclusive).

**Validation**:
- Enforced in `normalizeToleranceDecimal()`
- Compares against `BigDecimal::zero()` (>= 0) and `BigDecimal::one()` (< 1)
- Throws `InvalidInput` with message: "{context} must be in the [0, 1) range."

**Valid Examples**:
```php
✅ ToleranceWindow::fromStrings('0', '0.1');          // 0% to 10%
✅ ToleranceWindow::fromStrings('0.01', '0.05');      // 1% to 5%
✅ ToleranceWindow::fromStrings('0.5', '0.9');        // 50% to 90%
✅ ToleranceWindow::fromStrings('0.999999', '0.999999'); // Very high, but < 1
```

**Invalid Examples**:
```php
❌ ToleranceWindow::fromStrings('-0.1', '0.5');   // Negative minimum
❌ ToleranceWindow::fromStrings('0', '1.0');      // Maximum >= 1.0
❌ ToleranceWindow::fromStrings('0', '1.5');      // Maximum > 1.0
```

**Rationale**: Tolerances represent relative fractions:
- 0 means "no tolerance" (exact match required)
- 0.1 means "10% tolerance"
- 1.0 would mean "100% tolerance" which is unbounded (nonsensical)
- Negative tolerances have no mathematical meaning

### Bounds Ordering

**Policy**: Minimum tolerance **MUST NOT exceed** maximum tolerance (min <= max).

**Validation**:
- Enforced in `ToleranceWindow::fromStrings()`
- Throws `InvalidInput` with message: "Minimum tolerance must be less than or equal to maximum tolerance."

**Valid Examples**:
```php
✅ ToleranceWindow::fromStrings('0', '0.1');     // min < max
✅ ToleranceWindow::fromStrings('0.05', '0.05'); // min == max (exact tolerance)
```

**Invalid Examples**:
```php
❌ ToleranceWindow::fromStrings('0.1', '0.05');  // min > max
```

### Heuristic Tolerance Derivation

**Policy**: When min == max, heuristic uses min; otherwise, heuristic uses max.

**Behavior**:
- Equal tolerances: `heuristicTolerance = minimum`, `heuristicSource = 'minimum'`
- Different tolerances: `heuristicTolerance = maximum`, `heuristicSource = 'maximum'`
- Deterministic and predictable

**Example**:
```php
$narrow = ToleranceWindow::fromStrings('0.01', '0.01');
$narrow->heuristicTolerance(); // "0.01"
$narrow->heuristicSource();    // "minimum"

$wide = ToleranceWindow::fromStrings('0.01', '0.1');
$wide->heuristicTolerance();   // "0.1"
$wide->heuristicSource();      // "maximum"
```

**Rationale**: Using the maximum tolerance for heuristics provides more flexibility in pathfinding while respecting the bounds.

### Canonical Scale

**Policy**: All tolerances are normalized to 18 decimal places (CANONICAL_SCALE).

**Behavior**:
- Defined in `DecimalHelperTrait::CANONICAL_SCALE = 18`
- All internal storage uses this scale
- Ensures consistent precision across all tolerance operations

**@invariant Annotations**:
```php
@invariant 0 <= minimum < 1
@invariant 0 <= maximum < 1
@invariant minimum <= maximum
@invariant scale = 18 (CANONICAL_SCALE)
@invariant heuristicTolerance = (min == max) ? min : max
@invariant heuristicSource = (min == max) ? 'minimum' : 'maximum'
```

---

## AssetPair Invariants

**Class**: `SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair`

AssetPair represents a directed trading pair (base -> quote). It ensures both currencies are valid and distinct.

### Distinct Asset Requirement

**Policy**: Base and quote assets **MUST be distinct**.

**Validation**:
- Enforced in `AssetPair::fromString()`
- Both currencies normalized via `Money::fromString()` validation
- Throws `InvalidInput` with message: "Asset pair requires distinct assets."

**Valid Examples**:
```php
✅ AssetPair::fromString('USD', 'EUR');
✅ AssetPair::fromString('BTC', 'USDT');
```

**Invalid Examples**:
```php
❌ AssetPair::fromString('USD', 'USD');  // Same asset
❌ AssetPair::fromString('btc', 'BTC');  // Same (normalized)
```

**Rationale**: Trading pairs describe exchanges between different assets. Same-asset pairs are nonsensical.

### Currency Format

**Policy**: Both base and quote must follow Money's currency format (3-12 uppercase alphabetic characters).

**Validation**: Delegates to `Money::fromString()` via `assertCurrency()`.

**@invariant Annotations**:
```php
@invariant base != quote (after normalization)
@invariant base matches /^[A-Z]{3,12}$/
@invariant quote matches /^[A-Z]{3,12}$/
```

---

## FeeBreakdown Invariants

**Class**: `SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown`

FeeBreakdown is a simple immutable value object representing optional base and quote fee components. It has minimal invariants as it primarily serves as a data container.

### Optional Fees

**Policy**: Both baseFee and quoteFee are optional (nullable).

**Behavior**:
- `null` represents "no fee for this component"
- Treated equivalently to zero fee in `hasBaseFee()` / `hasQuoteFee()`
- Both null = no fees at all

### Named Constructors

**Static Factories**:
- `none()`: Creates instance with null base and quote fees
- `forBase(Money)`: Creates instance with only base fee
- `forQuote(Money)`: Creates instance with only quote fee
- `of(?Money, ?Money)`: Creates instance with both components (returns `none()` if both null)

### Fee Merging

**Policy**: `merge()` combines two FeeBreakdown instances by summing like-currency fees.

**Behavior**:
- If both have baseFee in same currency, they are added
- If both have quoteFee in same currency, they are added
- If only one has a fee component, that component is used
- Currency mismatches will throw when `Money::add()` validates

**@invariant Annotations**:
```php
@invariant baseFee is null OR baseFee is Money
@invariant quoteFee is null OR quoteFee is Money
@invariant isZero() ≡ (baseFee == null || baseFee->isZero()) && (quoteFee == null || quoteFee->isZero())
@invariant merge() sums fees component-wise
```

---

## Order Invariants (Entity)

**Class**: `SomeWork\P2PPathFinder\Domain\Order\Order`

Order is a domain entity representing a tradeable order in the pathfinding system. It enforces strict consistency between all its components at construction time.

### Currency Consistency

**Policy**: All currency-denominated components must align with the AssetPair.

**Validation** (enforced in `assertConsistency()`):

1. **Bounds Currency**: Order bounds must be in base asset currency
   - Throws: "Order bounds must be expressed in the base asset."

2. **Effective Rate Base**: Rate's base currency must match asset pair's base
   - Throws: "Effective rate base currency must match asset pair base."

3. **Effective Rate Quote**: Rate's quote currency must match asset pair's quote
   - Throws: "Effective rate quote currency must match asset pair quote."

4. **Fee Currency Validation** (enforced during fee calculations):
   - Base fees must be in base asset currency
   - Quote fees must be in quote asset currency
   - Throws: "Fee policy must return money in {base|quote} asset currency."

**Example Valid Order**:
```php
✅ new Order(
    OrderSide::Buy,
    AssetPair::fromString('BTC', 'USD'),
    OrderBounds::from(
        Money::fromString('BTC', '0.1', 8),  // Base currency
        Money::fromString('BTC', '1.0', 8)   // Base currency
    ),
    ExchangeRate::fromString('BTC', 'USD', '50000', 8), // Matches pair
    $feePolicy
);
```

**Example Invalid Order**:
```php
❌ new Order(
    OrderSide::Buy,
    AssetPair::fromString('BTC', 'USD'),
    OrderBounds::from(
        Money::fromString('USD', '1000', 2),  // Wrong! Bounds in quote currency
        Money::fromString('USD', '5000', 2)
    ),
    ExchangeRate::fromString('BTC', 'USD', '50000', 8),
    null
);
// Throws: "Order bounds must be expressed in the base asset."
```

### Fee Policy Behavior

**Policy**: FeePolicy is optional but when present, must return fees in appropriate currencies.

**Behavior**:
- `null` FeePolicy means no fees
- Base fees affect gross base spend: `grossBase = baseAmount + baseFee`
- Quote fees affect effective quote: `effectiveQuote = quoteAmount - quoteFee`
- Fees are validated by `assertBaseFeeCurrency()` / `assertQuoteFeeCurrency()`

**Important**: Order class does NOT prevent excessive fees (e.g., fee > amount). This is the caller's responsibility to validate sensibility. The domain model allows mathematically valid but economically nonsensical fee scenarios.

**Example**:
```php
// This is allowed (but caller should validate if it makes sense):
$order->calculateEffectiveQuoteAmount(
    Money::fromString('BTC', '1.0', 8)
); 
// If fee is 100% of quote, effective quote = 0 (allowed by Order)

// This is also allowed:
$order->calculateGrossBaseSpend(
    Money::fromString('BTC', '1.0', 8)
);
// If base fee is 200% of base, gross base = 300% of base (allowed by Order)
```

### Partial Fill Validation

**Policy**: `validatePartialFill()` ensures fill amount is within bounds and in base currency.

**Validation**:
- Amount must be in base currency
- Amount must satisfy `bounds.contains(amount)` (min <= amount <= max)
- Throws `InvalidInput` if validation fails

**@invariant Annotations**:
```php
@invariant bounds.currency == assetPair.base
@invariant effectiveRate.baseCurrency == assetPair.base
@invariant effectiveRate.quoteCurrency == assetPair.quote
@invariant baseFee (if present) in base currency
@invariant quoteFee (if present) in quote currency
@invariant validatePartialFill ensures bounds.contains(amount)
@invariant calculateQuoteAmount = effectiveRate.convert(baseAmount)
@invariant calculateEffectiveQuoteAmount = quoteAmount - quoteFee (if present)
@invariant calculateGrossBaseSpend = baseAmount + baseFee (if present)
```

---

## PathSearchConfig Invariants

**Class**: `SomeWork\P2PPathFinder\Application\Config\PathSearchConfig`

PathSearchConfig is the main configuration object for path search operations. It validates search parameters and derives spend bounds from tolerance windows.

### Hop Constraints

**Policy**: 
- Minimum hops must be at least 1
- Maximum hops must be >= minimum hops

**Validation**:
- `minimumHops < 1`: Throws "Minimum hops must be at least one."
- `maximumHops < minimumHops`: Throws "Maximum hops must be greater than or equal to minimum hops."

**Valid Examples**:
```php
✅ minimumHops: 1, maximumHops: 3
✅ minimumHops: 2, maximumHops: 2  // Equal is allowed
✅ minimumHops: 1, maximumHops: 10
```

**Invalid Examples**:
```php
❌ minimumHops: 0, maximumHops: 3   // Min too low
❌ minimumHops: 3, maximumHops: 2   // Max < Min
```

**Rationale**: Paths require at least one hop (a single exchange). Maximum must be reachable from minimum.

### Result Limit

**Policy**: Result limit must be at least 1.

**Validation**: Throws "Result limit must be at least one."

**Rationale**: Requesting zero results is nonsensical.

### Spend Bounds Computation

**Policy**: Spend bounds are computed from tolerance window using multiplication:
- `minSpend = spendAmount × (1 - minimumTolerance)`
- `maxSpend = spendAmount × (1 + maximumTolerance)`

**Behavior**:
- Uses higher precision during computation: `max(spendAmount.scale, BOUND_SCALE)`
- Result normalized back to `spendAmount.scale`
- Bounds must satisfy `minSpend <= spendAmount <= maxSpend`
- Zero tolerance produces equal min/max (both = spendAmount)

**Example**:
```php
$config = new PathSearchConfig(
    Money::fromString('USD', '1000.00', 2),  // Spend $1000
    ToleranceWindow::fromStrings('0.1', '0.2'), // 10% below, 20% above
    1, 3
);

// minSpend = 1000 × (1 - 0.1) = 1000 × 0.9 = 900.00
// maxSpend = 1000 × (1 + 0.2) = 1000 × 1.2 = 1200.00
```

**Rationale**: Tolerances define acceptable deviation from target spend. Multiplication is more intuitive than addition for percentage-based tolerances.

### PathFinder Tolerance Resolution

**Policy**: PathFinder tolerance can be explicitly overridden or derived from tolerance window.

**Resolution Logic**:
- If `pathFinderToleranceOverride` provided: validates and uses it, source = 'override'
- Otherwise: uses `toleranceWindow.heuristicTolerance()`, source from window

**@invariant Annotations**:
```php
@invariant minimumHops >= 1
@invariant maximumHops >= minimumHops
@invariant resultLimit >= 1
@invariant minSpend = spendAmount × (1 - toleranceWindow.minimum)
@invariant maxSpend = spendAmount × (1 + toleranceWindow.maximum)
@invariant minSpend <= spendAmount <= maxSpend
@invariant pathFinderTolerance = override OR toleranceWindow.heuristicTolerance
```

---

## Summary of Key Findings from Subtasks 0002.1-0002.12

### Money (0002.1, 0002.3, 0002.4)
- ✅ Non-negative amounts enforced (zero allowed)
- ✅ Scale range: 0-50
- ✅ Currency format: 3-12 uppercase letters
- ✅ Extreme values tested up to scale 50
- ✅ Scale mismatches resolved via `max(scale1, scale2)`
- ✅ HALF_UP rounding mode for all operations

### ExchangeRate (0002.2, 0002.6)
- ✅ Positive rates only (> 0)
- ✅ Distinct currency requirement
- ✅ Inversion formula: `1 / rate` with +1 precision during division
- ✅ Double inversion approximately recovers original (within rounding)

### OrderBounds (0002.5, 0002.7, 0002.8)
- ✅ Currency consistency enforced
- ✅ Bounds ordering validated (min <= max)
- ✅ Scale normalized to max(min.scale, max.scale)
- ✅ Boundary value tests confirm inclusive bounds
- ✅ Contains checks boundaries correctly (min and max inclusive)

### ToleranceWindow (0002.9, 0002.10)
- ✅ Range constraint: [0, 1) strictly enforced
- ✅ Bounds ordering validated (min <= max)
- ✅ Canonical scale: 18 decimals
- ✅ Heuristic derivation: max (unless min == max)
- ✅ Spend bounds formula: min = spend × (1 - minTol), max = spend × (1 + maxTol)

### Order (0002.11, 0002.12)
- ✅ All currency consistency validated at construction
- ✅ Bounds must be in base currency
- ✅ Effective rate currencies must match asset pair
- ✅ Fee currencies validated during computation
- ✅ Excessive fees allowed (caller must validate sensibility)
- ✅ Zero/null fees treated equivalently

---

## Change Policy

**Stability**: These invariants are part of the public API contract and will follow semantic versioning:
- Breaking changes to invariants (e.g., allowing negatives, changing formulas) require major version bump
- Relaxing constraints (e.g., increasing MAX_SCALE from 50 to 100) may be done in minor versions
- Tightening constraints (e.g., reducing MAX_SCALE from 50 to 30) is considered breaking and requires major version bump

**Version**: These invariants are effective as of version 1.0.0-alpha

---

## See Also

- [API Stability Guide](api-stability.md) - Documents the public API surface
- [API Contracts (JSON)](api-contracts.md) - JSON serialization formats
- [Decimal Strategy](decimal-strategy.md) - Detailed decimal arithmetic rules
- [Property Test Scenarios](property-test-scenarios.md) - Property-based test coverage

