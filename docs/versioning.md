# Versioning and Compatibility Policy

This document describes the versioning strategy, backward compatibility guarantees, and deprecation policies for the P2P Path Finder library.

## Table of Contents

- [Semantic Versioning](#semantic-versioning)
- [Backward Compatibility Policy](#backward-compatibility-policy)
- [Deprecation Policy](#deprecation-policy)
- [Release Schedule](#release-schedule)
- [Version Support](#version-support)
- [Examples](#examples)

---

## Semantic Versioning

The P2P Path Finder library follows [Semantic Versioning 2.0.0](https://semver.org/) (SemVer).

### Version Format

Versions are formatted as `MAJOR.MINOR.PATCH`:

```
1.2.3
│ │ │
│ │ └─ PATCH: Bug fixes and internal improvements (no API changes)
│ └─── MINOR: New features and functionality (backward compatible)
└───── MAJOR: Breaking changes (not backward compatible)
```

### Version Increments

#### MAJOR Version (1.x.x → 2.x.x)

**When**: Breaking changes that are not backward compatible.

**Examples**:
- Removing public API methods or classes
- Changing method signatures (parameters, return types)
- Changing behavior in ways that break existing code
- Removing or renaming public properties
- Changing exception types thrown by public methods
- Significant changes to JSON output structure

#### MINOR Version (1.2.x → 1.3.x)

**When**: New features added in a backward-compatible manner.

**Examples**:
- Adding new public methods or classes
- Adding optional parameters to existing methods
- Adding new properties to JSON output
- Adding new exception types (without removing old ones)
- New configuration options
- Performance improvements
- New convenience methods

#### PATCH Version (1.2.3 → 1.2.4)

**When**: Backward-compatible bug fixes and internal improvements.

**Examples**:
- Bug fixes that don't change API
- Internal refactoring
- Documentation improvements
- Test improvements
- Performance optimizations (without API changes)
- Dependency updates (within compatible ranges)

---

## Backward Compatibility Policy

### What Constitutes a BC Break

A **Backward Compatibility (BC) break** is any change that could cause existing code to fail or behave differently when upgrading within the same major version.

#### 🔴 BC Breaks (Require MAJOR version bump)

**1. Public API Changes**

```php
// ❌ BC BREAK: Removing a public method
class PathFinderService {
    // Method removed
    // public function findPath(...): ?PathResult
}

// ❌ BC BREAK: Changing method signature
class PathFinderService {
    // BEFORE: public function findBestPaths(PathSearchRequest $request): SearchOutcome
    // AFTER:  public function findBestPaths(PathSearchRequest $request, int $limit): SearchOutcome
    // Adding a required parameter is a BC break
}

// ❌ BC BREAK: Changing return type
class Money {
    // BEFORE: public function multiply(string $multiplier): self
    // AFTER:  public function multiply(string $multiplier): ?self
    // Changing non-nullable to nullable is a BC break
}

// ❌ BC BREAK: Removing a class
// Deleting any public class is a BC break
```

**2. Exception Changes**

```php
// ❌ BC BREAK: Throwing different exception types
class Money {
    public function __construct(/* ... */) {
        // BEFORE: throws InvalidInput
        // AFTER:  throws InvalidArgumentException
        // Changing exception type is a BC break
    }
}

// ❌ BC BREAK: Not throwing documented exceptions
class PathFinderService {
    /**
     * @throws GuardLimitExceeded
     */
    public function findBestPaths(/* ... */): SearchOutcome {
        // BEFORE: Could throw GuardLimitExceeded
        // AFTER:  Never throws (returns partial results instead)
        // This is a BC break (documented behavior changed)
    }
}
```

**3. Behavioral Changes**

```php
// ❌ BC BREAK: Changing calculation results
class PathFinder {
    // BEFORE: Returns paths sorted by cost ascending
    // AFTER:  Returns paths sorted by hops ascending
    // Changing deterministic behavior is a BC break
}

// ❌ BC BREAK: Changing default values
class PathSearchConfig {
    // BEFORE: Default hop limit is 3
    // AFTER:  Default hop limit is 5
    // Changing defaults that affect results is a BC break
}
```

**4. JSON Output Changes**

```php
// ❌ BC BREAK: Removing fields from JSON output
$money = Money::fromString('USD', '100.00', 2);
$json = $money->jsonSerialize();

// BEFORE: ['currency' => 'USD', 'amount' => '100.00', 'scale' => 2]
// AFTER:  ['currency' => 'USD', 'amount' => '100.00']
// Removing 'scale' field is a BC break
```

**5. Constructor Changes**

```php
// ❌ BC BREAK: Adding required constructor parameters
class OrderBounds {
    // BEFORE: public function __construct(Money $min, Money $max)
    // AFTER:  public function __construct(Money $min, Money $max, int $scale)
    // Adding required parameter is a BC break
}
```

#### ✅ NOT BC Breaks (Can be done in MINOR/PATCH versions)

**1. Adding New Features**

```php
// ✅ OK (MINOR): Adding new public methods
class PathFinderService {
    // New method - doesn't break existing code
    public function findAllPaths(PathSearchRequest $request): Generator
}

// ✅ OK (MINOR): Adding new classes
// New classes don't break existing code
class AdvancedPathFinder implements PathFinderInterface
```

**2. Adding Optional Parameters**

```php
// ✅ OK (MINOR): Adding optional parameters at the end
class PathSearchConfig {
    // BEFORE: public static function builder(): PathSearchConfigBuilder
    // AFTER:  public static function builder(?string $preset = null): PathSearchConfigBuilder
    // Optional parameter with default doesn't break existing calls
}
```

**3. Adding JSON Output Fields**

```php
// ✅ OK (MINOR): Adding new fields to JSON output
$money = Money::fromString('USD', '100.00', 2);
$json = $money->jsonSerialize();

// BEFORE: ['currency' => 'USD', 'amount' => '100.00', 'scale' => 2]
// AFTER:  ['currency' => 'USD', 'amount' => '100.00', 'scale' => 2, 'formatted' => '$100.00']
// Adding 'formatted' field is NOT a BC break
```

**4. Internal Changes**

```php
// ✅ OK (PATCH): Internal refactoring
class PathFinder {
    // Changed internal implementation
    // But public API and behavior remain the same
    // This is NOT a BC break
}

// ✅ OK (PATCH): Fixing bugs
class Money {
    // BEFORE: multiply('1.5') incorrectly rounded
    // AFTER:  multiply('1.5') correctly rounded
    // Bug fixes are NOT BC breaks (they restore intended behavior)
}
```

**5. Documentation Changes**

```php
// ✅ OK (PATCH): Improving documentation
// PHPDoc improvements, README updates, etc.
// NOT BC breaks
```

**6. Performance Improvements**

```php
// ✅ OK (MINOR/PATCH): Performance optimizations
// As long as behavior remains the same
// NOT BC breaks
```

**7. Throwing More Specific Exceptions**

```php
// ✅ OK (MINOR): Throwing more specific exception subclasses
class PathFinderService {
    /**
     * @throws InvalidInput
     */
    public function findBestPaths(/* ... */): SearchOutcome {
        // BEFORE: throws InvalidInput
        // AFTER:  throws InvalidCurrency extends InvalidInput
        // More specific exception is NOT a BC break if it's a subclass
    }
}
```

### Internal vs Public API

#### Public API (BC guarantees apply)

Classes, methods, and properties marked with `@api` tag or in these namespaces:
- `SomeWork\P2PPathFinder\Application\Service\*`
- `SomeWork\P2PPathFinder\Application\Config\*`
- `SomeWork\P2PPathFinder\Application\OrderBook\*`
- `SomeWork\P2PPathFinder\Domain\**` (all domain objects)
- `SomeWork\P2PPathFinder\Exception\**` (all exceptions)

#### Internal API (No BC guarantees)

Classes, methods, and properties marked with `@internal` tag or in:
- `SomeWork\P2PPathFinder\Application\Graph\*` (graph internals)
- `SomeWork\P2PPathFinder\Application\PathFinder\*` (algorithm internals)

Internal APIs may change in MINOR versions without notice.

### How BC Breaks Are Communicated

When a BC break is unavoidable, it is communicated through:

1. **Major Version Bump**: `1.x.x` → `2.0.0`
2. **CHANGELOG.md**: Detailed list of breaking changes with migration guidance
3. **UPGRADING.md**: Step-by-step migration guide for each major version
4. **Deprecation Period**: Where possible, deprecated in one major version, removed in next
5. **GitHub Release Notes**: Summary of breaking changes and migration steps

---

## Deprecation Policy

The library uses a formal deprecation process to give users time to migrate before features are removed.

### Deprecation Process

```
Version 1.5.0: Feature deprecated (with @deprecated tag and alternatives)
                ↓ (at least 1 minor version)
Version 1.6.0: Feature still works, deprecation notice remains
                ↓
Version 2.0.0: Feature removed (BC break, major version bump)
```

### Minimum Deprecation Period

- **Minimum**: 1 minor version (e.g., deprecated in 1.5.0, removed in 2.0.0)
- **Recommended**: 2-3 minor versions for major features
- **Exceptions**: Critical security issues may skip deprecation

### Deprecation Annotation Format

All deprecated features MUST be annotated with:

```php
/**
 * @deprecated since 1.5.0, use newMethod() instead. Will be removed in 2.0.0.
 */
public function oldMethod(): void
{
    trigger_error(
        'Method oldMethod() is deprecated since 1.5.0, use newMethod() instead.',
        E_USER_DEPRECATED
    );
    
    // Implementation continues to work
    $this->newMethod();
}
```

### Deprecation Examples

#### Example 1: Deprecating a Method

```php
/**
 * Find the best path between two currencies.
 * 
 * @deprecated since 1.5.0, use findBestPaths() instead. Will be removed in 2.0.0.
 */
public function findPath(OrderBook $book, Money $spend, string $target): ?PathResult
{
    trigger_error(
        'PathFinderService::findPath() is deprecated since 1.5.0, use findBestPaths() instead.',
        E_USER_DEPRECATED
    );
    
    $config = PathSearchConfig::builder()
        ->withSpendAmount($spend)
        ->withToleranceBounds('0.0', '0.0')
        ->withResultLimit(1)
        ->build();
    
    $request = new PathSearchRequest($book, $config, $target);
    $outcome = $this->findBestPaths($request);
    
    return $outcome->hasPaths() ? $outcome->paths()->first() : null;
}
```

**Changelog Entry**:
```markdown
## [1.5.0] - 2024-01-15

### Deprecated
- `PathFinderService::findPath()` - Use `findBestPaths()` instead. Will be removed in 2.0.0.
```

**Timeline**:
- **1.5.0**: Method deprecated, still works, shows E_USER_DEPRECATED notice
- **1.6.0, 1.7.0, ...**: Method continues to work
- **2.0.0**: Method removed

#### Example 2: Deprecating a Class

```php
/**
 * Legacy path finder implementation.
 * 
 * @deprecated since 1.6.0, use PathFinderService instead. Will be removed in 2.0.0.
 */
final class LegacyPathFinder
{
    public function __construct()
    {
        trigger_error(
            'LegacyPathFinder is deprecated since 1.6.0, use PathFinderService instead.',
            E_USER_DEPRECATED
        );
    }
    
    // Implementation continues to work...
}
```

#### Example 3: Deprecating a Configuration Option

```php
class PathSearchConfigBuilder
{
    /**
     * Set legacy search mode.
     * 
     * @deprecated since 1.4.0, legacy mode is no longer needed. Will be removed in 2.0.0.
     */
    public function withLegacyMode(bool $enabled): self
    {
        trigger_error(
            'PathSearchConfigBuilder::withLegacyMode() is deprecated since 1.4.0 and has no effect.',
            E_USER_DEPRECATED
        );
        
        // Option is ignored but doesn't break builds
        return $this;
    }
}
```

### Detecting Deprecated Usage

Users can detect deprecated features in their code:

**1. During Development**:
```bash
# PHPStan detects deprecated usage
vendor/bin/phpstan analyse
```

**2. During Testing**:
```php
// PHPUnit can be configured to fail on deprecations
<phpunit failOnDeprecation="true">
```

**3. In Production Logs**:
```php
// E_USER_DEPRECATED notices are logged
set_error_handler(function ($errno, $errstr) {
    if ($errno === E_USER_DEPRECATED) {
        error_log("Deprecation: $errstr");
    }
});
```

---

## Release Schedule

### Regular Releases

- **PATCH releases**: As needed (bug fixes, security fixes)
- **MINOR releases**: Every 2-4 months (new features)
- **MAJOR releases**: When needed (BC breaks)

### Pre-Release Versions

Major versions may have pre-release versions:

```
1.0.0-alpha.1  → Alpha (unstable, API may change)
1.0.0-beta.1   → Beta (feature complete, stabilizing API)
1.0.0-rc.1     → Release Candidate (production-ready, final testing)
1.0.0          → Stable Release
```

Pre-release versions do NOT follow BC guarantees.

---

## Version Support

See [docs/support.md](support.md) for detailed support policies including:
- PHP version support
- Bug fix support timelines
- Security fix support timelines
- Dependency update policies

---

## Examples

### Upgrade Scenarios

#### Scenario 1: Patch Upgrade (Safe)

```
Current: 1.2.3
Upgrade: 1.2.4

Changes: Bug fixes only
Action:  composer update somework/p2p-path-finder
Risk:    ✅ None - Safe upgrade
Testing: Run existing tests
```

#### Scenario 2: Minor Upgrade (Safe)

```
Current: 1.2.4
Upgrade: 1.3.0

Changes: New features, deprecations
Action:  composer update somework/p2p-path-finder
Risk:    ✅ Low - Backward compatible
Testing: Run existing tests, check for deprecation notices
```

#### Scenario 3: Major Upgrade (Breaking Changes)

```
Current: 1.9.0
Upgrade: 2.0.0

Changes: BC breaks, removals of deprecated features
Action:  
  1. Review CHANGELOG.md for breaking changes
  2. Review UPGRADING.md for migration steps
  3. Update code to use new APIs
  4. Update composer.json: "somework/p2p-path-finder": "^2.0"
  5. Run composer update
  6. Run comprehensive tests
Risk:    ⚠️  High - May require code changes
Testing: Full test suite + manual testing
```

### Version Constraint Recommendations

**In `composer.json`**:

```json
{
    "require": {
        "somework/p2p-path-finder": "^1.0"
    }
}
```

**Explanation**:
- `^1.0` = `>=1.0.0 <2.0.0`
- Allows MINOR and PATCH updates automatically
- Prevents MAJOR updates (which may have BC breaks)
- Recommended for production applications

**Alternative constraints**:

```json
// Lock to specific MINOR version
"somework/p2p-path-finder": "~1.5.0"  // >=1.5.0 <1.6.0

// Lock to exact version (not recommended)
"somework/p2p-path-finder": "1.5.3"

// Allow all 1.x versions (more flexible)
"somework/p2p-path-finder": "^1.0"
```

---

## FAQ

### Q: Can I rely on internal classes?

**A**: No. Only classes marked `@api` or in the public API namespaces are stable. Internal classes may change in MINOR versions.

### Q: Are performance improvements considered BC breaks?

**A**: No, as long as the behavior and output remain the same. Performance is not part of the API contract.

### Q: What if a bug fix changes behavior?

**A**: Bug fixes are PATCH releases even if they change behavior, because they restore the *intended* behavior. Document the fix clearly in CHANGELOG.md.

### Q: How long are MAJOR versions supported?

**A**: See [docs/support.md](support.md) for version support policies.

### Q: Can JSON output fields be removed?

**A**: No, removing fields from JSON output is a BC break. Fields can be deprecated and removed in a MAJOR version.

---

## Related Documentation

- [API Stability Guide](api-stability.md) - Detailed API stability guarantees
- [Support Policy](support.md) - Version support timelines
- [Release Process](release-process.md) - How releases are created
- [CHANGELOG.md](../CHANGELOG.md) - Full version history
- [UPGRADING.md](../UPGRADING.md) - Migration guides for major versions

---

*This versioning policy is effective as of version 1.0.0 and follows [Semantic Versioning 2.0.0](https://semver.org/).*

