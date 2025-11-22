# Release Process

This document describes the complete release process for the P2P Path Finder library, including pre-release checks, release steps, post-release tasks, and hotfix procedures.

## Table of Contents

- [Pre-Release Checklist](#pre-release-checklist)
- [Release Steps](#release-steps)
- [Post-Release Steps](#post-release-steps)
- [Hotfix Process](#hotfix-process)
- [Version Numbering](#version-numbering)
- [Release Types](#release-types)

---

## Pre-Release Checklist

Complete ALL items in this checklist before creating a release.

### 1. Code Quality ✅

```bash
# Run full test suite
composer phpunit

# Check for test failures
# Expected: All tests pass

# Run static analysis
composer phpstan

# Expected: No errors

# Run code style checks
composer php-cs-fixer -- --dry-run --diff

# Expected: No violations

# Run Psalm
composer psalm

# Expected: No errors or warnings
```

### 2. Examples Validation ✅

```bash
# Run all examples
composer examples

# Expected: All examples complete successfully with exit code 0

# Manually verify example output matches expected behavior
php examples/guarded-search-example.php
```

### 3. Documentation Review ✅

- [ ] README.md is up to date
- [ ] All docs/*.md files are accurate
- [ ] API documentation is generated and current
- [ ] CHANGELOG.md is updated with all changes
- [ ] UPGRADING.md is updated (if BC breaks in major version)
- [ ] Migration guides are clear and tested
- [ ] No references to BCMath or deprecated features
- [ ] All examples are documented in examples/README.md

### 4. Dependency Check ✅

```bash
# Validate composer.json
composer validate --strict

# Expected: composer.json is valid

# Check for security vulnerabilities
composer audit

# Expected: No known vulnerabilities

# Review outdated dependencies
composer outdated --direct

# Update dependencies if needed (in separate PR)
```

### 5. Version Compatibility ✅

```bash
# Test on all supported PHP versions (via CI or locally)
# PHP 8.2
docker run --rm -v $(pwd):/app -w /app php:8.2-cli composer test

# PHP 8.3
docker run --rm -v $(pwd):/app -w /app php:8.3-cli composer test

# Expected: All tests pass on all PHP versions
```

### 6. Property-Based Tests ✅

```bash
# Run property-based tests with full iterations
vendor/bin/phpunit tests/Domain/ValueObject/MoneyPropertyTest.php
vendor/bin/phpunit tests/Domain/ValueObject/ExchangeRatePropertyTest.php

# Expected: All property tests pass (100 iterations)
```

### 7. Integration Tests ✅

```bash
# Run slow integration tests
vendor/bin/phpunit --group=slow

# Expected: All integration tests pass
```

### 8. Mutation Testing (Optional but Recommended) ⚠️

```bash
# Run mutation testing (takes ~10-15 minutes)
INFECTION=1 XDEBUG_MODE=coverage vendor/bin/infection

# Expected: MSI >= 80%, Covered MSI >= 85%
```

### 9. Final Smoke Test ✅

```bash
# Create a fresh test project
mkdir /tmp/test-p2p-path-finder
cd /tmp/test-p2p-path-finder

# Install the library
composer init --name=test/p2p-test --no-interaction
composer require brick/math
composer config repositories.local path /path/to/p2p-path-finder
composer require somework/p2p-path-finder:dev-main

# Run a simple test
php -r "
require 'vendor/autoload.php';
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
\$money = Money::fromString('USD', '100.00', 2);
echo \$money->amount() . PHP_EOL;
"

# Expected: Outputs "100.00"

# Clean up
cd -
rm -rf /tmp/test-p2p-path-finder
```

---

## Release Steps

### For PATCH/MINOR Releases (1.x.x → 1.x.y or 1.x.x → 1.y.0)

#### 1. Update Version Information

```bash
# Update CHANGELOG.md
# Add new version section with release date:

## [1.3.0] - 2024-01-15

### Added
- New feature X
- New feature Y

### Changed
- Improvement Z

### Fixed
- Bug fix A
- Bug fix B

### Deprecated
- Feature C (use feature D instead)

# Commit changes
git add CHANGELOG.md
git commit -m "docs: prepare changelog for v1.3.0"
git push origin main
```

#### 2. Create Release Tag

```bash
# Create annotated tag
git tag -a v1.3.0 -m "Release v1.3.0

Summary of changes:
- Added feature X
- Improved performance Y
- Fixed bug Z

See CHANGELOG.md for full details."

# Push tag to GitHub
git push origin v1.3.0
```

#### 3. Create GitHub Release

1. Go to: https://github.com/somework/p2p-path-finder/releases/new
2. Select tag: `v1.3.0`
3. Release title: `v1.3.0 - Feature Name or Summary`
4. Description: Copy relevant section from CHANGELOG.md
5. Check **"Set as the latest release"**
6. Click **"Publish release"**

### For MAJOR Releases (1.x.x → 2.0.0)

Follow the same steps as PATCH/MINOR, plus:

#### Additional Step: Update UPGRADING.md

```markdown
# Upgrading from 1.x to 2.0

## BC Breaks

### 1. PathFinderService::findPath() removed

**What changed**: Method removed.

**Migration**:
\`\`\`php
// Before (1.x)
$result = $service->findPath($orderBook, $spend, 'EUR');

// After (2.0)
$config = PathSearchConfig::builder()
    ->withSpendAmount($spend)
    ->withToleranceBounds('0.0', '0.0')
    ->build();
$request = new PathSearchRequest($orderBook, $config, 'EUR');
$outcome = $service->findBestPaths($request);
$result = $outcome->hasPaths() ? $outcome->paths()->first() : null;
\`\`\`

### 2. Money::multiply() now requires scale parameter

**What changed**: Scale parameter is now required.

**Migration**:
\`\`\`php
// Before (1.x)
$result = $money->multiply('1.5');

// After (2.0)
$result = $money->multiply('1.5', 6); // Specify scale
\`\`\`
```

Commit UPGRADING.md before creating the tag.

---

## Post-Release Steps

### 1. Verify Release on Packagist

1. Go to: https://packagist.org/packages/somework/p2p-path-finder
2. Verify new version appears (usually within 5-10 minutes)
3. Check that release info is correct

### 2. Update Documentation Website (If Applicable)

```bash
# Regenerate API documentation
composer phpdoc

# Deploy to documentation site
# (Process depends on hosting setup)
```

### 3. Announce Release

#### GitHub Discussions

Post announcement in GitHub Discussions:

```markdown
**Release v1.3.0 is now available! 🎉**

This release includes:
- ✨ New feature X
- ⚡ Performance improvement Y (30% faster)
- 🐛 Bug fix Z

**Installation**:
\`\`\`bash
composer require somework/p2p-path-finder:^1.3
\`\`\`

**Full changelog**: https://github.com/somework/p2p-path-finder/releases/tag/v1.3.0
```

#### Social Media (Optional)

- Twitter/X
- Reddit (r/PHP)
- PHP Newsletter

### 4. Monitor for Issues

**First 24 hours**:
- Monitor GitHub issues for bug reports
- Check Packagist download statistics
- Watch for CI failures in dependent projects

**First week**:
- Respond to any issues quickly
- Prepare hotfix if critical bugs are found

---

## Hotfix Process

Hotfixes address critical bugs or security issues that cannot wait for the next regular release.

### When to Create a Hotfix

**Create a hotfix for**:
- 🔴 Security vulnerabilities
- 🔴 Data corruption bugs
- 🔴 Critical functionality completely broken
- 🔴 Production-blocking issues

**Do NOT create a hotfix for**:
- Minor bugs (wait for next PATCH release)
- Feature requests (wait for next MINOR release)
- Performance improvements (wait for next PATCH/MINOR release)
- Documentation updates (can be fixed in main)

### Hotfix Steps

#### 1. Create Hotfix Branch

```bash
# Create branch from latest release tag
git checkout v1.3.0
git checkout -b hotfix/1.3.1

# OR from latest commit on main
git checkout main
git pull
git checkout -b hotfix/1.3.1
```

#### 2. Implement Fix

```bash
# Fix the bug (minimal changes only)
# Add test that reproduces the bug
# Verify the test fails before fix
# Verify the test passes after fix

git add -A
git commit -m "fix: critical bug in PathFinder

Fixes #123

The bug caused incorrect results when...
This fix ensures that..."
```

#### 3. Fast-Track Review

```bash
# Push branch
git push origin hotfix/1.3.1

# Create PR with "hotfix" label
# Request immediate review from maintainers
# Skip normal review wait time
```

#### 4. Merge and Release

```bash
# After approval, merge to main
git checkout main
git merge hotfix/1.3.1

# Update CHANGELOG.md
## [1.3.1] - 2024-01-16

### Fixed
- Critical bug in PathFinder causing incorrect results (#123)

git add CHANGELOG.md
git commit -m "docs: add changelog for v1.3.1 hotfix"

# Create tag
git tag -a v1.3.1 -m "Hotfix v1.3.1

Critical bug fix for PathFinder.

Fixes #123"

# Push
git push origin main
git push origin v1.3.1

# Create GitHub release with "This is a critical security/bug fix" note
```

#### 5. Notify Users

**GitHub**:
- Create release with 🔴 prefix
- Post in GitHub Discussions

**Security Issues**:
- Create GitHub Security Advisory
- Email security@yourproject.org mailing list (if exists)
- Post on security mailing lists

**Example Hotfix Release Notes**:

```markdown
# 🔴 v1.3.1 - Critical Hotfix

**This is a critical bug fix release. All users of v1.3.0 should upgrade immediately.**

## Fixed
- Critical bug in PathFinder that could cause incorrect path results (#123)

## Upgrade

\`\`\`bash
composer update somework/p2p-path-finder
\`\`\`

## Impact
Users of v1.3.0 may have experienced incorrect pathfinding results in specific scenarios.
This release ensures correct behavior in all cases.
```

---

## Version Numbering

### Semantic Versioning

Versions follow [Semantic Versioning 2.0.0](https://semver.org/):

```
MAJOR.MINOR.PATCH

Example: 1.3.2
         │ │ │
         │ │ └─ PATCH: Bug fixes
         │ └─── MINOR: New features (BC)
         └───── MAJOR: Breaking changes
```

### Pre-Release Versions

```
1.0.0-alpha.1    # Early development
1.0.0-beta.1     # Feature complete, testing
1.0.0-rc.1       # Release candidate
1.0.0            # Stable release
```

### Version Tagging

```bash
# Stable releases
v1.0.0, v1.1.0, v1.1.1, v2.0.0

# Pre-releases
v1.0.0-alpha.1
v1.0.0-beta.1
v1.0.0-rc.1
```

---

## Release Types

### Regular Releases

| Type | Frequency | Purpose |
|------|-----------|---------|
| **PATCH** | As needed | Bug fixes, security fixes |
| **MINOR** | Every 2-4 months | New features, deprecations |
| **MAJOR** | When needed | BC breaks, major changes |

### Pre-Release Versions

| Type | Purpose | Stability |
|------|---------|-----------|
| **Alpha** | Early testing, API unstable | ⚠️ Unstable |
| **Beta** | Feature complete, API stabilizing | ⚠️ Testing |
| **RC** | Production-ready, final testing | ✅ Stable |

---

## Release Checklist Template

Copy this checklist for each release:

```markdown
## Release v1.x.x Checklist

### Pre-Release
- [ ] All tests pass
- [ ] PHPStan passes
- [ ] PHP CS Fixer passes
- [ ] Examples run successfully
- [ ] Documentation updated
- [ ] CHANGELOG.md updated
- [ ] UPGRADING.md updated (if major)
- [ ] Composer validate passes
- [ ] Tested on PHP 8.2 and 8.3
- [ ] Property tests pass (full iterations)
- [ ] Smoke test completed

### Release
- [ ] Version tag created (vX.Y.Z)
- [ ] Tag pushed to GitHub
- [ ] GitHub release created
- [ ] Release notes published

### Post-Release
- [ ] Packagist updated (verify)
- [ ] Release announced
- [ ] Monitoring for issues
- [ ] Documentation website updated
```

---

## Related Documentation

- [Versioning Policy](versioning.md) - Semantic versioning rules
- [Support Policy](support.md) - Version support timelines
- [Release Checklist](release-checklist.md) - Technical release checklist
- [Contributing Guide](../CONTRIBUTING.md) - How to contribute

---

*This release process ensures consistent, high-quality releases with minimal risk.*

