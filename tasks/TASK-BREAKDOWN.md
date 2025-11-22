# Task Breakdown - Detailed Subtasks

This document breaks down each of the 14 major tasks into smaller, actionable subtasks. Each subtask should be completable in 1-4 hours and has clear success criteria.

## Legend

- **Task ID**: Original task number (0001-0014)
- **Subtask ID**: Detailed breakdown (e.g., 0001.1, 0001.2)
- **Effort**: XS (<1h), S (1-2h), M (2-4h), L (4-8h)
- **Dependencies**: Other subtasks that must complete first

---

## 0001: Public API Surface Finalization

### 0001.1: Public API Inventory
**Effort**: M (2-3h)  
**Dependencies**: None

**Actions**:
- Create comprehensive list of all public classes, interfaces, methods
- Mark each as: PUBLIC (stable), INTERNAL (marked @internal), UNCLEAR (needs decision)
- Document in `docs/api-stability.md` (create file)
- Include: PathFinderService, PathSearchRequest, PathSearchConfig, SearchOutcome, PathResult, PathResultSet, domain VOs, interfaces

**Done When**:
- [ ] docs/api-stability.md created with complete API inventory
- [ ] All classes categorized as PUBLIC or INTERNAL
- [ ] Rationale provided for UNCLEAR cases

---

### 0001.2: Review PathFinderService::withRunnerFactory()
**Effort**: S (1h)  
**Dependencies**: 0001.1

**Actions**:
- Review usage of withRunnerFactory() in codebase
- Determine if it's test-only or needed by consumers
- Either: Move to separate test helper OR document as @internal test utility
- Update PHPDoc to clarify intended usage

**Done When**:
- [ ] Decision documented in 0001.1 findings
- [ ] If test-only: Method documented as @internal with clear comment
- [ ] If public: Document use cases and examples

---

### 0001.3: Review Value Object Exposure in Internal Types
**Effort**: S (1-2h)  
**Dependencies**: 0001.1

**Actions**:
- Review CandidatePath and SpendConstraints exposure via callback signatures
- Determine if exposure is intentional
- Document decision in api-stability.md
- If keeping public: Add to public API list with docs

**Done When**:
- [ ] CandidatePath exposure documented
- [ ] SpendConstraints exposure documented
- [ ] If public: Added to api-stability.md with justification

---

### 0001.4: Extension Point Documentation - OrderFilterInterface
**Effort**: M (2-3h)  
**Dependencies**: 0001.1

**Actions**:
- Document OrderFilterInterface contract in interface PHPDoc
- Add performance expectations
- Add stability guarantees (e.g., filters must not modify orders)
- Create example custom filter in `examples/custom-order-filter.php`
- Test example works

**Done When**:
- [ ] OrderFilterInterface fully documented
- [ ] examples/custom-order-filter.php created and tested
- [ ] Example demonstrates best practices

---

### 0001.5: Extension Point Documentation - PathOrderStrategy
**Effort**: M (2-3h)  
**Dependencies**: 0001.1

**Actions**:
- Document PathOrderStrategy contract
- Document stable sort requirements
- Document determinism expectations
- Create example custom strategy in `examples/custom-ordering-strategy.php`
- Test example works

**Done When**:
- [ ] PathOrderStrategy fully documented
- [ ] examples/custom-ordering-strategy.php created and tested
- [ ] Determinism requirements clearly stated

---

### 0001.6: Extension Point Documentation - FeePolicy
**Effort**: M (2-3h)  
**Dependencies**: 0001.1

**Actions**:
- Document FeePolicy contract
- Document calculation order and currency constraints
- Create example custom fee policy in `examples/custom-fee-policy.php`
- Test example works

**Done When**:
- [ ] FeePolicy fully documented
- [ ] examples/custom-fee-policy.php created and tested
- [ ] Currency constraint behavior documented

---

### 0001.7: JSON Serialization Contract - PathResult
**Effort**: S (1-2h)  
**Dependencies**: 0001.1

**Actions**:
- Document PathResult::jsonSerialize() structure
- Add to `docs/api-contracts.md` (create file)
- Include field types and required/optional status
- Add version compatibility note

