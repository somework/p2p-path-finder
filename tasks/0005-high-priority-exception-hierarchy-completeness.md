# Task: Exception Hierarchy Review and Error Handling Completeness

## Context

The library defines a custom exception hierarchy under `SomeWork\P2PPathFinder\Exception`:
- `ExceptionInterface` - marker interface for all library exceptions
- `InvalidInput` - invalid configuration or input data
- `PrecisionViolation` - arithmetic operations that cannot be represented within precision
- `GuardLimitExceeded` - search guard limits breached (opt-in)
- `InfeasiblePath` - no route satisfies constraints

Current usage:
- Domain value objects throw `InvalidInput` for validation failures
- PathFinder can throw `GuardLimitExceeded` if configured
- Guards are reported via metadata by default, exception is opt-in
- README demonstrates catch strategies (fine-grained vs coarse)

## Problem

**Exception design risks:**
1. **Incomplete coverage**: Are there error scenarios that should throw exceptions but currently fail silently or return null?
2. **Exception vs return value**: Current pattern mixes exceptions (for invalid input) with nullable returns (for not-found scenarios). Is this consistent?
3. **Context in exceptions**: Do all exceptions carry enough information for debugging?
   - InvalidInput: Does it include the invalid value?
   - PrecisionViolation: Does it explain what operation failed?
   - GuardLimitExceeded: Does it include actual vs limit values?
4. **Exception messages**: Are they actionable? Do they guide users to fix the issue?
5. **Backwards compatibility**: Are exception types stable for 1.0? Any that should be split or merged?
6. **Missing exception types**: Should there be:
   - `GraphConstructionException` for graph building failures?
   - `ConversionException` for exchange rate issues?
   - `OrderValidationException` separate from `InvalidInput`?
7. **Error recovery**: Can callers recover from exceptions or are they terminal?
8. **Guard exception contract**: The opt-in nature of `GuardLimitExceeded` is unusual. Is this clearly documented?

## Proposed Changes

### 1. Audit all error scenarios

Walk through the codebase and identify all error conditions:

**Domain Layer:**
- Money: Invalid currency, invalid amount, invalid scale, arithmetic errors
- ExchangeRate: Invalid currencies, invalid rate, conversion errors
- OrderBounds: Invalid bounds, currency mismatch
- ToleranceWindow: Invalid tolerances
- Order: Consistency validation failures

**Application Layer:**
- PathSearchConfig: Invalid configuration (hops, guards, tolerance)
- GraphBuilder: Order processing failures
- PathFinder: Search failures, guard breaches
- PathFinderService: Empty order book, no graph, no paths
- Filters: Order filtering issues

**Current handling:**
- Which return `null`?
- Which throw exceptions?
- Which fail silently?
- Are there `TODO` or `FIXME` comments about error handling?

### 2. Review exception vs null return patterns

**Establish convention:**
- **Exceptions**: Invalid input, violated invariants, unrecoverable errors
- **Null returns**: Optional values, not-found scenarios that are valid
- **Empty collections**: No results (not an error)

**Audit for consistency:**
- Should `PathFinderService::findBestPaths()` throw when no paths found? (Currently returns empty `SearchOutcome`)
- Should `Graph::node()` throw when node not found? (Currently returns null - this is fine for optional access)
- Should `OrderSpendAnalyzer::determineInitialSpendAmount()` throw or return null? (Currently null - review)

### 3. Enhance exception context

Review each exception throw site:

**InvalidInput:**
- Include the invalid value in message: `sprintf('Invalid currency "%s" supplied.', $currency)` ✓ (already good)
- For complex validation, explain what's wrong: "Tolerance window produces inverted spend bounds" ✓ (already good)
- Consider adding structured context via exception properties (e.g., `getInvalidValue()`)

**PrecisionViolation:**
- Currently thrown from `DecimalHelperTrait` and value objects
- Does message explain what operation failed and why?
- Add context: operation type, scale requested, precision limit

