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

## Change Policy

**Stability**: These invariants are part of the public API contract and will follow semantic versioning:
- Breaking changes to invariants (e.g., allowing negatives) require major version bump
- Relaxing constraints (e.g., increasing MAX_SCALE) may be done in minor versions
- Tightening constraints is considered breaking and requires major version bump

**Version**: These invariants are effective as of version 1.0.0-alpha

---

## See Also

- [API Stability Guide](api-stability.md) - Documents the public API surface
- [API Contracts (JSON)](api-contracts.md) - JSON serialization formats
- [Decimal Strategy](decimal-strategy.md) - Detailed decimal arithmetic rules

