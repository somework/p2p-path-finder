# Domain Model Invariants

This document defines the invariants enforced by value objects and domain entities. All invariants are validated at construction time and throw `InvalidInput` exceptions for violations.

## Table of Contents

- [Overview](#overview)
- [Money Invariants](#money-invariants)
- [ExchangeRate Invariants](#exchangerate-invariants)
- [OrderBounds Invariants](#orderbounds-invariants)
- [Order Invariants](#order-invariants)
- [AssetPair Invariants](#assetpair-invariants)
- [ToleranceWindow Invariants](#tolerancewindow-invariants)
- [Quick Reference](#quick-reference)

---

## Overview

Value objects enforce invariants through:
- **Immutability** - All properties are `readonly`
- **Constructor validation** - Invalid states rejected with `InvalidInput` exceptions
- **Factory methods** - Named constructors ensure validation
- **Type safety** - Strong typing prevents type errors

**Validation approach**: Fail fast with clear error messages.

---

## Money Invariants

**Class**: `SomeWork\P2PPathFinder\Domain\Money\Money`

| Invariant    | Rule                       | Exception Message                                           |
|--------------|----------------------------|-------------------------------------------------------------|
| **Amount**   | Must be non-negative (≥ 0) | "Money amount cannot be negative. Got: {currency} {amount}" |
| **Currency** | 3-12 uppercase letters     | "Currency code must be 3-12 uppercase letters. Got: {code}" |
| **Scale**    | 0-50 decimal places        | "Scale must be between 0 and 50. Got: {scale}"              |

### Examples

**Valid**:
```php
Money::fromString('USD', '100.00', 2);      // ✅ Standard fiat
Money::fromString('BTC', '0.12345678', 8);  // ✅ Crypto  
Money::fromString('EUR', '0.00', 2);        // ✅ Zero allowed
Money::fromString('CUSTOM', '50.5', 1);     // ✅ Custom currency
```

**Invalid**:
```php
Money::fromString('USD', '-10.00', 2);      // ❌ Negative amount
Money::fromString('US', '100.00', 2);       // ❌ Currency too short
Money::fromString('USD', '100.00', 100);    // ❌ Scale too high
Money::fromString('$$$', '100.00', 2);      // ❌ Invalid characters
```

### Arithmetic Invariants

All operations preserve non-negativity:
- `add(Money)` - Result ≥ 0 (sum of non-negatives)
- `subtract(Money)` - Caller must ensure result ≥ 0
- `multiply(string)` - Multiplier must be ≥ 0
- `divide(string)` - Divisor must be > 0

---

## ExchangeRate Invariants

**Class**: `SomeWork\P2PPathFinder\Domain\Money\ExchangeRate`

| Invariant           | Rule                            | Exception Message                             |
|---------------------|---------------------------------|-----------------------------------------------|
| **Rate**            | Must be positive (> 0)          | "Exchange rate must be positive. Got: {rate}" |
| **Currencies**      | Base ≠ quote (case-insensitive) | "Exchange rate requires distinct currencies"  |
| **Currency format** | 3-12 uppercase letters each     | Same as Money                                 |
| **Scale**           | 0-50 decimal places             | Same as Money                                 |

### Examples

**Valid**:
```php
ExchangeRate::fromString('USD', 'EUR', '0.92', 4);      // ✅ Fiat pair
ExchangeRate::fromString('BTC', 'USD', '30000', 2);     // ✅ Crypto/fiat
ExchangeRate::fromString('ETH', 'BTC', '0.065', 6);     // ✅ Crypto pair
```

**Invalid**:
```php
ExchangeRate::fromString('USD', 'USD', '1.0', 2);       // ❌ Same currency
ExchangeRate::fromString('USD', 'EUR', '0.0', 2);       // ❌ Zero rate
ExchangeRate::fromString('USD', 'EUR', '-0.92', 4);     // ❌ Negative rate
ExchangeRate::fromString('usd', 'eur', '0.92', 100);    // ❌ Scale too high
```

### Operations

- `convert(Money)` - Converts amount from base to quote currency
- `invert()` - Returns reciprocal rate (quote → base)

---

## OrderBounds Invariants

**Class**: `SomeWork\P2PPathFinder\Domain\Order\OrderBounds`

| Invariant        | Rule                                  | Exception Message                                 |
|------------------|---------------------------------------|---------------------------------------------------|
| **Min/Max**      | Min ≤ Max                             | "Minimum amount cannot exceed the maximum amount" |
| **Currency**     | Both Money objects have same currency | "OrderBounds currencies must match"               |
| **Non-negative** | Both amounts ≥ 0                      | Enforced by Money invariants                      |

### Examples

**Valid**:
```php
$min = Money::fromString('USD', '10.00', 2);
$max = Money::fromString('USD', '1000.00', 2);
OrderBounds::from($min, $max);                          // ✅ Min < Max

$equal = Money::fromString('BTC', '0.5', 8);
OrderBounds::from($equal, $equal);                      // ✅ Min = Max allowed
```

**Invalid**:
```php
$min = Money::fromString('USD', '1000.00', 2);
$max = Money::fromString('USD', '10.00', 2);
OrderBounds::from($min, $max);                          // ❌ Min > Max

$minUsd = Money::fromString('USD', '10.00', 2);
$maxEur = Money::fromString('EUR', '10.00', 2);
OrderBounds::from($minUsd, $maxEur);                    // ❌ Currency mismatch
```

### Factory Method

```php
// From strings
OrderBounds::fromStrings('10.00', '1000.00', 2);        // ✅ Convenience method
```

---

## Order Invariants

**Class**: `SomeWork\P2PPathFinder\Domain\Order\Order`

| Invariant      | Rule                                        | Exception Message       |
|----------------|---------------------------------------------|-------------------------|
| **Side**       | Must be `BUY` or `SELL` enum value          | Type error              |
| **Asset Pair** | Valid AssetPair (base ≠ quote)              | AssetPair validation    |
| **Bounds**     | Valid OrderBounds                           | OrderBounds validation  |
| **Rate**       | Valid ExchangeRate with matching currencies | ExchangeRate validation |
| **Fee Policy** | Null or implements FeePolicy                | Type hint               |

### Examples

**Valid**:
```php
$order = new Order(
    OrderSide::BUY,
    AssetPair::fromString('BTC', 'USD'),
    OrderBounds::from(
        Money::fromString('BTC', '0.01', 8),
        Money::fromString('BTC', '1.0', 8)
    ),
    ExchangeRate::fromString('BTC', 'USD', '30000', 2),
    null  // No fees
);  // ✅ Valid buy order
```

**Invalid**:
```php
// Mismatched currencies between bounds and rate
$order = new Order(
    OrderSide::SELL,
    AssetPair::fromString('ETH', 'USD'),  // ❌ Pair says ETH/USD
    OrderBounds::from(
        Money::fromString('BTC', '0.01', 8),  // ❌ Bounds use BTC
        Money::fromString('BTC', '1.0', 8)
    ),
    ExchangeRate::fromString('ETH', 'USD', '2000', 2),
    null
);  // ❌ Inconsistent currencies
```

### Named Constructors

```php
// Simplified constructors
Order::buy($assetPair, $bounds, $rate, $side, $feePolicy = null);
Order::sell($assetPair, $bounds, $rate, $side, $feePolicy = null);
```

---

## AssetPair Invariants

**Class**: `SomeWork\P2PPathFinder\Domain\Money\AssetPair`

| Invariant    | Rule                            | Exception Message                               |
|--------------|---------------------------------|-------------------------------------------------|
| **Base**     | 3-12 uppercase letters          | "Base currency must be 3-12 uppercase letters"  |
| **Quote**    | 3-12 uppercase letters          | "Quote currency must be 3-12 uppercase letters" |
| **Distinct** | Base ≠ quote (case-insensitive) | "Asset pair requires distinct currencies"       |

### Examples

**Valid**:
```php
AssetPair::fromString('BTC', 'USD');     // ✅ Crypto/fiat
AssetPair::fromString('EUR', 'USD');     // ✅ Fiat/fiat
AssetPair::fromString('eth', 'btc');     // ✅ Normalized to uppercase
```

**Invalid**:
```php
AssetPair::fromString('BTC', 'BTC');     // ❌ Same currency
AssetPair::fromString('BT', 'USD');      // ❌ Base too short
AssetPair::fromString('BTC', 'US');      // ❌ Quote too short
```

---

## ToleranceWindow Invariants

**Class**: `SomeWork\P2PPathFinder\Domain\Tolerance\ToleranceWindow`

| Invariant    | Rule        | Exception Message                                   |
|--------------|-------------|-----------------------------------------------------|
| **Min**      | 0 ≤ min < 1 | "Minimum tolerance must be between 0 and 1"         |
| **Max**      | 0 ≤ max < 1 | "Maximum tolerance must be between 0 and 1"         |
| **Ordering** | min ≤ max   | "Minimum tolerance cannot exceed maximum tolerance" |

### Examples

**Valid**:
```php
ToleranceWindow::from('0.00', '0.05', 18);  // ✅ 0-5% tolerance
ToleranceWindow::from('0.0', '0.1', 18);    // ✅ 0-10% tolerance
ToleranceWindow::from('0.05', '0.05', 18);  // ✅ Min = Max allowed
```

**Invalid**:
```php
ToleranceWindow::from('0.10', '0.05', 18);  // ❌ Min > Max
ToleranceWindow::from('-0.01', '0.05', 18); // ❌ Negative min
ToleranceWindow::from('0.0', '1.0', 18);    // ❌ Max = 1 not allowed
ToleranceWindow::from('0.0', '1.5', 18);    // ❌ Max > 1
```

---

## Quick Reference

### Common Validation Errors

| Error Pattern                    | Cause                 | Fix                            |
|----------------------------------|-----------------------|--------------------------------|
| "negative" in message            | Negative amount       | Use positive values only       |
| "must be between 0 and 1"        | Invalid tolerance     | Use range [0, 1)               |
| "distinct currencies"            | Same base/quote       | Use different currencies       |
| "cannot exceed"                  | Min > Max             | Ensure min ≤ max               |
| "3-12 uppercase letters"         | Invalid currency code | Use ISO codes (USD, BTC, etc.) |
| "Scale must be between 0 and 50" | Invalid scale         | Use scale 0-50                 |

### Scale Guidelines

| Currency Type      | Recommended Scale | Example                     |
|--------------------|-------------------|-----------------------------|
| **Fiat**           | 2                 | USD, EUR, GBP               |
| **Crypto (BTC)**   | 8                 | Bitcoin (satoshi precision) |
| **Crypto (ETH)**   | 18                | Ethereum (wei precision)    |
| **Stablecoins**    | 2-8               | USDT, USDC                  |
| **Tokens**         | 0-18              | Varies by token             |
| **Exchange rates** | 4-8               | Depends on precision needs  |

### Currency Code Examples

**Valid formats**:
- **ISO 4217**: USD, EUR, GBP, JPY (3 letters)
- **Crypto**: BTC, ETH, XRP, LTC (3-4 letters)  
- **Stablecoins**: USDT, USDC, DAI (4 letters)
- **Custom**: MYTOKEN (7 letters, max 12)

**Invalid formats**:
- Symbols: $, €, £
- Numbers: USD123, BTC1
- Special chars: US$, EUR/USD
- Too short: US, EU (< 3)
- Too long: VERYLONGCURRENCY (> 12)

### Tolerance Range

Tolerance represents acceptable deviation as a ratio in range [0, 1):

| Tolerance | Percentage | Interpretation             |
|-----------|------------|----------------------------|
| 0.00      | 0%         | Exact match only           |
| 0.05      | 5%         | Up to 5% worse acceptable  |
| 0.10      | 10%        | Up to 10% worse acceptable |
| 0.20      | 20%        | Up to 20% worse acceptable |
| 0.99      | 99%        | Almost any path accepted   |

**Note**: Tolerance of 1.0 (100%) is not allowed - use 0.99 as maximum.

---

## Validation Strategy

### Prevention (Recommended)

Validate before calling library:

```php
// ✅ Good: Validate first
function createMoney(string $currency, string $amount, int $scale): ?Money
{
    if ($amount < 0) {
        return null; // or throw domain exception
    }
    
    if (!preg_match('/^[A-Z]{3,12}$/i', $currency)) {
        return null;
    }
    
    if ($scale < 0 || $scale > 50) {
        return null;
    }
    
    return Money::fromString($currency, $amount, $scale);
}
```

### Exception Handling (Fallback)

Catch library exceptions:

```php
// ⚠️ Acceptable: Handle exceptions
try {
    $money = Money::fromString($currency, $amount, $scale);
} catch (InvalidInput $e) {
    // Log and return error to user
    error_log("Invalid money: " . $e->getMessage());
    return ['error' => 'Invalid amount or currency'];
}
```

---

## Related Documentation

- [Exception Handling](exceptions.md) - Error handling patterns
- [Getting Started](getting-started.md) - Basic usage
- [API Stability](api-stability.md) - Public API guarantees
- [Decimal Strategy](decimal-strategy.md) - Precision arithmetic

---

*All invariants are enforced at construction time. Invalid objects cannot exist.*