**Done When**:
- [ ] docs/api-contracts.md created
- [ ] PathResult JSON structure fully documented
- [ ] Example JSON output included

---

### 0001.8: JSON Serialization Contract - SearchOutcome & SearchGuardReport
**Effort**: S (1-2h)  
**Dependencies**: 0001.7

**Actions**:
- Document SearchOutcome::jsonSerialize() structure
- Document SearchGuardReport::jsonSerialize() structure
- Add to docs/api-contracts.md
- Include examples

**Done When**:
- [ ] SearchOutcome JSON structure documented
- [ ] SearchGuardReport JSON structure documented
- [ ] Examples added to api-contracts.md

---

### 0001.9: JSON Serialization Contract - Money & Domain VOs
**Effort**: S (1h)  
**Dependencies**: 0001.7

**Actions**:
- Document Money serialization format (currency, amount, scale)
- Document other serializable domain VOs if any
- Add to docs/api-contracts.md

**Done When**:
- [ ] Money serialization format documented
- [ ] All serializable domain VOs documented

---

### 0001.10: JSON Serialization Tests
**Effort**: M (2-3h)  
**Dependencies**: 0001.7, 0001.8, 0001.9

**Actions**:
- Create `tests/Application/SerializationContractTest.php`
- Test PathResult JSON structure matches documentation
- Test SearchOutcome JSON structure matches documentation
- Test Money JSON structure matches documentation
- Ensure tests will catch breaking changes

**Done When**:
- [ ] SerializationContractTest.php created
- [ ] All documented JSON structures tested
- [ ] Tests verify structure, not just serialization works

---

### 0001.11: Add @api Annotations
**Effort**: M (2-3h)  
**Dependencies**: 0001.1

**Actions**:
- Add @api PHPDoc tag to all PUBLIC classes/methods from inventory
- Verify PHPDoc generator (bin/generate-phpdoc.php) respects @api
- Test doc generation excludes @internal

**Done When**:
- [ ] @api tags added to all public API surface
- [ ] PHPDoc generator tested
- [ ] Generated docs verified to include @api, exclude @internal

---

### 0001.12: Update README with API Documentation Links
**Effort**: XS (<1h)  
**Dependencies**: 0001.1, 0001.7

**Actions**:
- Add links to docs/api-stability.md from README
- Add links to docs/api-contracts.md from README
- Add "API Documentation" section if not present

**Done When**:
- [ ] README links to API documentation
- [ ] Links tested and working

---

## 0002: Domain Model Invariant Validation

### 0002.1: Money Negative Amount Policy
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Review if negative Money amounts are currently allowed
- Decide: Allow or reject negative amounts
- Document decision in code and docs/domain-invariants.md (create)
- Add/update validation if needed
- Add tests for chosen policy

**Done When**:
- [ ] Decision made and documented
- [ ] Validation implemented if rejecting negatives
- [ ] Tests added covering the policy

---

### 0002.2: Money Scale Boundary Tests
**Effort**: S (1-2h)  
**Dependencies**: None

**Actions**:
- Test scale = 0 (integers)
- Test scale = 50 (max)
- Test scale = -1 (should reject)
- Test scale = 51 (should reject)
- Add to existing or new MoneyTest

**Done When**:
- [ ] All boundary tests added
- [ ] All tests pass
- [ ] Edge cases documented in @invariant annotation

---

### 0002.3: Money Extreme Value Tests
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Test very large amounts (e.g., "999999999999999999999999999.99")
- Test very small amounts (e.g., "0.00000000000000000001")
- Verify no overflow/underflow issues
- Add to MoneyTest

**Done When**:
- [ ] Extreme value tests added
- [ ] Tests pass without precision loss or errors

---

### 0002.4: Money Scale Mismatch Arithmetic Tests
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Test Money(scale=2) + Money(scale=8)
- Test Money multiply/divide with different scale rates
- Verify scale is preserved or correctly derived
- Document scale derivation rules in @invariant
- Add to MoneyTest

