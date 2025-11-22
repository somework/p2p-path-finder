# Task: Property-Based Test Expansion and Invariant Coverage

## Context

The repository already has property-based tests:
- `PathFinderPropertyTest` - path finding properties
- `GraphBuilderPropertyTest` - graph construction properties
- `PathFinderHeuristicsPropertyTest` - heuristic properties
- `SearchStateQueueOrderingPropertyTest` - queue ordering properties
- `CandidateResultHeapPropertyTest` - result heap properties
- `PathEdgeSequencePropertyTest` - edge sequence properties
- `SearchStateRegistryPropertyTest` - state registry properties
- Various property tests for domain objects (FeeBreakdown, AssetPair, OrderBounds)

Property tests use `InfectionIterationLimiter` to reduce iterations during mutation testing.

This task focuses on:
- Expanding property test coverage
- Ensuring invariants are comprehensive
- Improving property test quality
- Documenting property testing strategy

## Problem

**Property test gaps and opportunities:**

1. **Missing property tests**:
   - Are all value objects covered? (Money, ExchangeRate, etc.)
   - Are all critical algorithms covered?
   - Are all invariants tested as properties?

2. **Property quality**:
   - Are properties meaningful (not just replicating implementation)?
   - Are properties testing actual invariants?
   - Are properties testing edge cases?
   - Are generators producing realistic values?

3. **Generator coverage**:
   - Do generators produce edge cases (zero, negative, very large, very small)?
   - Do generators produce diverse values?
   - Are generators documented?

4. **Iteration counts**:
   - Are iteration counts sufficient to catch bugs?
   - Are they too high (slowing down tests)?
   - How does InfectionIterationLimiter affect coverage?

5. **Property documentation**:
   - Are properties self-explanatory?
   - Are invariants documented?
   - Is property testing strategy documented?

6. **Metamorphic properties**:
   - Are there metamorphic relationships that should be tested?
   - Examples: doubling input doubles output, reversing path reverses costs, etc.
   - Current: PathFinderMetamorphicTest exists ✓

7. **Shrinking behavior**:
   - When properties fail, do they shrink to minimal failing cases?
   - Are generators designed for good shrinking?

8. **Property composition**:
   - Can properties be composed to test complex invariants?
   - Are there property combinators in use?

## Proposed Changes

### 1. Audit current property test coverage

**Create spreadsheet/document mapping**:
- All domain objects → property tests
- All application services → property tests  
- All critical algorithms → property tests
- All invariants → property tests

**Identify gaps**

### 2. Add missing property tests for domain objects

**Money property tests** (if not already comprehensive):
- Identity: `money.add(zero) = money`
- Commutativity: `a.add(b) = b.add(a)`
- Associativity: `a.add(b).add(c) = a.add(b.add(c))`
- Subtraction inverse: `money.add(x).subtract(x) = money`
- Multiplication: `money.multiply("2").multiply("3") = money.multiply("6")`
- Scale preservation: Operations preserve scale correctly
- Currency preservation: Operations preserve currency
- Zero properties: `money.multiply("0").isZero()`

**ExchangeRate property tests**:
- Inversion: `rate.invert().invert() ≈ rate` (within precision)
- Conversion: `rate.convert(money).currency() = rate.quoteCurrency()`
- Identity rate: Rate of 1.0 preserves amount
- Composition: Converting A→B→C = converting A→C (if rates exist)

**OrderBounds property tests**:
- Contains: If `bounds.contains(x)`, then `x >= min && x <= max`
- Boundary: `bounds.contains(min)` and `bounds.contains(max)` always true
- Transitivity: If min ≤ x ≤ max and x ≤ y ≤ max, then min ≤ y ≤ max

**ToleranceWindow property tests**:
- Ordering: minimum ≤ maximum always
- Range: 0 ≤ minimum, maximum < 1
- Spend bounds: computed bounds preserve ordering

### 3. Add missing property tests for algorithms

**PathFinder property tests** (expand existing):
- **Optimality**: Found path has lowest cost among all valid paths
- **Completeness**: If path exists, algorithm finds it (within guards)
- **Hop limits**: No result violates min/max hops
- **Tolerance**: All results within tolerance bounds
- **Guard respect**: Search respects all guard limits
- **Determinism**: Same input produces same output
- **Monotonicity**: Tighter tolerance → fewer or same paths

**GraphBuilder property tests** (expand existing):
- **Completeness**: All orders represented in graph
- **Correctness**: Edge rates match order rates
- **Capacity**: Edge capacity matches order bounds
- **Symmetry**: Buy/Sell orders create correct edge directions

### 4. Improve property test generators

