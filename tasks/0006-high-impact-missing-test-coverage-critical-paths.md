# Task: Test Coverage Analysis for Critical Paths

## Context

The repository has comprehensive test coverage including:
- Unit tests for domain objects
- Integration tests for PathFinder
- Property-based tests for invariants
- Mutation testing with Infection (MSI ≥80%, covered MSI ≥85%)
- Dense graph and adversarial tests
- Guard limit tests
- Benchmark performance tests

However, test coverage should be audited to ensure:
- All critical paths are covered
- Edge cases are tested
- Integration scenarios are realistic
- Property tests exercise sufficient cases
- Mutation testing catches actual issues

## Problem

**Potential coverage gaps:**

1. **Integration test realism**:
   - Do tests cover realistic order book scenarios?
   - Are multi-hop paths with fees tested?
   - Are tolerance window edge cases tested end-to-end?
   - Are guard limits tested with realistic graphs?

2. **Filter and extension point coverage**:
   - Are all OrderFilterInterface implementations tested?
   - Are custom PathOrderStrategy implementations tested?
   - Are FeePolicy edge cases (zero fees, high fees, multi-currency) tested?
   - Are filter combinations tested?

3. **Graph builder coverage**:
   - Are all order types (buy/sell, with/without fees) tested?
   - Are edge segment construction edge cases tested?
   - Are mandatory vs optional segment computations verified?
   - Are graph nodes with high fan-out tested?

4. **Error paths**:
   - Are all exception paths tested?
   - Are null returns tested?
   - Are empty result scenarios tested?
   - Are validation failures tested?

5. **Serialization coverage**:
   - Are all JSON serialization paths tested?
   - Are deserialization/hydration paths tested?
   - Are edge cases (empty, null fields) tested?

6. **Concurrency safety** (if applicable):
   - Are value objects truly immutable?
   - Can the same OrderBook be used by multiple searches?
   - Are there any shared mutable state concerns?

7. **Performance regression coverage**:
   - Are benchmarks comprehensive enough?
   - Are worst-case scenarios benchmarked?
   - Are guard limits verified to prevent runaway searches?

8. **Documentation examples**:
   - Are all README examples actually tested?
   - Is the guarded search example tested?
   - Are quick-start scenarios tested?

## Proposed Changes

### 1. Run coverage report and identify gaps

```bash
vendor/bin/phpunit --coverage-html coverage-report
```

**Analyze:**
- Which classes have < 90% coverage?
- Which methods are never called in tests?
- Which branches are never taken?
- Which exception paths are never exercised?

**Focus on:**
- PathFinder (core algorithm)
- PathFinderService (main facade)
- GraphBuilder (graph construction)
- LegMaterializer (result materialization)
- Domain value objects (Money, ExchangeRate, Order, etc.)

### 2. Add integration tests for realistic scenarios

**Test scenarios:**
- **Multi-hop with fees**: 3-hop path with different fee structures
- **Dense order book**: 100+ orders with multiple paths
- **Tolerance at boundaries**: min=max (zero flexibility)
- **Guard breach recovery**: Search that hits limits but returns partial results
- **Empty order book handling**: Various empty/invalid inputs
- **Currency triangle arbitrage**: A→B→C→A paths
- **High-scale currencies**: Crypto with 18 decimal places

**Create new test file:** `tests/Application/Service/PathFinder/PathFinderServiceIntegrationTest.php`

### 3. Test all extension points thoroughly

**OrderFilterInterface:**
- Test all built-in filters with various order books
- Test filter chains (multiple filters applied)
- Test filter with zero matches
- Test filter with all matches
- Test custom filter implementation (add example)

**PathOrderStrategy:**
- Test custom ordering strategy
- Test tie-breaking behavior
- Test with equal-cost paths
- Test determinism (run multiple times, verify same order)

**FeePolicy:**
- Test zero-fee policy
- Test high-fee policy (fee > amount)
- Test multi-currency fees (base + quote fees)
- Test percentage vs fixed fees
- Test FeeBreakdown accumulation

### 4. Test error paths comprehensively

**For each exception type:**
- Test that it's thrown in documented scenarios
- Test exception message content
- Test exception recovery (where applicable)

**For each nullable return:**
- Test null path
- Test handling of null by callers

**For each empty collection:**
- Test empty order book
- Test empty result set
- Test no matching filters