**Done When**:
- [ ] Scale mismatch tests added
- [ ] Scale derivation documented
- [ ] All tests pass

---

### 0002.5: ExchangeRate Extreme Rate Tests
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Test very small rates (e.g., "0.00000001")
- Test very large rates (e.g., "100000000.0")
- Test conversion with extreme rates
- Add to ExchangeRateTest

**Done When**:
- [ ] Extreme rate tests added
- [ ] Conversions maintain precision
- [ ] All tests pass

---

### 0002.6: ExchangeRate Inversion Edge Cases
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Test rate.invert().invert() ≈ rate (within precision)
- Test identity rate (1.0) inversion
- Test rate close to zero inversion
- Add to ExchangeRateTest

**Done When**:
- [ ] Inversion tests added
- [ ] Precision tolerance documented
- [ ] All tests pass

---

### 0002.7: OrderBounds Boundary Tests
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Test min = max (single valid amount)
- Test min > max (should reject)
- Test min = 0, max = 0
- Test negative bounds (if not allowed by Money)
- Add to OrderBoundsTest

**Done When**:
- [ ] Boundary tests added
- [ ] Invalid cases properly rejected
- [ ] All tests pass

---

### 0002.8: OrderBounds contains() Method Tests
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Test amount exactly at min/max
- Test amount just below/above bounds
- Test amount with different scale than bounds
- Add to OrderBoundsTest

**Done When**:
- [ ] contains() edge cases tested
- [ ] Scale handling verified
- [ ] All tests pass

---

### 0002.9: ToleranceWindow Boundary Tests
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Test min = 0, max = 0 (zero window)
- Test min = 0, max close to 1 (0.9999999999999999)
- Test min = max (should allow or reject explicitly)
- Test min > max (should reject)
- Add to ToleranceWindowTest

**Done When**:
- [ ] Boundary tests added
- [ ] Invalid cases properly rejected
- [ ] All tests pass

---

### 0002.10: ToleranceWindow Spend Bounds Computation
**Effort**: S (1-2h)  
**Dependencies**: None

**Actions**:
- Verify PathSearchConfig computes bounds correctly from tolerance
- Test edge cases: zero window, wide window, boundaries
- Add to PathSearchConfigTest

**Done When**:
- [ ] Spend bounds computation tested
- [ ] Edge cases covered
- [ ] All tests pass

---

### 0002.11: Order Consistency Validation Tests
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Test Order with bounds currency != base currency (should reject)
- Test Order with rate base currency != asset pair base (should reject)
- Test Order with rate quote currency != asset pair quote (should reject)
- Test Order with fee currency mismatches
- Add to OrderTest

**Done When**:
- [ ] Consistency validation tests added
- [ ] All invalid cases properly rejected
- [ ] All tests pass

---

### 0002.12: Fee Policy Edge Case Tests
**Effort**: S (1-2h)  
**Dependencies**: None

**Actions**:
- Test fees larger than amounts
- Test zero fees
- Test negative fees (if allowed)
- Add to FeePolicyTest

**Done When**:
- [ ] Fee edge cases tested
- [ ] Behavior documented
- [ ] All tests pass

---

### 0002.13: Document Domain Invariants
**Effort**: M (2-3h)  
**Dependencies**: 0002.1-0002.12

**Actions**:
- Create `docs/domain-invariants.md`
- List all value object constraints
- Document valid ranges for numeric fields
- Document currency format requirements
- Document scale limitations
- Include @invariant annotations in all VOs

**Done When**:
- [ ] docs/domain-invariants.md created
- [ ] All invariants documented
- [ ] @invariant PHPDoc annotations added to all VOs
- [ ] Document linked from README

---

### 0002.14: Add/Expand Property-Based Tests
**Effort**: M (2-3h)  
**Dependencies**: 0002.13

**Actions**:
- Money commutativity: a + b = b + a
- Money associativity: (a + b) + c = a + (b + c)
- Money subtraction inverse: (a + b) - b = a
- ExchangeRate conversion roundtrip
- Add to existing property tests or create new

**Done When**:
- [ ] Property tests added for documented invariants
- [ ] Tests use adequate iteration counts
- [ ] All tests pass