**GuardLimitExceeded:**
- Currently formatted by PathFinderService with actual values ✓ (already good)
- Ensure guard report is accessible from exception

**InfeasiblePath:**
- Currently only mentioned in README example, not thrown by library
- Should PathFinderService throw this? Or is empty result sufficient?
- Consider adding with context about why infeasible

### 4. Standardize exception messages

**Create guidelines:**
- Start with what failed: "Currency cannot be empty."
- Include invalid value when safe: "Invalid currency \"XY\" supplied."
- Suggest fix when possible: "Scale cannot exceed 50 decimal places."
- Use consistent terminology (asset vs currency, amount vs value)

**Audit existing messages** for consistency

### 5. Document exception behavior

**Update docs/exceptions.md** (or create if missing):
- List all exception types and when they're thrown
- Provide examples of each exception
- Document exception hierarchy and catch strategies
- Explain guard exception opt-in pattern
- Clarify when to expect exceptions vs empty results

**Update PHPDoc:**
- All public methods should have `@throws` tags
- Document all possible exceptions, not just most common

### 6. Consider additional exception types

**Evaluate need for:**
- `GraphConstructionException` - if graph building has special error cases
- `OrderValidationException` - if order validation needs separation from generic InvalidInput
- `ConfigurationException` - if PathSearchConfig validation is complex enough

**Decision criteria:**
- Does it help callers handle the error differently?
- Does it clarify the source of the error?
- Does it avoid overloading existing exceptions?

**Recommendation**: Start conservative, only add if there's clear value

### 7. Add exception tests

**For each exception type:**
- Test construction with appropriate context
- Test message formatting
- Test that correct exception is thrown in error scenarios
- Test catch strategies from README examples

**Add error path tests:**
- Test every validation that should throw
- Test that invalid state cannot be constructed
- Test error recovery scenarios (where applicable)

### 8. Review InfeasiblePath usage

Currently only mentioned in README example but not thrown by library:
```php
if (!$resultOutcome->hasPaths()) {
    throw new InfeasiblePath('No viable routes found.');
}
```

**Decide:**
- Should this be thrown by PathFinderService?
- Should it be removed from the exception hierarchy?
- Should it remain as a user-space exception for consumers to throw?

**Recommendation**: Document that empty SearchOutcome is not an error; InfeasiblePath is available for consumers who want to treat it as exceptional

## Dependencies

- Interacts with task 0001 (Public API) - exception types are part of public contract
- Interacts with task 0002 (Domain model) - validation exceptions

## Effort Estimate

**M** (0.5-1 day)
- Error scenario audit: 2-3 hours
- Exception message review and standardization: 2-3 hours
- Documentation: 1-2 hours
- Test implementation: 1-2 hours

## Risks / Considerations

- **Breaking changes**: Changing when exceptions are thrown vs returning null could break consumers
- **Over-engineering**: Adding too many exception types can complicate error handling
- **Message changes**: Even improving exception messages is technically a BC break for tests that assert on messages
- **Exception vs result**: Overusing exceptions for flow control is an anti-pattern; ensure exceptions are for exceptional cases

## Definition of Done

- [ ] All error scenarios identified and categorized (exception vs null vs empty collection)
- [ ] Exception vs null return pattern is consistent across codebase
- [ ] All exception throw sites reviewed for adequate context
- [ ] Exception messages standardized and actionable
- [ ] docs/exceptions.md created or updated with complete exception documentation
- [ ] All public methods have @throws PHPDoc tags
- [ ] Additional exception types evaluated (added only if clearly beneficial)
- [ ] Exception construction tests added
- [ ] Error path tests added for all validation scenarios
- [ ] InfeasiblePath usage documented clearly
- [ ] README exception examples verified to be accurate
- [ ] PHPStan/Psalm pass
- [ ] All tests pass

**Priority:** P1 – Release-blocking

