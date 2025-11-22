# PathFinderService Error Handling Review

**Date**: 2024-11-22  
**Task**: 0005.4  
**Decision**: Empty Results Should Return Empty SearchOutcome (Not Throw)

## Executive Summary

✅ **DECISION**: PathFinderService should **return empty `SearchOutcome`** for no-results scenarios, NOT throw an exception.

**Rationale**: No results is a valid business outcome, not an error condition.

---

## Current Behavior

### Scenario 1: No Orders After Filtering

**Code** (`PathFinderService.php` lines 173-182):
```php
$orders = $this->orderSpendAnalyzer->filterOrders($orderBook, $config);
if ([] === $orders) {
    /** @var SearchOutcome<PathResult> $empty */
    $empty = SearchOutcome::empty(SearchGuardReport::idle(...));
    return $empty;
}
```

**Behavior**: Returns empty `SearchOutcome` with idle guard report

**Is This Correct?** ✅ **YES**

### Scenario 2: Source or Target Not in Graph

**Code** (`PathFinderService.php` lines 185-195):
```php
$graph = $this->graphBuilder->build($orders);
if (!$graph->hasNode($sourceCurrency) || !$graph->hasNode($targetCurrency)) {
    /** @var SearchOutcome<PathResult> $empty */
    $empty = SearchOutcome::empty(SearchGuardReport::idle(...));
    return $empty;
}
```

**Behavior**: Returns empty `SearchOutcome` with idle guard report

**Is This Correct?** ✅ **YES**

### Scenario 3: No Paths Found (Search Completes)

**Behavior**: PathFinder returns `SearchOutcome` with empty paths collection

**Is This Correct?** ✅ **YES**

### Scenario 4: All Paths Rejected by Callback

**Behavior**: Returns `SearchOutcome` with empty paths (callback filtered all)

**Is This Correct?** ✅ **YES**

---

## Decision Rationale

### Why Return Empty SearchOutcome (Not Throw)?

#### 1. **No Results is a Valid Business Outcome**

```php
// Valid scenarios that legitimately return no results:
- No orders meet filter criteria (e.g., all below minimum amount)
- Source/target currency not in order book
- Graph has no path between source and target
- All paths exceed tolerance bounds
- All paths rejected by acceptance callback
- Guards limit search before finding any viable paths
```

**Conclusion**: These are **expected scenarios**, not errors.

#### 2. **Consumer Can Distinguish Scenarios**

```php
$result = $service->findBestPaths($request);

if ($result->paths()->isEmpty()) {
    // Check guard report to understand why
    if ($result->guardLimits()->anyLimitReached()) {
        // Partial search - may want to retry with higher limits
    } else {
        // Complete search - truly no paths available
    }
}
```

**Benefit**: Consumer gets actionable information without exception handling.

#### 3. **Consistent with Query Pattern**

```php
// Similar to database queries:
$users = $repository->findByEmail('example@example.com');
// Returns empty array if not found, doesn't throw

// Similar to search APIs:
$results = $searchEngine->search('query');
// Returns empty results, doesn't throw
```

**Pattern**: "Find" operations return empty collections, not exceptions.

#### 4. **Guard Report Provides Context**

```php
$result = $service->findBestPaths($request);

// Guard report explains search outcome
$guardReport = $result->guardLimits();

if ($guardReport->expansionsReached()) {
    // Hit expansion limit - partial results
}

if ($guardReport->timeBudgetReached()) {
    // Hit time limit - partial results
}

// No guards hit + empty results = truly no paths
```

**Benefit**: Rich metadata explains why results are empty.

#### 5. **Exception Reserved for Actual Errors**

**Throw exceptions for**:
- Invalid input (e.g., negative hop limit)
- Configuration errors (e.g., min > max hops)
- System errors (e.g., out of memory)

**NOT for**:
- Empty results (valid outcome)
- Optional values (return null)
- Boolean conditions (return true/false)

---

## Alternative Considered: Throw Exception

### ❌ Why Throwing Would Be Wrong

```php
// BAD: Treating no-results as error
if ($result->paths()->isEmpty()) {
    throw new InfeasiblePath('No paths found');
}
```

**Problems**:

1. **Forces exception handling for common case**:
   ```php
   try {
       $result = $service->findBestPaths($request);
   } catch (InfeasiblePath $e) {
       // Forced to catch even for expected "no results" case
   }
   ```

2. **Loses guard report information**:
   - Can't distinguish "no paths exist" from "search limited by guards"
   - Would need to attach guard report to exception

3. **Inconsistent with query pattern**:
   - Other "find" operations return empty, not throw
   - Breaks principle of least surprise

4. **Makes partial results difficult**:
   - What if guards hit but some results found?
   - Throw or return? Inconsistent.

5. **Poor ergonomics**:
   ```php
   // Consumer must always wrap in try-catch
   try {
       $result = $service->findBestPaths($request);
       // ... use results ...
   } catch (InfeasiblePath $e) {
       // Common case treated as exceptional
   }
   ```

---

## When to Throw Exceptions in PathFinderService

### Throw `InvalidInput`

**For**:
- Malformed request parameters
- Invalid configuration