---

## 0003: Decimal Arithmetic Consistency Audit

### 0003.1: Grep Audit - Float Literals
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Search for float literals in arithmetic: `/\d+\.\d+\s*[\+\-\*\/]/`
- Document findings
- Evaluate if each is legitimate (test mocks, constants) or needs fixing
- Create issue list

**Done When**:
- [ ] Search completed
- [ ] All float literals documented
- [ ] Legitimate vs problematic cases identified

---

### 0003.2: Grep Audit - BCMath Remnants
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Search for: `bc(add|sub|mul|div|pow|comp|scale|sqrt|mod)`
- Document any findings
- Migrate any BCMath calls to BigDecimal
- Update tests if needed

**Done When**:
- [ ] Search completed
- [ ] All BCMath calls migrated or justified
- [ ] Tests updated and passing

---

### 0003.3: Grep Audit - PHP Math Functions
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Search for: `round()`, `ceil()`, `floor()`, `pow()`, `sqrt()`
- Document findings
- Evaluate if each is legitimate or needs BigDecimal equivalent
- Fix any that bypass BigDecimal incorrectly

**Done When**:
- [ ] Search completed
- [ ] All math functions reviewed
- [ ] Problematic cases fixed

---

### 0003.4: Audit RoundingMode Usage
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Search for all `->toScale()` calls
- Verify all use `RoundingMode::HALF_UP` or document why not
- Search for `RoundingMode::` to find all uses
- Ensure no PHP `round()` calls (different tie-breaking)
- Document any exceptions

**Done When**:
- [ ] All toScale() calls reviewed
- [ ] All use HALF_UP or exception documented
- [ ] No problematic round() calls found

---

### 0003.5: Audit PathFinder Scale Usage
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Verify PathFinder::SCALE (18) used for tolerance/cost calculations
- Verify SearchState cost normalization uses SCALE
- Verify CandidatePath cost normalization uses SCALE
- Verify DecimalTolerance uses SCALE
- Document findings

**Done When**:
- [ ] All PathFinder scale usage verified
- [ ] SCALE constant used consistently
- [ ] Any deviations documented and justified

---

### 0003.6: Audit Working Precision Constants
**Effort**: S (1-2h)  
**Dependencies**: 0003.5

**Actions**:
- Verify RATIO_EXTRA_SCALE (4) used correctly in PathFinder
- Verify SUM_EXTRA_SCALE (2) used correctly
- Check if constants are applied consistently
- Document findings

**Done When**:
- [ ] Working precision usage verified
- [ ] Constants applied consistently
- [ ] Purpose of each constant clear in code

---

### 0003.7: Audit Value Object Scale Handling
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Money: Verify arithmetic preserves scale correctly
- ExchangeRate: Verify conversion scale is max(money.scale, rate.scale)
- Review scale derivation rules
- Add tests if gaps found

**Done When**:
- [ ] Money scale handling verified
- [ ] ExchangeRate scale handling verified
- [ ] Scale derivation rules documented

---

### 0003.8: Audit Comparison Operations
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Search for `===`, `==`, `<`, `>`, `<=`, `>=` involving BigDecimal
- Ensure all use `->compareTo()`, `->isEqualTo()`, `->isLessThan()`, etc.
- Check for string comparisons of amounts
- Fix any problematic comparisons

**Done When**:
- [ ] All comparisons reviewed
- [ ] All use BigDecimal methods, not operators
- [ ] No string comparisons of numeric values

---

### 0003.9: Audit Serialization Boundaries
**Effort**: S (1-2h)  
**Dependencies**: None

**Actions**:
- Verify all BigDecimal->string conversions use `->toScale(scale, HALF_UP)->__toString()`
- Check jsonSerialize() implementations
- Review SerializesMoney trait usage
- Ensure no locale-dependent formatting

**Done When**:
- [ ] All serialization boundaries verified
- [ ] Consistent formatting everywhere
- [ ] No locale dependencies

---

### 0003.10: Audit Test Fixtures
**Effort**: S (1-2h)  
**Dependencies**: None

