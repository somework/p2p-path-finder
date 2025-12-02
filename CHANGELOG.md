# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **Major namespace refactoring**: Reorganized entire codebase for better structure and maintainability
  - `Application/Graph/` → `Application/PathSearch/Model/Graph/`
  - `Application/PathFinder/` → `Application/PathSearch/`
  - `Domain/ValueObject/` → `Domain/Money/`, `Domain/Tolerance/`, `Domain/Order/`
  - `Application/Service/` → `Application/PathSearch/Service/`
  - Updated all imports and references across 78 source files and 173 test files
- **Cleaned up domain models**: Removed serialization interfaces for cleaner, more focused domain objects
  - Eliminated `JsonSerializable` implementations from core classes
  - Removed serialization-specific traits and logic
  - Updated examples to use direct object APIs

### Breaking Changes
- **Namespace changes**: All public class namespaces have changed (breaking change for library consumers)
- **Simplified APIs**: Classes now provide direct object access methods

### Migration Guide
- Update all import statements to use new namespace paths
- Use direct object API methods for accessing data
- See updated documentation for new API usage patterns

## [0.1.0] - TBD

**First tagged pre-release** - This version represents the initial BigDecimal migration and establishes the foundation for the 1.0.0 stable release.

### Added
- **Core Pathfinding Engine**:
  - `PathFinderService` for finding optimal currency conversion paths
  - `PathFinder` algorithm with Dijkstra-like search
  - `GraphBuilder` for order book graph construction
  - `SearchGuards` for resource limit enforcement (expansions, visited states, time budget)
  
- **Domain Model**:
  - `Money` value object with arbitrary precision (Brick\Math\BigDecimal)
  - `ExchangeRate` value object with deterministic decimal arithmetic
  - `Order` entity for buy/sell orders
  - `OrderBounds` for order amount constraints
  - `ToleranceWindow` for acceptable conversion slippage
  - `AssetPair` for currency pair representation
  
- **Configuration**:
  - `PathSearchConfig` with builder pattern
  - `PathSearchRequest` DTO for service requests
  - Configurable tolerance bounds, hop limits, result limits
  - Guard limits (expansion limit, visited state limit, time budget)
  
- **Extensibility**:
  - `OrderFilterInterface` for custom order filtering
  - `PathOrderStrategy` for custom path ranking
  - `FeePolicy` interface for custom fee calculations
  - Built-in filters: `MinimumAmountFilter`, `MaximumAmountFilter`, `ToleranceWindowFilter`, `CurrencyPairFilter`
  - Built-in fee policies: `NoFeePolicy`, `PercentageFeePolicy`, `FixedFeePolicy`, `TieredFeePolicy`, `MakerTakerFeePolicy`
  
- **Results**:
  - `SearchOutcome` with paths and guard reports
  - `PathResult` with detailed path information
  - `PathLeg` for individual hop details
  - `SearchGuardReport` for diagnostics
  - Object APIs for all result types
  
- **Exception Hierarchy**:
  - `InvalidInput` for domain validation errors
  - `GuardLimitExceeded` for resource exhaustion
  - `PrecisionViolation` for arithmetic precision issues
  - `InfeasiblePath` for impossible path constraints
  
- **Documentation**:
  - Comprehensive README with quick start and examples
  - Getting Started Guide (step-by-step tutorial)
  - Troubleshooting Guide (common issues and solutions)
  - Architecture Guide (7 Mermaid diagrams, 943 lines)
  - API Contracts (object API specification)
  - API Stability Guide (public API guarantees)
  - Domain Invariants (value object constraints)
  - Decimal Strategy (arbitrary precision policy)
  - Memory Characteristics (optimization guide)
  - Exception Handling Guide (catch strategies)
  - Versioning Policy (SemVer, BC breaks, deprecation)
  - Release Process (pre-release checklist, hotfix procedures)
  - Support Policy (PHP/library version support timelines)
  
