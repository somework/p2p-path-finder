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
| ------------ | -------------- | ---------- | ---------------- | ---------------------------------------------------------- |
| **0.x**      | 1.0            | Medium     | Yes              | See [0.x → 1.0](#upgrading-from-0x-to-10)                  |
| **1.x**      | 2.0            | Medium     | Yes              | See [1.x → 2.0](#upgrading-from-1x-to-20)                  |

---

## Upgrade Paths

### Upgrading from 0.x to 1.0

**Status**: ⏳ Pre-1.0 (in development)

Version 1.0 will be the first stable release with major architectural improvements including comprehensive namespace reorganization.

#### Namespace Changes

**Breaking Change**: All public class namespaces have been reorganized for better clarity:

| Old Namespace | New Namespace |
| ------------- | ------------- |
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
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Domain\Money\Money;
```

#### Other Changes

- Class names may have changed (e.g., `PathFinderService` → `ExecutionPlanService`)
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

**Release Date**: TBD  
**Difficulty**: Medium  
**Estimated Time**: 1-2 hours

Version 2.0 introduces `ExecutionPlanService` as the sole API for path finding. The deprecated `PathSearchService` and its underlying `PathSearchEngine` have been removed. This version also removes deprecated methods from `ExecutionPlanSearchOutcome`.

#### Summary of Changes

The main change in 2.0 is `ExecutionPlanService` which can find execution plans that:
- Use multiple orders for the same currency direction
- Split input across parallel routes
- Merge multiple routes at the target currency

#### Removed APIs

The following classes and methods have been removed in version 2.0:

##### Removed Classes

- **`PathSearchService`** - Use `ExecutionPlanService` instead
- **`PathSearchEngine`** - Internal class, no longer accessible

##### Removed Methods

- **`ExecutionPlanSearchOutcome::hasPlan()`** - Use `hasRawFills()` instead
- **`ExecutionPlanSearchOutcome::plan()`** - Use `rawFills()` + `ExecutionPlanMaterializer` instead

#### New Features

| Feature | Description |
| --------- | ------------- |
| **ExecutionPlanService** | New recommended service for path finding |
| **ExecutionPlan** | Result type supporting split/merge execution |
| **ExecutionStep** | Step with sequence number for execution ordering |
| **ExecutionStepCollection** | Immutable collection of steps |
| **Multi-order aggregation** | Multiple orders for same direction |
| **Split execution** | Input split across parallel routes |
| **Merge execution** | Routes converging at target |
| **PortfolioState** | Internal multi-currency balance tracking |
| **Top-K Discovery** | Return up to K distinct alternative plans |

#### Top-K Execution Plan Discovery

**New Feature**: `ExecutionPlanService::findBestPlans()` now supports **Top-K execution plan discovery**, returning up to K distinct, ranked execution plans when configured via `PathSearchConfig::resultLimit()`.

**How it works**:
- Set `resultLimit(K)` in PathSearchConfig to request K alternative plans
- Default is `resultLimit(1)` for backward compatibility
- Each plan uses a **completely disjoint set of orders** (no order reuse)
- Plans are ranked by cost (best/cheapest first)
- If fewer than K alternatives exist, returns as many as found

**Example**:

```php
$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '1000.00', 2))
    ->withToleranceBounds('0.0', '0.10')
    ->withHopLimits(1, 3)
    ->withResultLimit(5)  // Request top 5 plans
    ->build();

$outcome = $service->findBestPlans($request);

// Get all alternative plans
echo "Found {$outcome->paths()->count()} alternative plans:\n";
foreach ($outcome->paths() as $rank => $plan) {
    echo "Plan #{$rank}: {$plan->totalReceived()->amount()} received\n";
}