**Actions**:
- Search tests for hardcoded numeric strings
- Ensure DecimalMath helper used consistently
- Verify property test generators produce canonical strings
- Update fixtures if needed

**Done When**:
- [ ] Test fixtures reviewed
- [ ] DecimalMath used consistently
- [ ] Generators produce canonical output

---

### 0003.11: Cross-Reference decimal-strategy.md
**Effort**: S (1h)  
**Dependencies**: 0003.5, 0003.6

**Actions**:
- Verify documented scale values match code:
  - PathFinder::SCALE == 18
  - PathFinder::RATIO_EXTRA_SCALE == 4
  - PathFinder::SUM_EXTRA_SCALE == 2
- Verify documented rounding policy matches code
- Update docs if discrepancies found

**Done When**:
- [ ] Documentation verified against code
- [ ] Any discrepancies fixed
- [ ] Documentation accurate

---

### 0003.12: Optional: Custom PHPStan Rules
**Effort**: L (4-6h)  
**Dependencies**: 0003.1-0003.11

**Actions**:
- Research PHPStan custom rule development
- Create rules to detect:
  - Float literals in arithmetic
  - String comparisons of numeric values
  - Missing RoundingMode in toScale()
  - bcmath function calls
- Test rules
- Document in CONTRIBUTING.md

**Done When**:
- [ ] Custom rules created and tested
- [ ] Rules catch known anti-patterns
- [ ] Rules documented for contributors

---

## 0004: PathFinder Algorithm Correctness

### 0004.1: Review Tolerance Amplifier Calculation
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Audit tolerance amplifier calculation in PathFinder constructor
- Verify formula is correct
- Test with tolerance = 0, 0.5, 0.999999...
- Document formula in PathFinder docblock

**Done When**:
- [ ] Amplifier calculation reviewed and verified
- [ ] Edge cases tested
- [ ] Formula documented

---

### 0004.2: Review Tolerance Pruning Logic
**Effort**: M (2-3h)  
**Dependencies**: 0004.1

**Actions**:
- Review tolerance pruning in search loop
- Verify candidates pruned correctly based on tolerance
- Add debug logging if needed for review
- Document pruning logic

**Done When**:
- [ ] Pruning logic reviewed
- [ ] Logic verified correct
- [ ] Documentation updated

---

### 0004.3: Test Tolerance Edge Cases
**Effort**: M (2-3h)  
**Dependencies**: 0004.1, 0004.2

**Actions**:
- Test tolerance = 0 (must spend exactly)
- Test tolerance close to upper bound (0.999999...)
- Test tolerance window where min = max
- Add to PathFinderTest

**Done When**:
- [ ] Tolerance edge case tests added
- [ ] All tests pass
- [ ] Behavior documented

---

### 0004.4: Review Hop Limit Enforcement
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Verify minimum hops filter in PathFinderService callback
- Verify maximum hops check in PathFinder search loop
- Document hop enforcement sequence
- Ensure no paths violate limits

**Done When**:
- [ ] Hop enforcement verified
- [ ] Enforcement sequence documented
- [ ] No bugs found

---

### 0004.5: Test Hop Limit Edge Cases
**Effort**: S (1-2h)  
**Dependencies**: 0004.4

**Actions**:
- Test path optimal but violates minimum hops
- Test path respects max hops at search level but callback rejects
- Add to PathFinderTest

**Done When**:
- [ ] Hop limit edge case tests added
- [ ] Tests verify correct behavior
- [ ] All tests pass

---

### 0004.6: Review SearchGuards Implementation
**Effort**: M (2-3h)  
**Dependencies**: None

**Actions**:
- Review expansion count increment
- Review visited state count
- Review time budget check frequency
- Verify counts match actual search activity

**Done When**:
- [ ] SearchGuards implementation reviewed
- [ ] Counting verified correct
- [ ] Time budget check frequency adequate

---

### 0004.7: Verify Guard Report Accuracy
**Effort**: M (2h)  
**Dependencies**: 0004.6

