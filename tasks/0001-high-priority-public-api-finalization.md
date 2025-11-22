# Task: Public API Surface Finalization and Documentation

## Context

The library is approaching its 1.0.0-rc release. The public API includes:
- `PathFinderService` - primary facade
- `PathSearchRequest` - DTO for search requests
- `PathSearchConfig` and `PathSearchConfigBuilder` - configuration
- `SearchOutcome`, `PathResult`, `PathResultSet` - results
- `PathOrderStrategy` interface - custom ordering extension point
- `OrderFilterInterface` - custom filtering extension point
- `FeePolicy` interface - custom fee calculation
- Domain value objects (`Money`, `ExchangeRate`, `OrderBounds`, etc.)

Internal components are marked with `@internal` annotations (PathFinder, OrderSpendAnalyzer, LegMaterializer, ToleranceEvaluator).

The API needs final review to ensure:
- All public surface is intentional, minimal and coherent
- Internal details don't leak into public types
- Extension points are clear and sufficient
- Breaking changes are identified before 1.0

## Problem

**Current risks:**
1. **Constructor exposure**: `PathFinderService::withRunnerFactory()` is marked `@internal` but is public - this creates ambiguity about whether it's truly part of the supported API or test-only.
2. **Value object exposure in internal types**: Some internal types (e.g., `CandidatePath`, `SpendConstraints`) are exposed through callback signatures. Verify these are appropriately scoped.
3. **Missing extension points**: Currently only `OrderFilterInterface`, `PathOrderStrategy`, and `FeePolicy` are extension points. Are there other areas where consumers might need customization (e.g., custom graph builders, edge weight calculation, segment pruning logic)?
4. **Inconsistent terminology**: Some types use "Spend", others "Amount" - ensure terminology is consistent across public API.
5. **API discoverability**: README documents the main entry points but doesn't provide clear guidance on which classes/interfaces are stable vs internal.
6. **Serialization contracts**: `PathResult`, `PathLeg`, `SearchGuardReport` implement `JsonSerializable` but the JSON schema isn't formally documented. This is a stability contract for API consumers.

## Proposed Changes

### 1. Review and finalize public surface area

- **Action**: Create a comprehensive list of all public classes, interfaces, and methods that constitute the stable API
- **Review** `PathFinderService::withRunnerFactory()`: 
  - Either move to a separate test helper class or add explicit `@internal` + documentation that it's not part of stable API
  - Consider if test extension is truly needed or if default factory is sufficient for all use cases
- **Review** `SpendConstraints` exposure: Currently exposed via `CandidatePath` in the acceptance callback. Ensure this is intentional and document as part of public API if retained
- **Review** all `public` methods in domain/application layers: Ensure nothing intended as internal is accidentally public

### 2. Formalize extension point contracts

- **Document** extension interfaces with clear contracts:
  - `OrderFilterInterface::accept()` - document expectations, performance characteristics
  - `PathOrderStrategy::compare()` - document stable sort requirements, determinism expectations
  - `FeePolicy` - document calculation order, currency constraints
- **Add** example implementations for each extension point in `examples/` directory
- **Consider** additional extension points:
  - Custom graph representation strategy (if graph structure becomes pluggable)
  - Custom segment capacity evaluator
  - Custom tolerance evaluator strategies

### 3. Document JSON serialization contracts

- **Create** JSON schema or clear documentation for:
  - `PathResult::jsonSerialize()` structure
  - `SearchOutcome::jsonSerialize()` structure  
  - `SearchGuardReport::jsonSerialize()` structure
  - `Money` serialization format (currency, amount, scale)
- **Add** to docs/api-contracts.md with version compatibility promises
- **Add** test coverage for JSON serialization stability (detect breaking changes)

### 4. Audit public constructors and factory methods

- **Review** which value objects should use private constructors + factory methods vs public constructors
- **Ensure** all public factories validate input and throw appropriate exceptions
- **Document** when to use `fromString()` vs direct construction (if both exist)

### 5. Create API stability documentation

- **Create** docs/api-stability.md covering:
  - What is considered public API (explicit list)
  - What is internal (list of `@internal` types)
  - Semantic versioning promises for 1.0+
  - Deprecation policy
  - BC break process

### 6. Add @api annotations

- **Add** `@api` PHPDoc tag to all truly stable public methods/classes
- **Ensure** PHPDoc generator (bin/generate-phpdoc.php) filters based on both `@api` and absence of `@internal`

## Dependencies

- None - this is a foundational task that should be completed early

## Effort Estimate

**M** (0.5-1 day)
- API surface audit: 2-3 hours
- Documentation creation: 2-3 hours  
- Example implementations: 1-2 hours
- JSON schema documentation: 1 hour

## Risks / Considerations

- **BC concerns**: Some changes might require marking current methods as `@deprecated` rather than immediate removal
- **Over-engineering risk**: Don't create extension points speculatively - only add what has clear use cases
- **Documentation maintenance**: JSON schemas need to stay in sync with implementation - consider adding tests that validate schema matches actual output

## Definition of Done

- [ ] Complete list of public API classes/interfaces/methods documented in docs/api-stability.md
- [ ] All `@internal` types clearly marked and documented as unstable
- [ ] `PathFinderService::withRunnerFactory()` moved to test helper or documented as test-only internal method
- [ ] Extension point interfaces (OrderFilterInterface, PathOrderStrategy, FeePolicy) fully documented with examples
- [ ] JSON serialization contracts documented in docs/api-contracts.md
- [ ] Tests added to verify JSON serialization structure doesn't break accidentally
- [ ] `@api` annotations added to stable public methods
- [ ] README updated to reference API stability documentation
- [ ] PHPStan/Psalm pass with no new errors
- [ ] All existing tests pass

**Priority:** P1 – Release-blocking

