# Custom PHPStan Rules for Decimal Arithmetic

This document describes the custom PHPStan rules implemented to enforce decimal arithmetic consistency and prevent precision errors in the p2p-path-finder library.

## Overview

The library uses three custom PHPStan rules to automatically detect common decimal arithmetic anti-patterns:

1. **FloatLiteralInArithmeticRule** - Detects float literals in arithmetic operations
2. **MissingRoundingModeRule** - Detects missing RoundingMode parameters in `toScale()` calls
3. **BCMathFunctionCallRule** - Detects BCMath function calls in production code

## Rule 1: FloatLiteralInArithmeticRule

### Purpose

Prevents the use of float literals (e.g., `10.5`, `20.3`) in arithmetic operations to avoid precision loss with monetary values.

### What It Catches

- Binary arithmetic operations (`+`, `-`, `*`, `/`, `%`) with float literals
- Method calls on `Money` or `ExchangeRate` with float arguments
- Any arithmetic expression involving DNumber (float) nodes

### Examples

#### ❌ Violations

```php
// Float literal in arithmetic
$result = $amount * 1.5;  // ERROR: Float literal 1.5

// Float literal passed to Money method
$money = Money::fromString('USD', '100.00', 2);
$doubled = $money->multiply(2.0);  // ERROR: Float literal 2.0

// Float literal in division
$average = $total / 3.5;  // ERROR: Float literal 3.5
```

#### ✅ Correct Usage

```php
// Use string literals
$result = $money->multiply('1.5');  // Correct

// Use BigDecimal
$multiplier = BigDecimal::of('1.5');
$result = $money->multiply($multiplier->__toString());  // Correct

// String literals in constructor
$money = Money::fromString('USD', '150.00', 2);  // Correct
```

### Allowed Contexts

The rule **allows** float literals in time-related calculations where precision loss is acceptable:

```php
// ✅ Allowed: Time conversion
$elapsedMilliseconds = ($now - $startedAt) * 1000.0;

// ✅ Allowed: Guard comparison for time values
if ($elapsedMilliseconds < 0.0) {
    $elapsedMilliseconds = 0.0;
}
```

The rule detects time-related contexts by:
- Function/class name contains: `elapsed`, `milliseconds`, `seconds`, `started`, `budget`, `timeout`, `duration`
- Variable name contains time-related patterns
- Float literal is `1000.0` (ms conversion) or `0.0` (guard comparisons)

### Error Message Format

```
Float literal 1.5 used in arithmetic operation. Use BigDecimal or numeric-string instead.
💡 Convert to string: '1.5'
```

### Bypassing the Rule

For exceptional cases, use PHPStan ignore comments:

```php
// @phpstan-ignore p2pPathFinder.floatLiteral
$result = $value * 1.5;  // Intentionally using float for specific reason
```

## Rule 2: MissingRoundingModeRule

### Purpose

Ensures all `BigDecimal::toScale()` calls include an explicit `RoundingMode` parameter for deterministic behavior.

### What It Catches

- Calls to `BigDecimal::toScale()` with only one argument (scale)
- Missing explicit rounding mode specification

### Why It Matters

Without an explicit rounding mode, the behavior is undefined or defaults to `UNNECESSARY`, which throws an exception if rounding is required. This can lead to non-deterministic results or runtime errors.

### Examples

#### ❌ Violations

```php
// Missing RoundingMode
$value = BigDecimal::of('10.123456');
$rounded = $value->toScale(2);  // ERROR: Missing RoundingMode
```

#### ✅ Correct Usage

```php
use Brick\Math\RoundingMode;

// Explicit RoundingMode
$value = BigDecimal::of('10.123456');
$rounded = $value->toScale(2, RoundingMode::HALF_UP);  // Correct

// Using HALF_UP (project standard)
$rounded = $value->toScale(8, RoundingMode::HALF_UP);  // Correct
```

### Project Standard

This library uses **RoundingMode::HALF_UP** as the standard rounding mode for consistency. Always use `HALF_UP` unless there's a specific requirement for a different mode.