**Actions**:
- Add test that verifies reported counts match actual
- Test elapsed time measurement
- Test breach flags set correctly
- Add to SearchGuardsTest

**Done When**:
- [ ] Guard report accuracy tests added
- [ ] All metrics verified accurate
- [ ] All tests pass

---

### 0004.8: Test Guard Combinations
**Effort**: M (2-3h)  
**Dependencies**: 0004.6, 0004.7

**Actions**:
- Test multiple guards reached simultaneously
- Test guards at boundary values (1 expansion, 1ms budget)
- Test guards with very large limits
- Test exception vs metadata modes
- Add to SearchGuardsTest

**Done When**:
- [ ] Guard combination tests added
- [ ] All edge cases covered
- [ ] All tests pass

---

### 0004.9: Review Ordering Determinism
**Effort**: M (2-3h)  
**Dependencies**: None

**Actions**:
- Verify SearchStatePriorityQueue tie-breaking
- Verify CandidatePriorityQueue tie-breaking
- Verify PathOrderStrategy usage
- Review for any sources of non-determinism

**Done When**:
- [ ] Ordering implementation reviewed
- [ ] Tie-breaking verified correct
- [ ] No non-determinism found

---

### 0004.10: Test Ordering Determinism
**Effort**: M (2-3h)  
**Dependencies**: 0004.9

**Actions**:
- Test large batch of equal-cost paths
- Test paths with identical cost and hops but different signatures
- Test repeated runs produce same order
- Add to PathFinderTest

**Done When**:
- [ ] Ordering determinism tests added
- [ ] Stable ordering verified
- [ ] All tests pass

---

### 0004.11: Review Mandatory Segment Logic
**Effort**: M (2-3h)  
**Dependencies**: None

**Actions**:
- Review SegmentPruner implementation
- Verify mandatory capacity aggregation in search loop
- Check edge pruning based on mandatory capacity
- Document mandatory segment semantics

**Done When**:
- [ ] Mandatory segment logic reviewed
- [ ] Aggregation verified correct
- [ ] Logic documented

---

### 0004.12: Test Mandatory Segment Edge Cases
**Effort**: M (2-3h)  
**Dependencies**: 0004.11

**Actions**:
- Test path with mandatory segments exceeding spend constraints
- Test edge with all-mandatory vs mixed segments
- Test zero mandatory capacity (all optional)
- Add to PathFinderTest

**Done When**:
- [ ] Mandatory segment tests added
- [ ] Edge cases covered
- [ ] All tests pass

---

### 0004.13: Review Spend Constraints Propagation
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Verify SpendConstraints computed correctly from ToleranceWindow
- Verify constraints updated when traversing edges
- Check constraint violations caught early

**Done When**:
- [ ] Constraints propagation reviewed
- [ ] Computation verified correct
- [ ] Early detection confirmed

---

### 0004.14: Test Spend Constraints Edge Cases
**Effort**: M (2h)  
**Dependencies**: 0004.13

**Actions**:
- Test desired amount outside min/max bounds
- Test min = max (single valid amount)
- Test very wide tolerance window
- Add to PathFinderTest or SpendConstraintsTest

**Done When**:
- [ ] Constraint edge case tests added
- [ ] All cases handled correctly
- [ ] All tests pass

---

### 0004.15: Review Visited State Tracking
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Review SearchStateRegistry implementation
- Verify SearchStateSignature uniqueness
- Check for potential cycles
- Verify state count accuracy

**Done When**:
- [ ] State tracking reviewed
- [ ] Signature uniqueness verified
- [ ] Cycle prevention confirmed

---

### 0004.16: Test Visited State Tracking
**Effort**: M (2-3h)  
**Dependencies**: 0004.15

**Actions**:
- Test graph with many paths to same node
- Test that cycles are prevented
- Test same node reached via different costs
- Verify visited states count matches actual
- Add to SearchStateRegistryTest

**Done When**:
- [ ] State tracking tests added
- [ ] Cycle prevention verified
- [ ] Count accuracy verified

---

### 0004.17: Review Acceptance Callback Semantics
**Effort**: S (1-2h)  
**Dependencies**: None