// Best plan is always first
$bestPlan = $outcome->bestPath();
```

**Use cases**:
- **Fallback options**: Have backup plans if primary fails during execution
- **Rate comparison**: Compare trade-offs across different routes
- **Risk diversification**: Spread execution across multiple strategies
- **User selection**: Display alternatives for user to choose from

**Guard limits**: Metrics are aggregated across all K search iterations. Use `guardLimits()->anyLimitReached()` to check if any iteration hit a limit.

See [examples/top-k-execution-plans.php](examples/top-k-execution-plans.php) for a complete demonstration.

---

#### Multiple Paths → Single Plan (Default K=1)

**Note**: With `resultLimit(1)` (the default), `ExecutionPlanService::findBestPlans()` returns at most **ONE** optimal execution plan. The `PathSearchService` class has been removed.

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

#### Why PathSearchService Was Removed

The legacy `PathSearchService` returned `Path` objects representing sequential, linear execution routes. Real-world P2P trading scenarios benefit from:

1. **Split execution**: Using multiple orders to fill a single request
2. **Liquidity aggregation**: Combining liquidity from multiple sources
3. **Better rates**: Achieving optimal overall cost through parallel routes

`ExecutionPlanService` addresses these requirements by returning `ExecutionPlan` objects that can represent both linear and non-linear execution topologies.

#### Migration Path

##### Step 1: Update Service Instantiation

**Before (1.x)**:

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;

$service = new PathSearchService(new GraphBuilder());
```

**After (2.x)**:

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;

$service = new ExecutionPlanService(new GraphBuilder());
```

##### Step 2: Update Search Method Call

**Before (1.x)**:

```php
$outcome = $service->findBestPaths($request);
```

**After (2.x)**:

```php
$outcome = $service->findBestPlans($request);
```

##### Step 3: Update Result Processing

**Before (1.x with Path and hops)**:

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

**After (2.x with ExecutionPlan and steps)**:

```php
$bestPlan = $outcome->bestPath();
if (null !== $bestPlan) {
    echo "Spend: {$bestPlan->totalSpent()->amount()} {$bestPlan->totalSpent()->currency()}\n";
    echo "Receive: {$bestPlan->totalReceived()->amount()} {$bestPlan->totalReceived()->currency()}\n";
    echo "Is Linear: " . ($bestPlan->isLinear() ? 'yes' : 'no') . "\n";
    
    foreach ($bestPlan->steps() as $step) {
        echo "  Step {$step->sequenceNumber()}: {$step->from()} -> {$step->to()}\n";
        echo "  Order: {$step->order()->assetPair()->base()}/{$step->order()->assetPair()->quote()}\n";
    }
}
```

**Note**: `ExecutionPlanService::findBestPlans()` returns at most **ONE** optimal execution plan. The `paths()` collection will contain either 0 or 1 entries. Use `bestPath()` to get the single plan or null.

##### Step 4: Update ExecutionPlanSearchOutcome Usage

If you're working directly with `ExecutionPlanSearchOutcome` (public API), update method calls:

**Before (1.x)**:

```php
$searchOutcome = $engine->search($graph, $source, $target, $spend);

if ($searchOutcome->hasPlan()) {
    $plan = $searchOutcome->plan();
    // Use $plan directly
}
```

**After (2.x)**:

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanMaterializer;

$searchOutcome = $engine->search($graph, $source, $target, $spend);

if ($searchOutcome->hasRawFills()) {
    $rawFills = $searchOutcome->rawFills();
    $materializer = new ExecutionPlanMaterializer();
    $plan = $materializer->materialize(
        $rawFills,
        $source,
        $target,
        $tolerance
    );
    // Use $plan
}
```

**Rationale**: The search engine now returns raw order fills instead of pre-materialized plans. This separation allows for more flexible materialization strategies and better separation of concerns.

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

For linear execution plans, you can convert to the legacy `Path` format:

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;

