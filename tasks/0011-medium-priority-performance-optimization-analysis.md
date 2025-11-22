# Task: Performance Optimization Analysis and Low-Hanging Fruit

## Context

Current performance characteristics (from README and benchmarks):
- **k-best-n1e2 (100 orders)**: 25.5ms mean, 8.3 MB peak memory
- **k-best-n1e3 (1,000 orders)**: 216.3ms mean, 12.8 MB peak memory
- **k-best-n1e4 (10,000 orders)**: 2,154.7ms mean, 59.1 MB peak memory
- **85-87% faster** than BCMath baseline after BigDecimal migration
- **Performance targets met** (KPI targets comfortably exceeded)

Performance profiling completed:
- Hotspot profiles documented (docs/performance/hotspot-profile.md)
- Performance issues tracked and marked complete (docs/performance/issues/)
- GraphBuilder optimizations applied (copy churn reduced)
- PathFinderService callback optimizations applied

This task focuses on:
- Identifying remaining optimization opportunities
- Ensuring no obvious performance regressions
- Documenting performance characteristics
- Adding performance tests where needed

**Note**: This is a P3 task because performance is already good. Focus on analysis and low-risk improvements.

## Problem

**Performance considerations:**

1. **Profiling coverage**:
   - Are all critical paths profiled?
   - Are there unexplored worst-case scenarios?
   - Are memory allocations tracked?

2. **Known hotspots** (from hotspot-profile.md):
   - GraphBuilder (5.9% / 38.7% inclusive) - already optimized
   - PathFinderService callback (7.7% / 40.0%) - already optimized
   - What about PathFinder search loop itself?
   - What about LegMaterializer?
   - What about value object construction?

3. **Low-hanging fruit**:
   - Object pooling opportunities (already done for Money?)
   - Memoization opportunities
   - Lazy evaluation opportunities
   - Array vs SplFixedArray for large collections
   - String concatenation vs implode
   - isset() vs array_key_exists()

4. **BigDecimal performance**:
   - Are there places where BigDecimal operations are repeated unnecessarily?
   - Can intermediate results be cached?
   - Are there unnecessary precision conversions?

5. **Memory efficiency**:
   - Are there memory leaks?
   - Are large objects retained unnecessarily?
   - Can search state be more compact?
   - Can visited state tracking be more efficient?

6. **Guard tuning**:
   - Are default guard limits optimal?
   - Should there be presets for different use cases?
   - Can guards be more efficient?

7. **Filter efficiency**:
   - Are filters applied in optimal order?
   - Can filters short-circuit earlier?
   - Are filters creating unnecessary copies?

8. **Benchmark coverage**:
   - Do benchmarks cover all critical paths?
   - Are there missing performance regression tests?
   - Are benchmarks representative of real usage?

## Proposed Changes

### 1. Run comprehensive profiling

**Re-run XDebug profiling** on critical scenarios:
```bash
php -d xdebug.mode=profile \
    -d xdebug.start_with_request=yes \
    -d xdebug.output_dir=.xdebug \
    vendor/bin/phpbench run \
      --config=phpbench.json \
      --filter=benchFindKBestPaths \
      --iterations=1 \
      --revs=1
```

**Analyze with QCacheGrind or similar**:
- Identify top 10 time-consuming functions
- Identify top 10 memory-allocating functions
- Look for unexpected hotspots

**Update docs/performance/hotspot-profile.md** if new insights found

### 2. Profile specific operations

**Micro-benchmarks for**:
- Money creation and arithmetic
- BigDecimal operations
- SearchState creation and cloning
- Graph node/edge access
- Priority queue operations

**Create benchmarks/MicrobenchmarksTest.php** if significant opportunities found

### 3. Analyze low-hanging fruit

**Review for common PHP optimizations**:

**Object creation**:
- Are value objects created in hot loops?
- Can they be cached or pooled?
- Example: Zero Money instances already cached ✓

**String operations**:
- Any string concatenation in loops?
- Can be replaced with array + implode?

**Array operations**:
- Large arrays using standard PHP arrays?
- Consider SplFixedArray for memory efficiency (probably not needed)

**Function calls**:
- Repeated calls with same arguments?
- Can results be memoized?

**Type checks**:
- `is_*()` calls in hot paths?
- Can be cached or eliminated?

**Searches**:
```bash
grep -r "foreach" src/ --include="*.php" | wc -l
grep -r "array_map\|array_filter" src/ --include="*.php" | wc -l
```

Identify hot loops and review for optimization

### 4. Review BigDecimal usage patterns

**Look for**:
- Repeated `BigDecimal::of()` calls with same value
- Unnecessary scale conversions
- Multiple operations that could be chained
- Intermediate BigDecimal objects that could be reused