**Review current generators**:
- `NumericStringGenerator` (if exists)
- Money generators
- Order generators
- Graph generators

**Ensure generators produce**:
- **Edge cases**: 0, negative (if valid), very large, very small, boundary values
- **Diverse values**: Not just 1, 2, 3
- **Realistic values**: Match actual use cases
- **Valid combinations**: Generated structures are internally consistent

**Document generators** in tests/Support/Generator/README.md or inline

### 5. Tune iteration counts

**Current strategy** (InfectionIterationLimiter):
- Reduces iterations during Infection runs
- Prevents property tests from dominating mutation testing time

**Review**:
- What are normal iteration counts? (100? 1000?)
- What are Infection iteration counts? (10? 50?)
- Are they sufficient to catch bugs?

**Experiment**:
```php
// Normal run
phpunit tests/Domain/ValueObject/MoneyPropertyTest.php

// Infection run
INFECTION=1 phpunit tests/Domain/ValueObject/MoneyPropertyTest.php
```

**Tune** if needed, document rationale

### 6. Add metamorphic properties

**PathFinder metamorphic tests** (expand existing):
- **Scale invariance**: Multiplying all amounts by constant preserves paths
- **Order permutation**: Reordering orders doesn't change optimal path
- **Edge cases**: Adding irrelevant orders doesn't change result
- **Guard monotonicity**: Tighter guards → subset of results

**GraphBuilder metamorphic tests**:
- **Order addition**: Adding order adds edges, doesn't remove
- **Order removal**: Removing order removes edges, doesn't add
- **Consistency**: Building graph twice gives same result

### 7. Document property testing strategy

**Create tests/PropertyTestStrategy.md**:

Explain:
- Why property-based testing is used
- What invariants are tested
- How generators work
- How to add new property tests
- Iteration count strategy
- How InfectionIterationLimiter works
- Best practices for property tests

Link from CONTRIBUTING.md

### 8. Add property test examples

**In docs/examples/** or tests/:

Show how to:
- Write a simple property test
- Create a generator
- Test an invariant
- Use InfectionIterationLimiter
- Debug a failing property test

### 9. Review shrinking behavior

**When a property fails**:
- Does it show a minimal failing case?
- Or does it show the original (potentially complex) case?

**PHP property testing libraries** (if used):
- eris/eris
- infection/infection
- Or custom implementation?

**Verify shrinking works** or document if not available

### 10. Add continuous property testing

**Consider**:
- Running property tests with higher iteration counts in nightly builds
- Running property tests with different seeds
- Tracking property test failure rates

**Add to CI** (optional):
```yaml
- name: Extended property tests
  if: github.event_name == 'schedule' # Nightly
  run: |
    PROPERTY_ITERATIONS=10000 vendor/bin/phpunit \
      --group property \
      --testdox
```

## Dependencies

- Complements task 0006 (test coverage) - property tests are part of coverage strategy
- Informs task 0007 (documentation) - property test strategy docs

## Effort Estimate

**M** (0.5-1 day)
- Coverage audit: 1-2 hours
- Domain object properties: 2-3 hours
- Algorithm properties: 2-3 hours
- Generator improvements: 1-2 hours
- Iteration count tuning: 1 hour
- Metamorphic tests: 1-2 hours
- Documentation: 2 hours
- Examples: 1-2 hours
- Shrinking review: 1 hour

## Risks / Considerations

- **Diminishing returns**: Property tests can be expensive to write and run
- **False confidence**: Passing property tests don't guarantee correctness
- **Complexity**: Property tests can be harder to understand than example-based tests
- **Flakiness**: Poorly designed properties can be flaky
- **Performance**: High iteration counts can slow down test suite

**Balance**: 
- Focus on testing actual invariants, not implementation details
- Keep iteration counts reasonable
- Document properties clearly
- Use property tests alongside example-based tests

## Definition of Done

- [ ] Property test coverage audit completed
- [ ] Missing domain object property tests added (Money, ExchangeRate, OrderBounds, ToleranceWindow)
- [ ] Algorithm property tests expanded (PathFinder, GraphBuilder)
- [ ] Property test generators reviewed and improved
- [ ] Edge case generation verified
- [ ] Iteration counts reviewed and tuned
- [ ] InfectionIterationLimiter behavior verified
- [ ] Metamorphic properties expanded
- [ ] tests/PropertyTestStrategy.md created
- [ ] Property test examples added
- [ ] Shrinking behavior reviewed and documented
- [ ] Optional: Continuous property testing added to CI
- [ ] All new property tests pass
- [ ] No flaky tests introduced
- [ ] Test suite runtime remains reasonable

**Priority:** P3 – Nice to have