**Actions**:
- Document callback contract (when called, guarantees)
- Verify callback called only after basic validation
- Check callback error handling

**Done When**:
- [ ] Callback contract documented
- [ ] Call timing verified
- [ ] Error handling reviewed

---

### 0004.18: Test Acceptance Callback Edge Cases
**Effort**: M (2h)  
**Dependencies**: 0004.17

**Actions**:
- Test slow callback with time budget (ensure timeout)
- Test callback that always returns false
- Test callback exceptions (if handled)
- Add to PathFinderServiceTest

**Done When**:
- [ ] Callback edge cases tested
- [ ] Timeout behavior verified
- [ ] All tests pass

---

### 0004.19: Add Missing Algorithm Tests
**Effort**: L (3-4h)  
**Dependencies**: 0004.1-0004.18

**Actions**:
- Add adversarial graph tests (designed for worst-case)
- Add boundary condition tests
- Add guard stress tests (tight guards on complex graphs)
- Add metamorphic properties if gaps found
- Document test scenarios

**Done When**:
- [ ] All identified test gaps filled
- [ ] Adversarial cases covered
- [ ] Test suite comprehensive

---

### 0004.20: Document Algorithm Behavior
**Effort**: M (2h)  
**Dependencies**: 0004.1-0004.19

**Actions**:
- Update PathFinder class docblock with algorithm details
- Document tolerance handling
- Document hop enforcement
- Document guard behavior
- Document ordering guarantees

**Done When**:
- [ ] PathFinder docblock comprehensive
- [ ] Algorithm behavior clearly documented
- [ ] All guarantees stated explicitly

---

## 0005: Exception Hierarchy Completeness

### 0005.1: Audit Error Scenarios - Domain Layer
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Review Money for all error scenarios
- Review ExchangeRate for all error scenarios
- Review OrderBounds for all error scenarios
- Review ToleranceWindow for all error scenarios
- Review Order for all error scenarios
- Document which throw, which return null, which fail silently

**Done When**:
- [ ] All domain error scenarios documented
- [ ] Current handling catalogued
- [ ] Inconsistencies identified

---

### 0005.2: Audit Error Scenarios - Application Layer
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Review PathSearchConfig error scenarios
- Review GraphBuilder error scenarios
- Review PathFinder error scenarios
- Review PathFinderService error scenarios
- Review Filter error scenarios
- Document current handling

**Done When**:
- [ ] All application error scenarios documented
- [ ] Current handling catalogued
- [ ] Gaps identified

---

### 0005.3: Establish Exception vs Null Convention
**Effort**: S (1h)  
**Dependencies**: 0005.1, 0005.2

**Actions**:
- Define convention: exceptions for invalid input/invariants, null for optional
- Review current code against convention
- Document convention in docs/exceptions.md (create)
- List any violations to fix

**Done When**:
- [ ] Convention established and documented
- [ ] Current code reviewed against convention
- [ ] Violations listed

---

### 0005.4: Review PathFinderService::findBestPaths() Error Handling
**Effort**: S (1h)  
**Dependencies**: 0005.3

**Actions**:
- Should empty paths throw? (Currently returns empty SearchOutcome)
- Document decision
- Update behavior if needed
- Update docs and tests

**Done When**:
- [ ] Decision made and documented
- [ ] Behavior matches convention
- [ ] Tests updated

---

### 0005.5: Enhance InvalidInput Exception Context
**Effort**: M (2h)  
**Dependencies**: 0005.1, 0005.2

**Actions**:
- Review all InvalidInput throw sites
- Ensure invalid value included in message where safe
- Consider adding structured context methods (e.g., getInvalidValue())
- Update exception construction if needed

**Done When**:
- [ ] All InvalidInput sites reviewed
- [ ] Context enhanced where possible
- [ ] Consistent format used

---

### 0005.6: Enhance PrecisionViolation Exception Context
**Effort**: S (1-2h)  
**Dependencies**: 0005.1, 0005.2

**Actions**:
- Review all PrecisionViolation throw sites
- Ensure message explains operation and why it failed
- Add context: operation type, scale requested, limit
- Update exception construction