// Convert a linear ExecutionPlan to Path
$plan = $outcome->bestPath();
if (null !== $plan && $plan->isLinear()) {
    $path = $plan->asLinearPath();
    // Use $path with legacy code expecting PathHop objects
}
```

#### API Reference

**ExecutionPlanService API**:

| Method/Property | Description |
| ---------------- | ------------- |
| `new ExecutionPlanService($graphBuilder)` | Create service with GraphBuilder |
| `$service->findBestPlans($request)` | Find best execution plan |
| Returns `SearchOutcome<ExecutionPlan>` | Search result with plan |
| `$plan->steps()` | Get execution steps |
| `$plan->isLinear()` | Check if plan is linear |
| `$plan->asLinearPath()` | Convert linear plan to Path |
| `$plan->sourceCurrency()` | Get source currency |
| `$plan->targetCurrency()` | Get target currency |
| `$step->sequenceNumber()` | Get step sequence |
| `$step->from()` | Get step source currency |
| `$step->to()` | Get step target currency |
| `$step->spent()` | Get spent amount |
| `$step->received()` | Get received amount |
| `$step->order()` | Get order reference |
| `$step->fees()` | Get fee breakdown |

#### Code Examples

##### Example 1: Basic Migration

**Before (1.x)**:

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Domain\Money\Money;

$service = new PathSearchService(new GraphBuilder());
$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->build();

$request = new PathSearchRequest($orderBook, $config, 'BTC');
$outcome = $service->findBestPaths($request);

foreach ($outcome->paths() as $path) {
    foreach ($path->hops() as $hop) {
        // Process hop
    }
}
```

**After (2.x)**:

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Domain\Money\Money;

$service = new ExecutionPlanService(new GraphBuilder());
$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->build();

$request = new PathSearchRequest($orderBook, $config, 'BTC');
$outcome = $service->findBestPlans($request);

$bestPlan = $outcome->bestPath();
if (null !== $bestPlan) {
    foreach ($bestPlan->steps() as $step) {
        // Process step
    }
}
```

##### Example 2: Converting Linear Plans to Legacy Path Format

If you need to maintain compatibility with code expecting `Path` objects:

```php
$bestPlan = $outcome->bestPath();
if (null !== $bestPlan && $bestPlan->isLinear()) {
    $path = $bestPlan->asLinearPath();
    if (null !== $path) {
        // Use $path with legacy code expecting PathHop objects
        foreach ($path->hops() as $hop) {
            // Legacy code here
        }
    }
}
```

##### Example 3: Working with ExecutionPlanSearchOutcome (Public API)

**Before (1.x)**:

```php
$searchOutcome = $engine->search($graph, 'USD', 'BTC', $spend);

if ($searchOutcome->hasPlan()) {
    $plan = $searchOutcome->plan();
    // Use plan directly
}
```

**After (2.x)**:

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanMaterializer;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;

$searchOutcome = $engine->search($graph, 'USD', 'BTC', $spend);

if ($searchOutcome->hasRawFills()) {
    $rawFills = $searchOutcome->rawFills();
    $materializer = new ExecutionPlanMaterializer();
    $tolerance = DecimalTolerance::fromNumericString('0', 18);
    
    $plan = $materializer->materialize(
        $rawFills,
        'USD',
        'BTC',
        $tolerance
    );
    
    if (null !== $plan) {
        // Use plan
    }
}
```

#### Upgrade Checklist

- [ ] Replace `PathSearchService` with `ExecutionPlanService`
- [ ] Update method calls from `findBestPaths()` to `findBestPlans()`
- [ ] Update result handling from `Path` to `ExecutionPlan`
- [ ] Replace `hops()` with `steps()` in iteration loops
- [ ] Replace `ExecutionPlanSearchOutcome::hasPlan()` with `hasRawFills()`
- [ ] Replace `ExecutionPlanSearchOutcome::plan()` with `rawFills()` + `ExecutionPlanMaterializer`
- [ ] Use `sequenceNumber()` for step ordering if needed
- [ ] Handle non-linear plans or use `isLinear()` + `asLinearPath()` for legacy compatibility
- [ ] Consider using `resultLimit(K)` for Top-K plan discovery if alternatives are needed
- [ ] Update code expecting multiple paths (default is now 0 or 1 plan; use `resultLimit` for more)
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

