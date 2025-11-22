# Task: Semantic Versioning Readiness and Release Preparation

## Context

The library is approaching 1.0.0-rc release, as indicated by:
- CHANGELOG.md references "1.0.0-rc milestone"
- Release checklist exists (docs/release-checklist.md)
- Breaking changes tracked in CHANGELOG under "Unreleased"
- Community documentation prepared (CONTRIBUTING, SECURITY, CODE_OF_CONDUCT)
- BigDecimal migration completed

Current package metadata (composer.json):
- No version tag (handled by git tags)
- Package type: "library"
- Keywords: path-finding, p2p, exchange, graph, routing
- Homepage, support, funding links present
- License: Not specified in composer.json (should check LICENSE file)

Semantic versioning for 1.0+ requires:
- Clear public API definition
- Documented BC break policy
- Versioning scheme (semver.org)
- Deprecation policy
- Release process
- Support policy

## Problem

**Release readiness gaps:**

1. **Version definition**:
   - No version currently tagged
   - No version in composer.json (correct for libraries)
   - No documented versioning scheme
   - When is 1.0.0 vs 1.0.0-rc1 appropriate?

2. **Public API contract**:
   - API surface identified (README) but not formally documented as "stable"
   - Internal APIs marked @internal but stability promise not explicit
   - Need formal list of "this is stable, that is not"

3. **BC break policy**:
   - No documented policy for what constitutes a BC break
   - No policy for how BC breaks are communicated
   - No policy for deprecation timeline

4. **Release process**:
   - Release checklist exists but minimal
   - No documented release process
   - No branching strategy documented (main only? release branches?)
   - No documented hotfix process

5. **Support policy**:
   - SECURITY.md covers security fixes ("main branch and released tags when feasible")
   - No general bug fix support policy
   - No LTS policy (if applicable)
   - No documented PHP version support lifecycle

6. **Dependency management**:
   - PHP ^8.2 requirement - will 8.3, 8.4 be supported? For how long?
   - brick/math ^0.12.3 - will updates be allowed? (e.g., 0.13, 1.0)
   - No documented dependency update policy

7. **CHANGELOG structure**:
   - Currently only "Unreleased" section
   - Needs versioned sections once releases begin
   - Should follow Keep a Changelog format (already mentioned)
   - Need examples of what goes in Added, Changed, Fixed, etc.

8. **License clarity**:
   - LICENSE file exists but not specified in composer.json
   - Should add "license" field to composer.json

## Proposed Changes

### 1. Define versioning scheme

**Create docs/versioning.md**:

Document semantic versioning compliance:
- MAJOR: BC breaks to public API
- MINOR: New features, BC additions
- PATCH: Bug fixes, no new features

Define what is "public API":
- All classes/methods not marked @internal
- All interfaces
- JSON serialization structure
- Exception types
- Command-line tools (if any)

Define what is NOT public API:
- Internal classes marked @internal
- Private/protected methods
- Internal data structures
- Performance characteristics (not guaranteed, only targeted)

Define pre-1.0 vs post-1.0 semantics:
- Pre-1.0: Breaking changes allowed in minor versions
- Post-1.0: Breaking changes require major version bump

### 2. Document BC break policy

**Add to docs/versioning.md**:

What constitutes a BC break:
- Removing public classes/methods
- Changing method signatures (param types, return types)
- Changing exception types thrown
- Changing JSON serialization structure
- Renaming classes/methods
- Making strict changes (adding types where none existed)

What is NOT a BC break:
- Adding new public methods
- Adding optional parameters with defaults
- Throwing more specific exceptions (if they extend existing)
- Improving error messages
- Performance improvements
- Adding @internal classes
- Changes to classes marked @internal

How BC breaks are communicated:
- Documented in CHANGELOG under "Breaking"
- Major version bump
- Migration guide provided
- Deprecated alternatives provided before removal (when possible)

### 3. Define deprecation policy

**Add to docs/versioning.md**:

Deprecation process:
1. Mark as @deprecated with version and alternative
2. Document in CHANGELOG under "Deprecated"
3. Keep for at least 1 major version
4. Remove in next major version
5. Document removal in CHANGELOG under "Removed"

Example:
```php
/**
 * @deprecated since 1.2.0, use PathSearchConfig::builder() instead. Will be removed in 2.0.
 */
public function createConfig(...) { }
```

### 4. Document release process

**Create docs/release-process.md**:

**Pre-release checklist** (expand existing docs/release-checklist.md):
- [ ] All tests pass (PHPUnit, PHPStan, Psalm, Infection, PhpBench)
- [ ] Run guarded-search-example.php and verify output
- [ ] Update CHANGELOG.md with version and date
- [ ] Update README.md if needed
- [ ] Update documentation version references
- [ ] Run composer validate
- [ ] Review git diff for unintended changes