**Done When**:
- [ ] All PrecisionViolation sites reviewed
- [ ] Context enhanced
- [ ] Messages actionable

---

### 0005.7: Review GuardLimitExceeded Exception
**Effort**: S (1h)  
**Dependencies**: 0005.2

**Actions**:
- Verify guard report accessible from exception
- Verify message includes actual vs limit values
- Document opt-in nature clearly

**Done When**:
- [ ] Guard report accessible
- [ ] Message format verified
- [ ] Opt-in pattern documented

---

### 0005.8: Review InfeasiblePath Exception Usage
**Effort**: S (1h)  
**Dependencies**: 0005.3, 0005.4

**Actions**:
- Decide: Should PathFinderService throw this?
- Or should it remain user-space exception?
- Document decision in docs/exceptions.md
- Update code/docs accordingly

**Done When**:
- [ ] Decision made
- [ ] Usage documented
- [ ] Code matches decision

---

### 0005.9: Standardize Exception Messages
**Effort**: M (2h)  
**Dependencies**: 0005.5, 0005.6

**Actions**:
- Create message guidelines (what failed, value, fix)
- Review all exception messages
- Update messages for consistency
- Use consistent terminology

**Done When**:
- [ ] Guidelines documented
- [ ] All messages reviewed
- [ ] Messages standardized

---

### 0005.10: Evaluate Additional Exception Types
**Effort**: S (1h)  
**Dependencies**: 0005.1, 0005.2, 0005.3

**Actions**:
- Consider GraphConstructionException
- Consider OrderValidationException
- Consider ConfigurationException
- Decide if needed based on value
- Document decision

**Done When**:
- [ ] Need evaluated for each type
- [ ] Decision made with rationale
- [ ] If added: Exception classes created

---

### 0005.11: Document Exception Behavior
**Effort**: M (2-3h)  
**Dependencies**: 0005.3, 0005.8, 0005.9

**Actions**:
- Create/update docs/exceptions.md
- List all exception types and when thrown
- Provide examples
- Document hierarchy and catch strategies
- Explain guard opt-in pattern
- Clarify exceptions vs empty results

**Done When**:
- [ ] docs/exceptions.md complete
- [ ] All exceptions documented
- [ ] Examples provided
- [ ] Catch strategies shown

---

### 0005.12: Add @throws PHPDoc Tags
**Effort**: M (2h)  
**Dependencies**: 0005.11

**Actions**:
- Add @throws tags to all public methods
- Document all possible exceptions, not just most common
- Verify accuracy
- Update existing @throws tags if wrong

**Done When**:
- [ ] All public methods have @throws tags
- [ ] Tags accurate and complete
- [ ] PHPStan doesn't complain

---

### 0005.13: Add Exception Construction Tests
**Effort**: M (2h)  
**Dependencies**: 0005.9, 0005.10

**Actions**:
- Create ExceptionTest.php if not exists
- Test each exception type construction
- Test message formatting
- Test context availability

**Done When**:
- [ ] Exception construction tests added
- [ ] All exception types covered
- [ ] All tests pass

---

### 0005.14: Add Error Path Tests
**Effort**: M (2-3h)  
**Dependencies**: 0005.1, 0005.2

**Actions**:
- Test every validation that should throw
- Test that invalid state cannot be constructed
- Test error recovery where applicable
- Add to relevant test files

**Done When**:
- [ ] All validation error paths tested
- [ ] Invalid construction attempts tested
- [ ] Tests comprehensive

---

### 0005.15: Verify README Exception Examples
**Effort**: XS (<1h)  
**Dependencies**: 0005.11

**Actions**:
- Review README exception examples
- Verify they match current behavior
- Update if incorrect
- Ensure examples demonstrate best practices

**Done When**:
- [ ] README examples verified
- [ ] Examples accurate
- [ ] Best practices demonstrated

---

## Continue with remaining tasks...

Due to length constraints, I'll create a separate file for tasks 0006-0014 breakdown. Would you like me to continue with those, or would you prefer to start implementing from the breakdowns above first?