**Example**:
```php
if (empty($request->targetAsset())) {
    throw new InvalidInput('Target asset cannot be empty');
}
```

### Throw `GuardLimitExceeded`

**For**:
- Guard limits exceeded (when `throwOnGuardLimit` configured)

**Current Implementation** (`PathFinderService.php` line 307):
```php
if ($config->throwOnGuardLimit() && $guardLimits->anyLimitReached()) {
    throw new GuardLimitExceeded($this->formatGuardLimitMessage($config, $guardLimits));
}
```

**Note**: This is **opt-in** via configuration. Default behavior is to return `SearchOutcome` with guard report.

---

## Implementation Status

### ✅ Current Implementation is Correct

1. **Empty results return `SearchOutcome`** ✅
2. **Guard report explains outcome** ✅
3. **Configurable exception throwing for guards** ✅
4. **No changes needed** ✅

---

## Consumer Usage Patterns

### Pattern 1: Check for Empty Results

```php
$result = $service->findBestPaths($request);

if ($result->paths()->isEmpty()) {
    $this->logger->info('No paths found', [
        'source' => $request->sourceAsset(),
        'target' => $request->targetAsset(),
        'guardLimits' => $result->guardLimits(),
    ]);
    
    // Handle no-results case (not an error)
    return $this->createFallbackResponse();
}

// Use found paths
foreach ($result->paths() as $path) {
    // ... process path ...
}
```

### Pattern 2: Check Guard Limits

```php
$result = $service->findBestPaths($request);

if ($result->guardLimits()->anyLimitReached()) {
    $this->logger->warning('Search limited by guards', [
        'guardReport' => $result->guardLimits(),
    ]);
    
    // Decide: accept partial results or retry with higher limits
    if ($result->paths()->isEmpty()) {
        // No results due to guard limits - may want to retry
        return $this->retryWithHigherLimits($request);
    }
}

// Use results (partial or complete)
return $result->paths();
```

### Pattern 3: Opt-In Exception Mode

```php
$config = PathSearchConfigBuilder::create($orderBook, $targetAsset, $tolerance)
    ->withHopLimits($minHops, $maxHops)
    ->withThrowOnGuardLimit(true)  // Opt-in to exceptions
    ->build();

try {
    $result = $service->findBestPaths(new PathSearchRequest($orderBook, $config, $targetAsset));
    // If we get here, search completed without hitting guards
} catch (GuardLimitExceeded $e) {
    // Guard limit hit - decide how to handle
    $this->logger->error('Search guard limit exceeded', ['exception' => $e]);
}
```

---

## Documentation Recommendations

### Update `docs/exceptions.md`

Add section on "Empty Results Handling":

```markdown
## Empty Results vs Exceptions

**Empty results are NOT errors** - they are valid business outcomes.

**Return empty `SearchOutcome`** when:
- No orders match filter criteria
- Source/target not in graph
- No paths exist between source and target
- All paths filtered out by tolerance/constraints
- All paths rejected by callback
- Guards limit search before finding paths

**Throw exceptions** when:
- Input parameters are invalid
- Configuration is malformed
- System errors occur

**Example**:
```php
$result = $service->findBestPaths($request);

if ($result->paths()->isEmpty()) {
    // Valid scenario - no paths available
    // Check guard report for context
}
```
```

---

## Testing Recommendations

### Test Empty Result Scenarios

```php
public function testReturnsEmptySearchOutcomeWhenNoOrdersAfterFiltering(): void
{
    $orderBook = new OrderBook();
    // ... orders that will be filtered out ...
    
    $config = PathSearchConfigBuilder::create(...)
        ->build();
    
    $result = $this->service->findBestPaths(new PathSearchRequest($orderBook, $config, 'EUR'));
    
    self::assertTrue($result->paths()->isEmpty(), 'Should return empty results');
    self::assertFalse($result->guardLimits()->anyLimitReached(), 'No guards should be hit');
}

public function testReturnsEmptySearchOutcomeWhenSourceNotInGraph(): void
{
    $orderBook = new OrderBook();
    $orderBook->add(OrderFactory::buy('EUR', 'GBP', '100', '1000', '0.9', 2, 2));
    
    $config = PathSearchConfigBuilder::create(...)
        ->build();
    
    // USD not in graph
    $result = $this->service->findBestPaths(new PathSearchRequest($orderBook, $config, 'EUR'));
    
    self::assertTrue($result->paths()->isEmpty(), 'Should return empty results');
}
```

---

## Summary

### ✅ Decision: Return Empty SearchOutcome

**Rationale**:
1. No results is a valid business outcome
2. Consumer can distinguish scenarios via guard report
3. Consistent with query/search patterns
4. Exception reserved for actual errors
5. Better ergonomics for consumers

### Current Implementation Status

✅ **Correct** - No changes needed

### Documentation Updates

✅ Add empty results handling to `docs/exceptions.md`

### Testing

✅ Add tests for empty result scenarios (if not already covered)

---

## References

- `src/Application/Service/PathFinderService.php` (lines 164-299)
- `docs/exceptions.md` (established conventions)
- `src/Application/PathFinder/Result/SearchOutcome.php`
- `src/Application/PathFinder/Result/SearchGuardReport.php`