**Release steps**:
1. Create release branch: `release/v1.0.0`
2. Update CHANGELOG.md: Move "Unreleased" to "[1.0.0] - 2025-XX-XX"
3. Commit: `git commit -m "Prepare 1.0.0 release"`
4. Tag: `git tag -s v1.0.0 -m "Release 1.0.0"`
5. Push: `git push origin v1.0.0`
6. Create GitHub release with CHANGELOG excerpt
7. Merge release branch to main
8. Create new "Unreleased" section in CHANGELOG

**Post-release steps**:
1. Verify Packagist updates automatically
2. Announce in relevant channels
3. Monitor for issues
4. Prepare hotfix process if needed

**Hotfix process**:
1. Create hotfix branch from tag: `hotfix/v1.0.1`
2. Fix issue
3. Update CHANGELOG
4. Follow release steps for 1.0.1
5. Merge back to main

### 5. Document support policy

**Create docs/support.md**:

**PHP version support**:
- PHP 8.2: Supported until 8.2 reaches end-of-life (Dec 2025)
- PHP 8.3: Supported
- PHP 8.4: Will be tested and supported once stable
- Policy: Support all active PHP versions, drop support for EOL versions in next minor release

**Bug fix support**:
- Latest stable release: Full support
- Previous minor: Security fixes only
- Older versions: No support, upgrade recommended

**Security fix support**:
- Latest major version: All releases receive security fixes
- Previous major: 6 months of security fixes after new major release
- Older majors: No support

**Dependency updates**:
- brick/math: Minor and patch updates allowed without major version bump
- PHP: New PHP versions supported in minor releases
- Dev dependencies: Updated regularly, not part of API contract

### 6. Update composer.json

**Add license field**:
```json
{
  "license": "MIT"
}
```
(Adjust based on actual LICENSE file)

**Verify other metadata**:
- name, description, type, keywords: ✓ (looks good)
- homepage, support, funding: ✓ (present)
- authors: ✓ (present)

**Consider adding**:
```json
{
  "version": "dev-main"
}
```
(Only if not using git tags exclusively - probably not needed)

### 7. Structure CHANGELOG for versioning

**Update CHANGELOG.md**:

Add version section structure:
```markdown
## [Unreleased]

### Added
- New features

### Changed
- Changes to existing functionality

### Deprecated
- Soon-to-be removed features

### Removed
- Removed features

### Fixed
- Bug fixes

### Security
- Security updates

## [1.0.0] - YYYY-MM-DD
### Added
- Initial stable release
...

[Unreleased]: https://github.com/somework/p2p-path-finder/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/somework/p2p-path-finder/releases/tag/v1.0.0
```

**Decide**: Should current "Unreleased" content become "1.0.0" or "1.0.0-rc1"?

Recommendation: 
- 1.0.0-rc1 for release candidate
- 1.0.0 after RC testing period with no major issues

### 8. Create migration guide template

**Create docs/upgrading.md**:

Template for version upgrade guides:
```markdown
# Upgrading

## Upgrading to 2.0 from 1.x

### Breaking Changes

- **ClassName::methodName() signature changed**
  - Before: `methodName(string $arg)`
  - After: `methodName(Money $arg)`
  - Migration: Wrap strings in `Money::fromString()`

...

## Upgrading to 1.0 from 0.x

(If applicable)
```

## Dependencies

- Task 0001 (Public API finalization) defines what is stable
- Task 0007 (Documentation) versioning.md, support.md become part of docs

## Effort Estimate

**M** (0.5-1 day)
- Versioning documentation: 2 hours
- Release process documentation: 2 hours
- Support policy documentation: 1 hour
- CHANGELOG restructuring: 1 hour
- composer.json updates: 30 minutes
- Migration guide template: 1 hour
- Review and refinement: 1 hour

## Risks / Considerations

- **Premature commitment**: Declaring 1.0 before API is truly stable risks early BC breaks
- **Over-specified**: Too rigid policies can hinder evolution
- **Under-specified**: Too loose policies create uncertainty for users
- **Maintenance burden**: Support policies create obligations

**Recommendation**: 
- Start with 1.0.0-rc1, gather feedback
- Document that RC releases don't guarantee stability
- Move to 1.0.0 after 2-4 weeks of RC stability

## Definition of Done

- [ ] docs/versioning.md created with semver scheme, BC policy, deprecation policy
- [ ] docs/release-process.md created with detailed release steps
- [ ] docs/support.md created with PHP version, bug fix, security policies
- [ ] docs/upgrading.md created with template for migration guides
- [ ] composer.json updated with license field
- [ ] CHANGELOG.md restructured with versioned sections
- [ ] Release checklist (docs/release-checklist.md) expanded
- [ ] All versioning/policy docs linked from README
- [ ] Decision made on 1.0.0-rc1 vs 1.0.0 initial release
- [ ] All documentation reviewed for version references
- [ ] Branching strategy documented (if using release branches)

**Priority:** P2 – High impact