**Example pattern to avoid**:
```php
// BAD (multiple conversions)
$a = BigDecimal::of($str1);
$b = BigDecimal::of($str2);
$sum = $a->plus($b);
$scaled = $sum->toScale(18, HALF_UP);

// BETTER (if $str1 and $str2 are reused)
// Cache BigDecimal instances
```

**Check if value objects already optimize this** ✓ (they should)

### 5. Analyze memory retention

**Check for**:
- Large arrays or objects stored in instance variables
- Closures capturing large contexts
- Circular references preventing garbage collection
- Search state retained after search completes

**Use memory profiler** or manual analysis:
```bash
php -d memory_limit=-1 benchmarks/memory-analysis.php
```
(Create if doesn't exist)

**Verify**: Search results don't retain full search state unnecessarily

### 6. Optimize guard checks

**Current guard implementation**:
- Guards checked on every iteration
- Time budget checks require `microtime()` calls

**Possible optimizations**:
- Batch guard checks (check every N iterations)
- Use cheaper iteration counters where possible
- Profile guard check overhead

**Measure** before optimizing - guard checks might be negligible

### 7. Review filter execution order

**Current filter pattern**:
```php
$filtered = $orderBook
    ->filtered(new AmountRangeFilter($config))
    ->filtered(new ToleranceWindowFilter($config));
```

**Questions**:
- Which filter is most selective?
- Should most selective run first?
- Are filters creating full copies?

**Analyze filter selectivity** with real order books:
- How many orders does each filter eliminate?
- What's the performance of each filter?

**Optimize ordering** if significant gains possible

### 8. Add performance regression tests

**Add to CI workflow** (quality.yml):
```yaml
- name: Performance regression check
  run: |
    php -d memory_limit=-1 -d xdebug.mode=off \
      vendor/bin/phpbench run \
        --config=phpbench.json \
        --ref=baseline \
        --assert="mean(variant.time.avg) <= mean(baseline.time.avg) +/- 20%" \
        --assert="mean(variant.mem.peak) <= mean(baseline.mem.peak) +/- 20%"
```

**Already exists** ✓ (verify it's actually running)

### 9. Document performance characteristics

**Enhance docs/memory-characteristics.md**:
- Add section on performance tuning
- Document time complexity of major operations
- Document space complexity
- Provide decision trees for configuration

**Create docs/performance-tuning.md**:
- How to profile your usage
- How to choose guard limits
- How to optimize order books
- How to minimize memory usage
- How to minimize latency

### 10. Consider JIT compilation

**PHP 8.2+ has JIT**:
- Is JIT enabled in benchmarks?
- Does JIT help or hurt BigDecimal operations?
- Should JIT be recommended for production?

**Test with JIT**:
```bash
php -d opcache.enable_cli=1 \
    -d opcache.jit_buffer_size=100M \
    -d opcache.jit=tracing \
    vendor/bin/phpbench run --config=phpbench.json
```

**Document findings** in performance-tuning.md

## Dependencies

- Complements task 0006 (test coverage) - performance tests should be covered
- Informs task 0007 (documentation) - performance tuning guide

## Effort Estimate

**M** (0.5-1 day)
- Profiling: 2-3 hours
- Low-hanging fruit analysis: 2-3 hours
- BigDecimal usage review: 1-2 hours
- Memory analysis: 1-2 hours
- Guard optimization review: 1 hour
- Filter ordering analysis: 1 hour
- Documentation: 2-3 hours
- JIT testing: 1 hour

**Note**: Focus on analysis and documentation, not deep optimization (performance is already good)

## Risks / Considerations

- **Premature optimization**: Don't optimize without measurements
- **Complexity vs performance**: Optimizations can hurt readability
- **Maintenance burden**: Complex optimizations are harder to maintain
- **Diminishing returns**: Already 85-87% faster than baseline
- **Breaking determinism**: Some optimizations (caching, memoization) might affect reproducibility

**Principle**: Only optimize if:
1. Profiling shows clear hotspot
2. Optimization is simple and maintainable
3. Optimization doesn't compromise determinism
4. Benchmarks confirm improvement

## Definition of Done

- [ ] Comprehensive profiling completed (XDebug + analysis)
- [ ] Top 10 hotspots identified and documented
- [ ] Low-hanging fruit analysis completed
- [ ] BigDecimal usage patterns reviewed
- [ ] Memory retention analysis completed
- [ ] Guard check overhead measured
- [ ] Filter execution order analyzed
- [ ] Performance regression tests verified in CI
- [ ] docs/performance-tuning.md created or updated
- [ ] JIT compilation tested and documented
- [ ] Any implemented optimizations:
  - [ ] Benchmarked before/after
  - [ ] No determinism impact
  - [ ] Tests still pass
  - [ ] Code still readable
- [ ] Performance characteristics documented
- [ ] Baseline updated if improvements made

**Priority:** P3 – Nice to have

