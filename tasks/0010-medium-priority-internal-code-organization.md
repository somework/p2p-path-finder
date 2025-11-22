# Task: Internal Code Organization and Architecture Cleanup

## Context

The codebase has a clear domain/application layer split:
- **Domain**: `src/Domain/Order/`, `src/Domain/ValueObject/`
- **Application**: `src/Application/Config/`, `src/Application/Service/`, `src/Application/PathFinder/`, etc.
- **Exceptions**: `src/Exception/`

Current structure is generally clean, but internal organization can be improved for:
- Consistency
- Discoverability
- Maintainability
- Logical grouping

This is a P3 task (internal refactoring, not affecting public API) but worth doing before 1.0 to establish good patterns.

## Problem

**Organizational concerns:**

1. **Directory structure depth**:
   - `src/Application/PathFinder/Result/Ordering/` is 4 levels deep
   - `src/Application/PathFinder/Result/Heap/` is 4 levels deep
   - `src/Application/PathFinder/Search/` is 3 levels deep
   - Is this depth necessary or can it be flattened?

2. **Naming consistency**:
   - Some classes prefixed with domain (`PathResult`, `SearchOutcome`)
   - Some classes suffixed with type (`OrderFilterInterface`, `PathOrderStrategy`)
   - Some classes standalone (`Money`, `Order`)
   - Is there a consistent naming convention?

3. **File organization**:
   - Graph components spread across `Application/Graph/` and `Application/PathFinder/ValueObject/` (e.g., `PathEdge`, `PathEdgeSequence`)
   - Should graph-related VOs be in Graph/ namespace?

4. **Trait usage**:
   - `DecimalHelperTrait` in `Domain/ValueObject/`
   - `SerializesMoney` in `Application/Support/`
   - Are traits overused? Should they be static classes instead?

5. **Value object proliferation**:
   - Many small value objects in `Application/PathFinder/ValueObject/`
   - Are they all necessary or could some be combined?
   - Examples: `PathEdge`, `PathEdgeSequence`, `SpendConstraints`, `SpendRange`, `CandidatePath`

6. **Service organization**:
   - `Application/Service/` has main services + helpers
   - Helpers are marked `@internal`
   - Should internal services be in separate namespace? (e.g., `Application/Internal/Service/`)

7. **Result objects**:
   - `Application/Result/` has `PathResult`, `PathLeg`, `MoneyMap`, `PathResultFormatter`
   - `Application/PathFinder/Result/` has `SearchOutcome`, `SearchGuardReport`, `PathResultSet`, etc.
   - Should these be unified under one Result namespace?

8. **Redundant abstractions**:
   - Are there classes that could be simplified?
   - Are there unnecessary indirections?
   - Are there over-engineered patterns?

## Proposed Changes

### 1. Review and document namespace organization

**Create docs/architecture/namespaces.md**:

Document the current namespace structure and rationale:
- **Domain**: Pure domain model, no application logic
- **Application/Config**: Configuration DTOs
- **Application/Service**: Public and internal services
- **Application/PathFinder**: Internal search engine (marked `@internal`)
- **Application/Graph**: Graph representation
- **Application/Filter**: Order filtering
- **Application/OrderBook**: Order book management
- **Application/Result**: Public result objects
- **Application/Support**: Support utilities
- **Exception**: All library exceptions

Decide if current structure is optimal or needs changes

### 2. Evaluate directory depth

**Current deep paths**:
- `Application/PathFinder/Result/Ordering/` → 4 levels
- `Application/PathFinder/Result/Heap/` → 4 levels
- `Application/PathFinder/Search/` → 3 levels
- `Application/PathFinder/ValueObject/` → 3 levels

**Options**:
1. **Keep as-is** - depth is fine for large codebases
2. **Flatten to 2-3 levels** - e.g., `Application/PathFinder/` and `Application/PathFinderSearch/`
3. **Extract internal** - e.g., `Internal/PathFinder/` to clearly separate

