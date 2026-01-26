# Upgrading Guide

This document provides step-by-step instructions for upgrading between major versions of the P2P Path Finder library.

## Table of Contents

- [General Upgrade Process](#general-upgrade-process)
- [Version Compatibility Matrix](#version-compatibility-matrix)
- [Upgrade Paths](#upgrade-paths)
- [Getting Help](#getting-help)

---

## General Upgrade Process

### Before Upgrading

1. **Read the full upgrade guide** for your target version
2. **Review the CHANGELOG** for all versions between current and target
3. **Check PHP version requirements** in [docs/releases-and-support.md](docs/releases-and-support.md)
4. **Backup your code** or ensure version control is in place
5. **Run your test suite** to establish a baseline

### Upgrade Steps

1. **Update composer.json**:
   ```bash
   # For MAJOR version upgrades
   composer require somework/p2p-path-finder:^2.0
   ```

2. **Update dependencies**:
   ```bash
   composer update somework/p2p-path-finder
   ```

3. **Follow version-specific migration steps** (see sections below)

4. **Run static analysis** to catch API changes:
   ```bash
   vendor/bin/phpstan analyse
   ```

5. **Run your test suite** and fix any failures

6. **Test in a staging environment** before deploying to production

### After Upgrading

- Monitor for any runtime issues
- Review deprecation warnings (check logs for `E_USER_DEPRECATED`)
- Update documentation references to the library
- Consider contributing fixes to the upgrade guide if you found issues

---

## Version Compatibility Matrix

| Your Version | Target Version | Difficulty | Breaking Changes | Required Actions                                           |
|--------------|----------------|------------|------------------|------------------------------------------------------------|
| **0.x**      | 1.0            | Medium     | Yes              | See [0.x → 1.0](#upgrading-from-0x-to-10)                  |
| **1.x**      | 2.0            | TBD        | TBD              | See [1.x → 2.0](#upgrading-from-1x-to-20) (when available) |

---

## Upgrade Paths

### Upgrading from 0.x to 1.0

**Status**: ⏳ Pre-1.0 (in development)

Version 1.0 will be the first stable release with major architectural improvements including comprehensive namespace reorganization.

#### Namespace Changes

**Breaking Change**: All public class namespaces have been reorganized for better clarity:

| Old Namespace | New Namespace |
|---------------|---------------|
| `Application\Service\*` | `Application\PathSearch\Service\*` |
| `Application\Config\*` | `Application\PathSearch\Config\*` |
| `Application\Graph\*` | `Application\PathSearch\Model\Graph\*` |
| `Application\PathFinder\*` | `Application\PathSearch\*` |
| `Application\Result\*` | `Application\PathSearch\Result\*` |
| `Domain\ValueObject\*` | `Domain\Money\*`, `Domain\Tolerance\*`, `Domain\Order\*` |

**Migration Required**: Update all import statements in your code:

```php
// Before
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

// After
use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Domain\Money\Money;
```

#### Other Changes

- Class names may have changed (e.g., `PathFinderService` → `PathSearchService`)
- Some internal APIs have been reorganized
- Exception hierarchy remains stable
- Domain object behavior is unchanged

**Timeline**:
- 0.1.0: Initial pre-release (current)
- 1.0.0-rc.1: Release candidate with final API (planned)
- 1.0.0: First stable release (planned)

---

### Upgrading from 1.x to 2.0

**Status**: ✅ Ready

Version 2.0 introduces `ExecutionPlanService` as the recommended API for path finding, deprecating `PathSearchService`.

#### Summary of Changes

The main change in 2.0 is the introduction of `ExecutionPlanService` which can find execution plans that:
- Use multiple orders for the same currency direction
- Split input across parallel routes
- Merge multiple routes at the target currency

The legacy `PathSearchService` only returns linear paths (single chain from source to target).

#### New Features

| Feature | Description |
|---------|-------------|
| **ExecutionPlanService** | New recommended service for path finding |
| **ExecutionPlan** | Result type supporting split/merge execution |
| **ExecutionStep** | Step with sequence number for execution ordering |
| **ExecutionStepCollection** | Immutable collection of steps |
| **Multi-order aggregation** | Multiple orders for same direction |
| **Split execution** | Input split across parallel routes |
| **Merge execution** | Routes converging at target |
| **PortfolioState** | Internal multi-currency balance tracking |

#### Multiple Paths → Single Plan

**Breaking Change**: `ExecutionPlanService::findBestPlans()` returns at most **ONE** optimal execution plan, not multiple ranked paths.

**Before (1.x with PathSearchService)**:

```php
$config = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withTopK(10)  // Request top 10 paths
    ->build();

$outcome = $service->findBestPaths($request);
$paths = $outcome->paths()->toArray();
foreach ($paths as $path) {
    // Process multiple alternative paths
    echo "Path: {$path->totalSpent()->amount()} -> {$path->totalReceived()->amount()}\n";
}
```

**After (2.x with ExecutionPlanService)**:

```php
$config = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->build();

$outcome = $service->findBestPlans($request);
$plan = $outcome->bestPath();  // Single optimal plan or null
if (null !== $plan) {
    // Process the single best plan
    echo "Plan: {$plan->totalSpent()->amount()} -> {$plan->totalReceived()->amount()}\n";
}

// Note: $outcome->paths()->count() will be 0 or 1
```

**Why this changed**: The new execution plan algorithm optimizes for a single global optimum that may include split/merge execution. The concept of "alternative paths" doesn't map cleanly to split/merge topology where a single plan can utilize multiple routes simultaneously. For alternative routes, run separate searches with modified constraints:

```php
// Get alternatives by varying constraints:

// 1. Run with different tolerance bounds
$config1 = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.0', '0.05')
    ->build();

// 2. Run with modified order book (filter out certain orders)
$filteredBook = new OrderBook($filteredOrders);

// 3. Run with different spend amounts
$config2 = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '500.00', 2))
    ->build();
```

#### Why PathSearchService is Deprecated

`PathSearchService` returns `Path` objects representing sequential, linear execution routes. However, real-world P2P trading scenarios often benefit from:

1. **Split execution**: Using multiple orders to fill a single request
2. **Liquidity aggregation**: Combining liquidity from multiple sources
3. **Better rates**: Achieving optimal overall cost through parallel routes

`ExecutionPlanService` addresses these limitations by returning `ExecutionPlan` objects that can represent both linear and non-linear execution topologies.

#### Migration Path

##### Step 1: Update Service Instantiation

**Before (deprecated)**:

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;

$service = new PathSearchService(new GraphBuilder());
```

**After (recommended)**:

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;

$service = new ExecutionPlanService(new GraphBuilder());
```

##### Step 2: Update Search Method Call

**Before**:

```php
$outcome = $service->findBestPaths($request);
```

**After**:

```php
$outcome = $service->findBestPlans($request);
```

##### Step 3: Update Result Processing

**Before (Path with hops)**:

```php
foreach ($outcome->paths() as $path) {
    echo "Spend: {$path->totalSpent()->amount()} {$path->totalSpent()->currency()}\n";
    echo "Receive: {$path->totalReceived()->amount()} {$path->totalReceived()->currency()}\n";
    
    foreach ($path->hops() as $hop) {
        echo "  {$hop->from()} -> {$hop->to()}\n";
        echo "  Order: {$hop->order()->assetPair()->base()}/{$hop->order()->assetPair()->quote()}\n";
    }
}
```

**After (ExecutionPlan with steps)**:

```php
foreach ($outcome->paths() as $plan) {
    echo "Spend: {$plan->totalSpent()->amount()} {$plan->totalSpent()->currency()}\n";
    echo "Receive: {$plan->totalReceived()->amount()} {$plan->totalReceived()->currency()}\n";
    echo "Is Linear: " . ($plan->isLinear() ? 'yes' : 'no') . "\n";
    
    foreach ($plan->steps() as $step) {
        echo "  Step {$step->sequenceNumber()}: {$step->from()} -> {$step->to()}\n";
        echo "  Order: {$step->order()->assetPair()->base()}/{$step->order()->assetPair()->quote()}\n";
    }
}
```

##### Incremental Migration (Hybrid Approach)

If you need to maintain backward compatibility during migration:

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;

$planService = new ExecutionPlanService(new GraphBuilder());
$outcome = $planService->findBestPlans($request);

foreach ($outcome->paths() as $plan) {
    // Convert linear plans to legacy Path format
    if ($plan->isLinear()) {
        $path = $plan->asLinearPath();
        if (null !== $path) {
            // Use $path with legacy code expecting PathHop objects
            processLegacyPath($path);
            continue;
        }
    }
    
    // Handle non-linear plans with new code
    processExecutionPlan($plan);
}
```

##### Migration Helper

A static helper method is available for converting linear execution plans to the legacy `Path` format:

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

// Convert a linear ExecutionPlan to Path
try {
    $path = PathSearchService::planToPath($executionPlan);
    // Use $path with legacy code
} catch (InvalidInput $e) {
    // Plan is non-linear or empty, handle accordingly
    // Use ExecutionPlan directly instead
}
```

#### API Comparison Table

| PathSearchService (Deprecated) | ExecutionPlanService (Recommended) |
|-------------------------------|-----------------------------------|
| `new PathSearchService($graphBuilder)` | `new ExecutionPlanService($graphBuilder)` |
| `$service->findBestPaths($request)` | `$service->findBestPlans($request)` |
| Returns `SearchOutcome<Path>` | Returns `SearchOutcome<ExecutionPlan>` |
| `$path->hops()` | `$plan->steps()` |
| `PathHop` | `ExecutionStep` |
| No `sequenceNumber()` | Has `sequenceNumber()` |
| `$hop->from()` | `$step->from()` |
| `$hop->to()` | `$step->to()` |
| `$hop->spent()` | `$step->spent()` |
| `$hop->received()` | `$step->received()` |
| `$hop->order()` | `$step->order()` |
| `$hop->fees()` | `$step->fees()` |
| Linear only | Linear + split/merge |
| N/A | `$plan->isLinear()` |
| N/A | `$plan->asLinearPath()` |
| N/A | `$plan->sourceCurrency()` |
| N/A | `$plan->targetCurrency()` |

#### Deprecation Timeline

| Version | Status |
|---------|--------|
| 2.0 | `PathSearchService` deprecated, `ExecutionPlanService` recommended |
| 3.0 (planned) | `PathSearchService` removed |

#### Deprecation Notices

When using `PathSearchService::findBestPaths()`, a deprecation notice will be triggered:

```
PathSearchService::findBestPaths() is deprecated since 2.0, use ExecutionPlanService::findBestPlans() instead.
```

To suppress these notices during migration, configure your error handler or use `@` operator temporarily.

#### Upgrade Checklist

- [ ] Identify all usages of `PathSearchService`
- [ ] Replace service instantiation with `ExecutionPlanService`
- [ ] Update method calls from `findBestPaths()` to `findBestPlans()`
- [ ] Update result handling from `Path` to `ExecutionPlan`
- [ ] Replace `hops()` with `steps()` in iteration loops
- [ ] Use `sequenceNumber()` for step ordering if needed
- [ ] Handle non-linear plans or use `isLinear()` + `asLinearPath()` for legacy compatibility
- [ ] Run tests to verify behavior
- [ ] Remove deprecation notice suppressions after migration complete
- [ ] Update any serialization/API responses that expose path structure

---

## Upgrade Guide Template

Each major version upgrade will follow this structure:

### Upgrading from X.x to Y.0

**Release Date**: YYYY-MM-DD  
**Difficulty**: Low / Medium / High  
**Estimated Time**: X hours

#### Summary

Brief overview of major changes and why the upgrade is beneficial.

#### PHP Version Requirements

- **Minimum PHP Version**: X.Y
- **Recommended PHP Version**: X.Y
- **Tested on**: PHP X.Y, X.Z

#### Breaking Changes

##### 1. [Feature/API Name] - [Short Description]

**What Changed**:
Describe the change in detail.

**Migration Required**: Yes / No

**Before** (version X.x):
```php
// Old API usage
$result = $service->oldMethod($param);
```

**After** (version Y.0):
```php
// New API usage
$result = $service->newMethod($param, $additionalParam);
```

**Rationale**:
Explain why the change was made.

---

##### 2. [Another Breaking Change]

**What Changed**:
...

---

#### Deprecations Removed

List features that were deprecated in X.x and removed in Y.0:

- `SomeClass::deprecatedMethod()` - Use `SomeClass::newMethod()` instead
- `OldConfig` class - Use `NewConfig` instead

#### New Features

Highlight new features available in Y.0:

- New feature A
- New feature B
- Performance improvement C

#### Known Issues

List any known issues or limitations in the new version:

- Issue 1 (workaround: ...)
- Issue 2 (will be fixed in Y.0.1)

#### Upgrade Checklist

- [ ] Update PHP to minimum required version
- [ ] Update composer.json to `"somework/p2p-path-finder": "^Y.0"`
- [ ] Run `composer update`
- [ ] Review all breaking changes above
- [ ] Update code for breaking change 1
- [ ] Update code for breaking change 2
- [ ] Run `vendor/bin/phpstan analyse` (fix any errors)
- [ ] Run test suite (fix any failures)
- [ ] Test in staging environment
- [ ] Review deprecation warnings in logs
- [ ] Update documentation/comments referencing old API
- [ ] Deploy to production with monitoring

---

## Getting Help

### Upgrade Issues

If you encounter issues during an upgrade:

1. **Check the troubleshooting guide**: [docs/troubleshooting.md](docs/troubleshooting.md)
2. **Search existing issues**: [GitHub Issues](https://github.com/somework/p2p-path-finder/issues)
3. **Ask in discussions**: [GitHub Discussions](https://github.com/somework/p2p-path-finder/discussions)
4. **Report a bug**: [New Issue](https://github.com/somework/p2p-path-finder/issues/new)

When reporting upgrade issues, include:
- Current version
- Target version
- PHP version
- Error messages
- Minimal reproduction code

### Rollback Procedure

If you need to rollback an upgrade:

```bash
# Revert composer.json to previous version
git checkout HEAD~1 -- composer.json

# Reinstall dependencies
composer install

# Verify rollback
vendor/bin/phpunit
```

Alternatively, if you have a backup:

```bash
# Restore from backup
cp composer.json.backup composer.json
composer install
```

### Commercial Support

For commercial support with upgrades, contact:
- Email: i.pinchuk.work@gmail.com

---

## Contributing to This Guide

If you find issues with this upgrade guide or have suggestions for improvement:

1. **Submit corrections** via Pull Request
2. **Share your experience** in GitHub Discussions
3. **Report missing information** via GitHub Issues

Your contributions help make upgrades easier for everyone!

---

## Related Documentation

- [Releases and Support Policy](docs/releases-and-support.md) - Versioning, BC breaks, and support timelines
- [CHANGELOG](CHANGELOG.md) - Detailed change history

---

*This upgrade guide is maintained alongside each major release to ensure smooth transitions.*