### Error Message Format

```
Call to BigDecimal::toScale() must include explicit RoundingMode parameter for deterministic behavior.
💡 Add RoundingMode::HALF_UP as second parameter: ->toScale($scale, RoundingMode::HALF_UP)
```

### Bypassing the Rule

```php
// @phpstan-ignore p2pPathFinder.missingRoundingMode
$rounded = $value->toScale(2);  // Exceptional case
```

## Rule 3: BCMathFunctionCallRule

### Purpose

Prevents the use of BCMath functions in production code, enforcing the use of `BigDecimal` for consistency and type safety.

### What It Catches

Calls to any BCMath function:
- `bcadd`, `bcsub`, `bcmul`, `bcdiv`
- `bcmod`, `bcpow`, `bcsqrt`
- `bccomp`, `bcscale`, `bcpowmod`

### Why BigDecimal Over BCMath

1. **Type Safety**: `BigDecimal` is object-oriented with type hints
2. **API Consistency**: Fluent, chainable API
3. **Error Handling**: Exceptions instead of false returns
4. **Readability**: More expressive method names
5. **IDE Support**: Better autocomplete and refactoring

### Examples

#### ❌ Violations

```php
// BCMath functions
$sum = bcadd('10.5', '20.3', 2);  // ERROR: BCMath prohibited
$product = bcmul('5.0', '3.0', 2);  // ERROR: BCMath prohibited
$comparison = bccomp('10.5', '10.3', 2);  // ERROR: BCMath prohibited
```

#### ✅ Correct Usage

```php
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

// Use BigDecimal methods
$sum = BigDecimal::of('10.5')
    ->plus('20.3')
    ->toScale(2, RoundingMode::HALF_UP);

$product = BigDecimal::of('5.0')
    ->multipliedBy('3.0')
    ->toScale(2, RoundingMode::HALF_UP);

$comparison = BigDecimal::of('10.5')
    ->compareTo(BigDecimal::of('10.3'));
```

### BCMath to BigDecimal Migration Guide

| BCMath Function | BigDecimal Equivalent |
|----------------|----------------------|
| `bcadd($a, $b, $scale)` | `BigDecimal::of($a)->plus($b)->toScale($scale, RoundingMode::HALF_UP)` |
| `bcsub($a, $b, $scale)` | `BigDecimal::of($a)->minus($b)->toScale($scale, RoundingMode::HALF_UP)` |
| `bcmul($a, $b, $scale)` | `BigDecimal::of($a)->multipliedBy($b)->toScale($scale, RoundingMode::HALF_UP)` |
| `bcdiv($a, $b, $scale)` | `BigDecimal::of($a)->dividedBy($b, $scale, RoundingMode::HALF_UP)` |
| `bccomp($a, $b, $scale)` | `BigDecimal::of($a)->toScale($scale, RoundingMode::HALF_UP)->compareTo(BigDecimal::of($b)->toScale($scale, RoundingMode::HALF_UP))` |
| `bcpow($a, $b, $scale)` | `BigDecimal::of($a)->power($b)->toScale($scale, RoundingMode::HALF_UP)` |
| `bcsqrt($a, $scale)` | `BigDecimal::of($a)->sqrt($scale, RoundingMode::HALF_UP)` |
| `bcmod($a, $b)` | `BigDecimal::of($a)->remainder($b)` |

### Error Message Format

```
BCMath function bcadd() is prohibited. Use BigDecimal instead for consistency.
💡 Use BigDecimal::plus()
```

### Allowed Contexts

BCMath functions are **allowed** in:
- Test files (`/tests/` directory)
- Example files (`/examples/` directory)
- Files ending with `Test.php`

This permits BCMath usage in documentation examples and test fixtures.

### Bypassing the Rule

```php
// @phpstan-ignore p2pPathFinder.bcmathUsage
$result = bcadd('10', '20', 2);  // Legacy code migration
```

## Running Custom Rules

The custom rules are automatically loaded when running PHPStan:

```bash
# Analyze the entire codebase
vendor/bin/phpstan analyse

# Analyze specific files
vendor/bin/phpstan analyse src/Domain/ValueObject/Money.php

# Run with verbose output
vendor/bin/phpstan analyse --no-progress --error-format=table
```

### Configuration

The rules are configured in `phpstan/phpstan-custom-rules.neon`:

```neon
services:
    -
        class: SomeWork\P2PPathFinder\PHPStan\Rules\FloatLiteralInArithmeticRule
        tags:
            - phpstan.rules.rule

    -
        class: SomeWork\P2PPathFinder\PHPStan\Rules\MissingRoundingModeRule
        tags:
            - phpstan.rules.rule

    -
        class: SomeWork\P2PPathFinder\PHPStan\Rules\BCMathFunctionCallRule
        tags:
            - phpstan.rules.rule
```

This configuration is included in the main `phpstan.neon.dist` file.

## Testing Custom Rules

To verify the custom rules are working correctly, create a test file with intentional violations:

```php
<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder;

use Brick\Math\BigDecimal;

final class RuleTest
{
    public function testRules(): void
    {
        // This should trigger all three rules
        $a = 10.5 + 20.3;  // Float literal
        $b = BigDecimal::of('10.5')->toScale(2);  // Missing RoundingMode
        $c = bcadd('10', '20', 2);  // BCMath
    }
}
```

Run PHPStan on this file:

```bash
vendor/bin/phpstan analyse src/RuleTest.php
```

Expected output:

```
 ------ ---------------------------------------------------------
  Line   RuleTest.php
 ------ ---------------------------------------------------------
  14     Float literal 10.5 used in arithmetic operation.
  14     Float literal 20.3 used in arithmetic operation.
  15     Call to BigDecimal::toScale() must include explicit
         RoundingMode parameter.
  16     BCMath function bcadd() is prohibited.
 ------ ---------------------------------------------------------
```

## Implementation Details

### File Locations

```
phpstan/
├── Rules/
│   ├── FloatLiteralInArithmeticRule.php
│   ├── MissingRoundingModeRule.php
│   └── BCMathFunctionCallRule.php
├── Tests/
│   └── RulesTestExamples.php
└── phpstan-custom-rules.neon
```

### Rule Interface

All rules implement the `PHPStan\Rules\Rule` interface:

```php
interface Rule
{
    public function getNodeType(): string;
    
    /**
     * @return array<int, \PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array;
}
```

### Error Identifiers

Each rule uses a unique identifier for errors:

- `p2pPathFinder.floatLiteral` - Float literal violations
- `p2pPathFinder.missingRoundingMode` - Missing RoundingMode violations
- `p2pPathFinder.bcmathUsage` - BCMath function violations

These identifiers can be used to ignore specific rule violations.

## Maintenance

### Adding New Rules

To add a new custom rule:

1. Create a new class in `phpstan/Rules/` implementing `Rule`
2. Add the rule to `phpstan/phpstan-custom-rules.neon`
3. Run `composer dump-autoload`
4. Test the rule with intentional violations
5. Document the rule in this file and `CONTRIBUTING.md`

### Modifying Existing Rules

When modifying rules:

1. Update the rule class
2. Test against the existing codebase
3. Check for new violations or false positives
4. Update documentation if behavior changes
5. Update `phpstan-baseline.neon` if necessary

### Performance Considerations

Custom rules are executed on every PHPStan run. To maintain performance:

- Keep rule logic simple and focused
- Avoid expensive operations in `processNode()`
- Use early returns to skip irrelevant nodes
- Cache expensive checks when possible

## Related Documentation

- [Decimal Strategy](decimal-strategy.md) - Overall decimal arithmetic approach
- [CONTRIBUTING.md](../CONTRIBUTING.md) - Contribution guidelines including decimal arithmetic rules

## References

- [PHPStan Custom Rules Documentation](https://phpstan.org/developing-extensions/rules)
- [Brick/Math Documentation](https://github.com/brick/math)
- [PHP-FIG Numeric Types Discussion](https://github.com/php-fig/fig-standards/discussions)