- **Examples**:
  - `guarded-search-example.php` - Complete workflow demonstration
  - `custom-order-filter.php` - 4 filter implementations
  - `custom-ordering-strategy.php` - 3 ordering strategies
  - `custom-fee-policy.php` - 5 fee policy implementations
  - `error-handling.php` - Comprehensive error handling patterns
  - `performance-optimization.php` - 4 optimization techniques with benchmarks
  - `examples/README.md` - Complete examples documentation
  
- **Testing**:
  - Unit tests for all domain objects
  - Integration tests for PathFinderService
  - Property-based tests for Money and ExchangeRate
  - Algorithm correctness tests (ordering determinism, guard enforcement)
  - Edge case tests (tolerance, hop limits, mandatory segments)
  - Stress tests (large graphs, adversarial scenarios)
  - Test coverage > 90%
  
- **Quality Tools**:
  - PHPStan level max with custom rules
  - PHP CS Fixer for code style
  - Psalm for additional static analysis
  - Infection for mutation testing
  - Custom PHPStan rules for decimal arithmetic enforcement
  
- **CI/CD**:
  - Automated tests on PHP 8.2 and 8.3
  - Static analysis checks
  - Code style checks
  - Example validation
  - Custom PHPStan rules verification
  
- **Community**:
  - Contributing guide
  - Code of conduct
  - Security policy
  - Issue templates
  - Pull request templates
  
- **Composer**:
  - Example runner scripts (`composer examples`)
  - Test runner script (`composer phpunit`)
  - Quality check scripts (`composer check`, `composer check:full`)
  - Individual tool scripts (phpstan, psalm, php-cs-fixer, etc.)

### Changed
- **BigDecimal Migration**: All arithmetic migrated from BCMath to `Brick\Math\BigDecimal` for deterministic precision
- **Value Object API**: Value objects now expose decimal accessors for internal precision control
- **PathFinder API**: `findBestPaths()` now uses `SpendConstraints` and returns `CandidatePath` instances (replacing associative arrays)
- **Service API**: `PathFinderService::findBestPaths()` accepts `PathSearchRequest` DTO (encapsulating order book, config, target asset)
- **Constructor Simplification**: `PathFinderService` constructor simplified to only require `GraphBuilder` and optional `PathOrderStrategy`
- **SpendConstraints API**: `bounds()` method replaces `SpendRange` exposure; `internalRange()` for implementation details
- **Documentation**: README clarifies supported extension points (removed custom `pathFinderFactory` recommendation)

### Deprecated
- Nothing (this is the first release)

### Removed
- BCMath arithmetic functions (replaced by BigDecimal)
- Legacy associative array payloads in `PathFinder` (replaced by value objects)
- Multi-argument `PathFinderService` constructor (simplified to 1-2 arguments)

### Fixed
- Floating-point precision issues (eliminated by BigDecimal migration)
- Rounding inconsistencies in Money and ExchangeRate calculations
- Edge cases in tolerance amplifier calculations

### Security
- No security issues to report (this is the first release)

---

## Version History Format

Each version should follow this structure:

```markdown
## [X.Y.Z] - YYYY-MM-DD

### Added
- New features, functionality, or capabilities

### Changed
- Changes to existing functionality (backward compatible in MINOR versions)

### Deprecated
- Features marked for removal in future versions

### Removed
- Features removed in this version (requires MAJOR version bump)

### Fixed
- Bug fixes

### Security
- Security vulnerability fixes
```

### Category Guidelines

- **Added**: New features, classes, methods, or configuration options
- **Changed**: Modifications to existing functionality (BC breaks require MAJOR version)
- **Deprecated**: Features that will be removed in a future version (minimum 1 MINOR version warning)
- **Removed**: Features that have been deleted (always requires MAJOR version bump)
- **Fixed**: Bug fixes that restore intended behavior
- **Security**: Security vulnerability fixes (always released immediately as hotfix)

### Version Comparison Links

[Unreleased]: https://github.com/somework/p2p-path-finder/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/somework/p2p-path-finder/releases/tag/v0.1.0