**Recommendation**: Keep as-is for now (it's marked `@internal` anyway), but document the structure. Avoid going deeper than 4 levels.

### 3. Unify result object namespaces

**Current split**:
- `Application/Result/` - public results (PathResult, PathLeg, MoneyMap, PathResultFormatter)
- `Application/PathFinder/Result/` - internal results (SearchOutcome, PathResultSet, SearchGuardReport, Ordering, Heap)

**Decision points**:
- Is `SearchOutcome` public or internal? (It's returned by PathFinderService, so public)
- Is `PathResultSet` public or internal? (It's part of SearchOutcome, so public)
- Is `SearchGuardReport` public or internal? (It's part of SearchOutcome, so public)

**If they're public, should they move to `Application/Result/`?**

**Consideration**: Moving would be a BC break if classes are referenced by FQCN

**Recommendation**: 
- For 1.0: Document the split and mark clearly which are public
- For 2.0: Consider consolidation if it adds value

### 4. Review trait usage

**Current traits**:
- `DecimalHelperTrait` - provides BigDecimal utilities
- `SerializesMoney` - provides Money serialization

**Trait vs static class trade-offs**:
- **Traits**: Can access private properties, but harder to test, unclear dependencies
- **Static classes**: Explicit dependencies, easier to test, but can't access private state

**Evaluate**:
- `DecimalHelperTrait`: Used by value objects, needs access to constants. **Keep as trait.**
- `SerializesMoney`: Used by result objects, doesn't need private access. **Could be static class.**

**Recommendation**: 
- Keep `DecimalHelperTrait` as trait
- Consider making `SerializesMoney` a static helper class (not critical)

### 5. Review value object necessity

**PathFinder value objects**:
- `CandidatePath` - used internally by PathFinder
- `PathEdge`, `PathEdgeSequence` - represent path structure
- `SpendConstraints`, `SpendRange` - represent spend bounds

**Questions**:
- Are `SpendConstraints` and `SpendRange` redundant? (One wraps the other)
- Should `PathEdge` be in Graph namespace instead?
- Is `CandidatePath` necessary or could search use `PathResult` directly?

**Recommendation**: Review in detail but don't force consolidation - small VOs aid clarity

### 6. Consider internal namespace

**Internal classes** (marked `@internal`):
- PathFinder
- OrderSpendAnalyzer
- LegMaterializer
- ToleranceEvaluator
- SearchState*, Search*, etc.

**Options**:
1. Keep as-is with `@internal` annotations
2. Move to `Internal/` namespace to make it obvious
3. Split into separate package (over-engineering)

**Recommendation**: Keep as-is. `@internal` annotation is sufficient. Moving would be a big refactor for marginal benefit.

### 7. Consolidate helper classes

**Support/helper classes**:
- `Application/Support/OrderFillEvaluator`
- `Application/Support/SerializesMoney`
- Are there more that should be in Support/?

**Review**:
- Should internal services like `LegMaterializer` be in Support/ instead of Service/?
- Or should Support/ be renamed to Internal/?

**Recommendation**: 
- `Support/` for utilities that have no dependencies
- `Service/` for services that orchestrate logic (even if internal)

### 8. Remove redundant abstractions

**Review for over-engineering**:
- Are there interfaces with only one implementation?
- Are there abstract classes that are never extended?
- Are there factory patterns where direct instantiation would suffice?

**Current interfaces**:
- `OrderFilterInterface` - multiple implementations ✓
- `PathOrderStrategy` - extension point ✓
- `FeePolicy` - extension point ✓
- `ExceptionInterface` - marker interface ✓

**Looks good** - no unnecessary abstractions observed

### 9. Add package-level documentation

**Create src/README.md** (or docs/architecture/overview.md):

Document the overall architecture:
- Layer separation (Domain vs Application)
- Public API vs Internal classes
- Key design patterns used
- Data flow overview
- Extension points

This helps new contributors understand the structure

### 10. Consider splitting large classes

**Review class sizes**:
```bash
find src -name "*.php" -exec wc -l {} \; | sort -rn | head -20
```

**If any classes are > 500 lines**:
- Are they doing too much?
- Can they be split?
- Are there private methods that could be extracted?

**Known large classes**:
- `PathFinder` - core algorithm, likely large
- `PathFinderService` - facade, might be large
- `PathSearchConfig` - configuration, might be large

**Review and decide if splitting adds value**

## Dependencies

- Should be done after task 0001 (Public API finalization) to avoid affecting public surface
- Complements task 0007 (Documentation) with architecture docs

## Effort Estimate

**M** (0.5-1 day)
- Namespace documentation: 1-2 hours
- Directory depth evaluation: 1 hour
- Result namespace analysis: 1 hour
- Trait usage review: 1 hour
- Value object review: 1-2 hours
- Internal namespace consideration: 1 hour
- Helper consolidation: 1 hour
- Abstraction review: 1 hour
- Architecture documentation: 2 hours
- Large class review: 1 hour

**Note**: This is mostly analysis and documentation, not heavy refactoring

## Risks / Considerations

- **Refactoring risk**: Moving classes is a BC break, must be avoided pre-1.0
- **Over-optimization**: Don't reorganize for the sake of it
- **Consistency vs pragmatism**: Perfect consistency might not be worth the effort
- **Documentation drift**: Architecture docs need maintenance

**Approach**: 
- Focus on documentation and small improvements
- Defer major reorganization to 2.0 if needed
- Prioritize clarity over perfection

## Definition of Done

- [ ] Namespace organization documented in docs/architecture/namespaces.md
- [ ] Directory depth evaluated and documented
- [ ] Result object namespaces analyzed (decision: keep as-is or consolidate)
- [ ] Trait usage reviewed (decision documented)
- [ ] Value object necessity reviewed
- [ ] Internal namespace decision documented
- [ ] Helper class consolidation reviewed
- [ ] Redundant abstractions reviewed (none found or removed)
- [ ] Architecture overview documented
- [ ] Large class analysis completed
- [ ] All decisions documented with rationale
- [ ] No BC breaks introduced
- [ ] All tests still pass
- [ ] PHPStan/Psalm still pass

**Priority:** P3 – Nice to have