### 5. Test serialization/deserialization

**JSON serialization:**
- Test PathResult::jsonSerialize()
- Test SearchOutcome::jsonSerialize()
- Test SearchGuardReport::jsonSerialize()
- Test Money serialization
- Test round-trip: serialize then deserialize (if applicable)
- Test with extreme values (very large, very small numbers)
- Test with various scales (0, 2, 8, 18)

### 6. Verify documentation examples

**README examples:**
- Extract all code examples
- Create tests that run them
- Verify output matches documented behavior

**Guarded search example:**
- Test exists (`tests/Documentation/GuardedSearchExampleTest.php`)
- Verify it's current and comprehensive

**Quick-start scenarios:**
- Test scenario 1 (direct buy)
- Test scenario 2 (multi-hop sell with tight tolerance)

**Create:** `tests/Documentation/ReadmeExamplesTest.php`

### 7. Property test expansion

**Review existing property tests:**
- PathFinderPropertyTest
- GraphBuilderPropertyTest  
- PathFinderHeuristicsPropertyTest
- SearchStateQueueOrderingPropertyTest
- CandidateResultHeapPropertyTest
- Are iteration counts sufficient? (check with InfectionIterationLimiter)

**Add new property tests if gaps found:**
- Order creation properties
- Money arithmetic properties
- ExchangeRate conversion properties
- Tolerance window properties

### 8. Mutation testing gap analysis

**Review Infection report:**
```bash
XDEBUG_MODE=coverage vendor/bin/infection --no-progress
```

**Identify:**
- Mutants that survive (not caught by tests)
- Areas with low mutation score
- Ignored mutators (see infection.json.dist)

**Focus on:**
- LegMaterializer (has many ignored mutators)
- ToleranceWindowFilter (has ignored mutators)
- Critical comparisons and conditionals

**Add tests to kill more mutants**

### 9. Benchmark coverage review

**Verify benchmarks cover:**
- Various order book sizes (100, 1K, 10K)
- Various graph densities (sparse, moderate, dense)
- Various hop limits (2-6)
- k-best search with various k (1, 10, 100)
- Guard limit scenarios

**Check regression assertions:**
- Time regression (±20%)
- Memory regression (±20%)

### 10. Concurrency and immutability tests

**Add tests verifying:**
- Value objects cannot be mutated after construction
- OrderBook can be reused across searches
- PathSearchConfig can be reused
- No shared state between searches

**Create:** `tests/Application/Service/PathFinder/PathFinderServiceConcurrencyTest.php`

## Dependencies

- Should be done after task 0004 (algorithm correctness) as issues found there might require new tests
- Informs task 0001 (public API) about which extension points need better examples

## Effort Estimate

**L** (1-3 days)
- Coverage report analysis: 2 hours
- Integration test implementation: 4-6 hours
- Extension point tests: 2-3 hours
- Error path tests: 2-3 hours
- Serialization tests: 2 hours
- Documentation example tests: 2 hours
- Property test review/expansion: 2-3 hours
- Mutation testing analysis: 3-4 hours

## Risks / Considerations

- **Diminishing returns**: Chasing 100% coverage can lead to tests that don't add value
- **Test maintenance**: More tests = more maintenance burden
- **False confidence**: High coverage doesn't guarantee correctness
- **Performance**: Large test suites can slow down CI

**Balance**: Focus on critical paths, edge cases, and integration scenarios. Don't test trivial getters just for coverage.

## Definition of Done

- [ ] Coverage report generated and analyzed
- [ ] All classes with < 85% coverage reviewed (decide if additional tests needed)
- [ ] Integration tests added for realistic multi-hop, fee, tolerance scenarios
- [ ] All extension points tested with custom implementations
- [ ] All error paths tested
- [ ] Serialization round-trip tests added
- [ ] All README examples tested in tests/Documentation/ReadmeExamplesTest.php
- [ ] Property tests reviewed and expanded if needed
- [ ] Mutation testing report analyzed, high-value mutants killed
- [ ] Benchmarks verified to cover critical scenarios
- [ ] Concurrency/immutability tests added
- [ ] Overall coverage ≥ 90% (or gaps justified)
- [ ] Infection MSI ≥ 80%, covered MSI ≥ 85% (maintained)
- [ ] All tests pass
- [ ] CI builds pass

**Priority:** P2 – High impact

