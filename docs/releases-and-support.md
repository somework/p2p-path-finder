# Releases and Support

This document describes the versioning, release schedule, support policies, and PHP version compatibility for the P2P Path Finder library.

## Table of Contents

- [Semantic Versioning](#semantic-versioning)
- [PHP Version Support](#php-version-support)
- [Library Support Lifecycle](#library-support-lifecycle)
- [Bug and Security Fixes](#bug-and-security-fixes)
- [Deprecation Policy](#deprecation-policy)
- [Dependency Updates](#dependency-updates)
- [Getting Help](#getting-help)

---

## Semantic Versioning

The library follows [Semantic Versioning 2.0.0](https://semver.org/) (SemVer).

### Version Format

```
MAJOR.MINOR.PATCH (e.g., 1.3.2)
  │     │     │
  │     │     └─ Bug fixes (backward compatible)
  │     └─────── New features (backward compatible)
  └─────────────── Breaking changes
```

### Version Increments

**MAJOR version** (1.x.x → 2.0.0):
- Removing public API methods or classes
- Changing method signatures or return types
- Changing exception types
- Significant API structure changes
- Breaking behavioral changes

**MINOR version** (1.2.x → 1.3.0):
- Adding new public methods or classes
- Adding optional parameters (with defaults)
- Adding new API methods
- Performance improvements
- New configuration options

**PATCH version** (1.2.3 → 1.2.4):
- Bug fixes that don't change API
- Documentation improvements
- Internal refactoring
- Performance optimizations (no API changes)

### Backward Compatibility Guarantees

**What constitutes a BC break**:

```php
// ❌ BC BREAK: Removing public methods
class PathFinderService {
    // public function findPath(...): ?PathResult  // Removed
}

// ❌ BC BREAK: Adding required parameters
class PathSearchConfig {
    // Before: public function __construct(Money $amount)
    // After:  public function __construct(Money $amount, int $hops)
}

// ❌ BC BREAK: Removing API methods
$amount = $money->amount();
// Before: ['currency' => 'USD', 'amount' => '100.00', 'scale' => 2]
// After:  ['currency' => 'USD', 'amount' => '100.00']  // 'scale' removed
```

**What is NOT a BC break**:

```php
// ✅ OK: Adding new public methods
class PathFinderService {
    public function findAllPaths(): Generator  // New method
}

// ✅ OK: Adding optional parameters
public static function builder(?string $preset = null): PathSearchConfigBuilder

// ✅ OK: Adding new API methods
$amount = $money->amount();
// Before: ['currency' => 'USD', 'amount' => '100.00']
// After:  ['currency' => 'USD', 'amount' => '100.00', 'formatted' => '$100.00']

// ✅ OK: Internal refactoring (same behavior)
```

### Public vs Internal API

**Public API** (BC guarantees apply):
- Classes/methods marked with `@api` tag
- Namespaces: `Application\Service\*`, `Application\Config\*`, `Application\OrderBook\*`, `Domain\**`, `Exception\**`

**Internal API** (No BC guarantees):
- Classes/methods marked with `@internal` tag
- Namespaces: `Application\Graph\*`, `Application\PathFinder\*`
- May change in MINOR versions without notice

---

## PHP Version Support

### Supported PHP Versions

The library supports PHP versions in **active support** or **security support** per the [official PHP release schedule](https://www.php.net/supported-versions.php).

| Library Version  | Minimum PHP | Recommended PHP | Testing Matrix |
|------------------|-------------|-----------------|----------------|
| **1.x**          | PHP 8.2     | PHP 8.3         | 8.2, 8.3       |
| **2.x** (future) | TBD         | TBD             | TBD            |

### PHP Version Timeline

```
PHP Version  │ Active Support │ Security Support │ Library Support
─────────────┼────────────────┼──────────────────┼─────────────────
PHP 8.1      │ Nov 2021       │ Nov 2024         │ ❌ Not supported
PHP 8.2      │ Dec 2022       │ Dec 2025         │ ✅ Supported
PHP 8.3      │ Nov 2023       │ Nov 2026         │ ✅ Supported
PHP 8.4      │ Nov 2024       │ Nov 2027         │ ⏳ Future
```

### When PHP Versions Are Dropped

PHP version support is dropped when:
1. The PHP version reaches **End of Life** (no security fixes from PHP)
2. A new **MAJOR version** of the library is released

**Example**: PHP 8.2 reaches EOL in December 2025. Library v2.0.0 (released after Dec 2025) may require PHP 8.3+, while v1.x continues to support PHP 8.2 for security fixes only.

---

## Library Support Lifecycle

### Support Tiers

| Tier            | Versions                     | Bug Fixes        | Security Fixes   | New Features |
|-----------------|------------------------------|------------------|------------------|--------------|
| **Active**      | Latest MINOR (e.g., 1.5.x)   | ✅ Yes            | ✅ Yes            | ✅ Yes        |
| **Maintenance** | Previous MINOR (e.g., 1.4.x) | ⚠️ Critical only | ✅ Yes            | ❌ No         |
| **Security**    | Previous MAJOR (e.g., 0.x.x) | ❌ No             | ✅ Yes (6 months) | ❌ No         |
| **End of Life** | Older versions               | ❌ No             | ❌ No             | ❌ No         |

### Current Support Status

| Version          | Status    | Bug Fixes Until | Security Fixes Until |
|------------------|-----------|-----------------|----------------------|
| **1.x** (latest) | 🟢 Active | Ongoing         | Ongoing              |

### Lifecycle Example

```
Version 1.0.0 Released (Jan 2024)
  ↓
Version 1.1.0 Released (Mar 2024)
  → 1.0.x moves to "Maintenance" (critical bugs + security only)
  ↓
Version 1.2.0 Released (May 2024)
  → 1.1.x moves to "Maintenance"
  → 1.0.x moves to "EOL"
  ↓
Version 2.0.0 Released (Jan 2025)
  → 1.x moves to "Security" (security fixes for 6 months)
  → 1.x becomes "EOL" after 6 months (Jul 2025)
```

### End of Life Policy

**EOL Announcement**: 3 months advance notice via GitHub Discussions, CHANGELOG.md, and README.md

**What happens at EOL**:
- ❌ No bug fixes
- ❌ No security fixes
- ❌ No support
- ⚠️ Users MUST upgrade

---

## Bug and Security Fixes

### Bug Fix Policy

**Latest MINOR version** (Active):
- ✅ All bugs fixed
- ✅ Patches released as needed
- ✅ PATCH version bumps (1.5.0 → 1.5.1)

**Previous MINOR version** (Maintenance):
- ⚠️ **Critical bugs only**: data corruption, security vulnerabilities, complete functionality loss
- ⚠️ Users encouraged to upgrade to latest MINOR

**Older versions**:
- ❌ No bug fixes
- ❌ Users must upgrade

### Security Fix Policy

Security vulnerabilities receive **highest priority**.

**Latest MAJOR version**:
- ✅ All security issues fixed immediately
- ✅ Hotfix releases for critical vulnerabilities

**Previous MAJOR version**:
- ✅ Security fixes for **6 months** after new MAJOR release
- ✅ Hotfix releases for critical vulnerabilities
- ⚠️ After 6 months: End of Life

**Response Times**:

| Severity     | Response Time | Action              |
|--------------|---------------|---------------------|
| **Critical** | 24 hours      | Immediate hotfix    |
| **High**     | 7 days        | Hotfix release      |
| **Medium**   | Next PATCH    | Regular patch cycle |
| **Low**      | Next MINOR    | Regular release     |

### Security Disclosure

**Reporting**: Email security issues to `i.pinchuk.work@gmail.com`
- Acknowledgment: 48 hours
- Assessment: 7 days
- Public disclosure: 7 days after fix is available

**Advisories published via**:
- GitHub Security Advisories
- CHANGELOG.md
- GitHub Releases

---

## Deprecation Policy

### Deprecation Process

```
Version 1.5.0: Feature deprecated (with @deprecated tag and alternatives)
                ↓ (minimum 1 minor version)
Version 1.6.0: Feature still works, deprecation notice remains
                ↓
Version 2.0.0: Feature removed (BC break, major version bump)
```

**Minimum Deprecation Period**: 1 minor version (recommended: 2-3 for major features)

**Exception**: Critical security issues may skip deprecation

### Deprecation Annotation

All deprecated features use `@deprecated` tag and trigger `E_USER_DEPRECATED`:

```php
/**
 * @deprecated since 1.5.0, use newMethod() instead. Will be removed in 2.0.0.
 */
public function oldMethod(): void
{
    trigger_error(
        'Method oldMethod() is deprecated since 1.5.0, use newMethod() instead.',
        E_USER_DEPRECATED
    );
    
    $this->newMethod(); // Still works
}
```

### Detecting Deprecated Usage

**During Development**:
```bash
vendor/bin/phpstan analyse  # PHPStan detects deprecated usage
```

**During Testing**:
```xml
<!-- phpunit.xml -->
<phpunit failOnDeprecation="true"></phpunit>
```

**In Production**:
```php
set_error_handler(function ($errno, $errstr) {
    if ($errno === E_USER_DEPRECATED) {
        error_log("Deprecation: $errstr");
    }
});
```

---

## Dependency Updates

### Current Dependencies

```json
{
  "require": {
    "php": "^8.2",
    "brick/math": "^0.12.3"
  }
}
```

### Update Policy

**PATCH version** (1.5.3 → 1.5.4):
- ✅ Security fixes for dependencies
- ✅ Bug fix updates within same MINOR
- ❌ NO major dependency updates

**MINOR version** (1.5.x → 1.6.0):
- ✅ Update dependencies to latest MINOR/PATCH
- ✅ Add new optional dependencies
- ❌ NO breaking dependency updates

**MAJOR version** (1.x.x → 2.0.0):
- ✅ Update dependencies to latest versions
- ✅ Update minimum PHP version
- ✅ Replace or remove dependencies

### Security Monitoring

Dependencies monitored via:
```bash
composer audit           # Automated CI checks
```

Dependabot alerts enabled on GitHub.

---

## Getting Help

### Support Channels

| Channel                | Purpose                       | Response Time    |
|------------------------|-------------------------------|------------------|
| **GitHub Issues**      | Bug reports, feature requests | Best effort      |
| **GitHub Discussions** | Questions, help, discussions  | Community-driven |
| **Documentation**      | Self-service help             | Instant          |
| **Security Email**     | Security vulnerabilities      | 48 hours         |

### Before Asking for Help

1. **Read documentation**: [Getting Started](getting-started.md), [Troubleshooting](troubleshooting.md), [API Docs](api/index.md)
2. **Search existing issues**: [GitHub Issues](https://github.com/somework/p2p-path-finder/issues)
3. **Verify your version**: `composer show somework/p2p-path-finder`
4. **Try upgrading**: Check if issue is fixed in latest version

### Creating a Bug Report

Include:
- Library version
- PHP version
- Minimal reproducible example
- Expected vs actual behavior
- Error messages (if any)

**Example**:

```markdown
**Library Version**: 1.5.3  
**PHP Version**: 8.3.0  

**Issue**: PathFinder returns incorrect results

**Code**:
```php
$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds('0.0', '0.0')
    ->build();
// ... minimal code to reproduce
```

**Expected**: Path with cost 100.00  
**Actual**: Path with cost 105.00
```

### Feature Requests

Include:
- Use case description
- Why existing features don't work
- Proposed API (if applicable)
- Willingness to contribute

### Commercial Support

For commercial support inquiries: `i.pinchuk.work@gmail.com`

---

## Upgrade Guidance

### Version Constraint Recommendations

**In `composer.json`**:

```json
{
    "require": {
        "somework/p2p-path-finder": "^1.0"
    }
}
```

- `^1.0` = `>=1.0.0 <2.0.0`
- Allows MINOR and PATCH updates automatically
- Prevents MAJOR updates (potential BC breaks)
- **Recommended for production**

**Alternative constraints**:

```json
// Lock to specific MINOR version
"somework/p2p-path-finder": "~1.5.0"  // >=1.5.0 <1.6.0

// Allow all 1.x versions (more flexible)
"somework/p2p-path-finder": "^1.0"
```

### Upgrade Scenarios

**PATCH Upgrade** (1.2.3 → 1.2.4):
- Changes: Bug fixes only
- Action: `composer update somework/p2p-path-finder`
- Risk: ✅ None - Safe upgrade
- Testing: Run existing tests

**MINOR Upgrade** (1.2.4 → 1.3.0):
- Changes: New features, deprecations
- Action: `composer update somework/p2p-path-finder`
- Risk: ✅ Low - Backward compatible
- Testing: Run tests, check deprecation notices

**MAJOR Upgrade** (1.9.0 → 2.0.0):
- Changes: BC breaks, removed deprecated features
- Action:
  1. Review CHANGELOG.md for breaking changes
  2. Review UPGRADING.md for migration steps
  3. Update code to use new APIs
  4. Update composer.json: `"somework/p2p-path-finder": "^2.0"`
  5. Run `composer update`
  6. Run comprehensive tests
- Risk: ⚠️ High - May require code changes
- Testing: Full test suite + manual testing

---

## FAQ

**Q: Can I rely on internal classes?**  
A: No. Only classes marked `@api` or in public API namespaces are stable. Internal classes may change in MINOR versions.

**Q: Are performance improvements considered BC breaks?**  
A: No, as long as behavior and output remain the same.

**Q: What if a bug fix changes behavior?**  
A: Bug fixes are PATCH releases even if they change behavior, because they restore the *intended* behavior.

**Q: How long are MAJOR versions supported?**  
A: Active support for latest MINOR. Security fixes for 6 months after new MAJOR release.

**Q: Can API methods be removed?**  
A: No, removing fields is a BC break. Fields can be deprecated and removed in a MAJOR version.

---

## Related Documentation

- [API Stability Guide](api-stability.md) - Detailed API stability guarantees
- [Getting Started Guide](getting-started.md) - Quick start tutorial
- [Troubleshooting Guide](troubleshooting.md) - Common issues and solutions
- [CHANGELOG.md](../CHANGELOG.md) - Full version history
- [UPGRADING.md](../UPGRADING.md) - Migration guides for major versions

---

*This policy is effective as of version 1.0.0 and follows [Semantic Versioning 2.0.0](https://semver.org/).*

