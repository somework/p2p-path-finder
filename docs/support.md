# Support Policy

This document defines the support policies for the P2P Path Finder library, including PHP version support, bug fixes, security fixes, and dependency updates.

## Table of Contents

- [Overview](#overview)
- [PHP Version Support](#php-version-support)
- [Library Version Support](#library-version-support)
- [Bug Fix Support](#bug-fix-support)
- [Security Fix Support](#security-fix-support)
- [Dependency Update Policy](#dependency-update-policy)
- [End of Life Policy](#end-of-life-policy)
- [Getting Help](#getting-help)

---

## Overview

The P2P Path Finder library follows a pragmatic support policy designed to:
- Support actively maintained PHP versions
- Provide security fixes for recent versions
- Balance maintenance burden with user needs
- Maintain high code quality standards

---

## PHP Version Support

### Supported PHP Versions

The library supports PHP versions that are in **active support** or **security support** according to the [official PHP release schedule](https://www.php.net/supported-versions.php).

| Library Version | Minimum PHP | Recommended PHP | Maximum PHP |
|-----------------|-------------|-----------------|-------------|
| **1.x** | PHP 8.2 | PHP 8.3 | PHP 8.3+ |
| **2.x** (future) | TBD | TBD | TBD |

### PHP Version Support Timeline

```
PHP Version    │ Active Support │ Security Support │ Library Support
───────────────┼────────────────┼──────────────────┼─────────────────
PHP 8.1        │ Nov 2021       │ Nov 2024         │ ❌ Not supported
PHP 8.2        │ Dec 2022       │ Dec 2025         │ ✅ Supported
PHP 8.3        │ Nov 2023       │ Nov 2026         │ ✅ Supported
PHP 8.4        │ Nov 2024       │ Nov 2027         │ ⏳ Future
```

### When PHP Versions Are Dropped

The library will drop support for a PHP version when:
1. The PHP version reaches **End of Life** (no more security fixes from PHP)
2. A new **MAJOR version** of the library is released

**Example**:
- PHP 8.2 reaches EOL in December 2025
- Library v2.0.0 (released after December 2025) may require PHP 8.3+
- Library v1.x continues to support PHP 8.2 for security fixes only

### Testing Matrix

All supported PHP versions are tested in CI:

```yaml
# .github/workflows/tests.yml
matrix:
  php-version: ['8.2', '8.3']
```

---

## Library Version Support

### Support Tiers

| Tier | Versions | Bug Fixes | Security Fixes | New Features |
|------|----------|-----------|----------------|--------------|
| **Active** | Latest MINOR (e.g., 1.5.x) | ✅ Yes | ✅ Yes | ✅ Yes |
| **Maintenance** | Previous MINOR (e.g., 1.4.x) | ⚠️ Critical only | ✅ Yes | ❌ No |
| **Security** | Previous MAJOR (e.g., 0.x.x) | ❌ No | ✅ Yes (6 months) | ❌ No |
| **End of Life** | Older versions | ❌ No | ❌ No | ❌ No |

### Current Support Status

| Version | Status | Bug Fixes Until | Security Fixes Until |
|---------|--------|-----------------|----------------------|
| **1.x** (latest) | 🟢 Active | Ongoing | Ongoing |
| **0.x** (if existed) | 🔴 EOL | Ended | Ended |

### Version Lifecycle Example

```
Version 1.0.0 Released (Jan 2024)
  ↓
Version 1.1.0 Released (Mar 2024)
  → 1.0.x moves to "Maintenance" (critical bugs + security only)
  ↓
Version 1.2.0 Released (May 2024)
  → 1.1.x moves to "Maintenance"
  → 1.0.x moves to "EOL" (no more support)
  ↓
Version 2.0.0 Released (Jan 2025)
  → 1.x moves to "Security" (security fixes for 6 months)
  → 1.x becomes "EOL" after 6 months (Jul 2025)
```

---

## Bug Fix Support

### Bug Fix Policy

**Latest MINOR version** (Active):
- ✅ All bugs fixed
- ✅ Patches released as needed
- ✅ PATCH version bumps (1.5.0 → 1.5.1)

**Previous MINOR version** (Maintenance):
- ⚠️ **Critical bugs only**:
  - Data corruption
  - Security vulnerabilities
  - Major functionality completely broken
- ⚠️ Patches released as needed
- ⚠️ Users encouraged to upgrade to latest MINOR

**Older versions**:
- ❌ No bug fixes
- ❌ Users must upgrade to receive fixes

### What Qualifies as "Critical"

**Critical bugs** (fixed in Maintenance tier):
- 🔴 Data corruption or loss
- 🔴 Security vulnerabilities
- 🔴 Complete loss of primary functionality
- 🔴 Crashes or fatal errors

**Non-critical bugs** (NOT fixed in Maintenance tier):
- 🟡 Minor functionality issues
- 🟡 Performance problems
- 🟡 Cosmetic issues
- 🟡 Documentation errors

**Example**:
```
Scenario: Bug in PathFinder causing incorrect results
Version affected: 1.4.x (Maintenance)
Severity: Critical (incorrect results)
Action: ✅ Patch released (1.4.1)

Scenario: Slow performance in specific edge case
Version affected: 1.4.x (Maintenance)
Severity: Non-critical
Action: ❌ No patch, users should upgrade to 1.5.x
```

---

## Security Fix Support

### Security Fix Policy

Security vulnerabilities are treated with **highest priority**.

**Latest MAJOR version**:
- ✅ All security issues fixed immediately
- ✅ Hotfix releases for critical vulnerabilities
- ✅ Public disclosure after fix is available

**Previous MAJOR version**:
- ✅ Security fixes for **6 months** after new MAJOR release
- ✅ Hotfix releases for critical vulnerabilities
- ⚠️ After 6 months: End of Life (no more fixes)

**Older MAJOR versions**:
- ❌ No security fixes
- ❌ Users must upgrade to receive fixes

### Security Vulnerability Severity

| Severity | Response Time | Action |
|----------|---------------|--------|
| **Critical** | 24 hours | Immediate hotfix release |
| **High** | 7 days | Hotfix release |
| **Medium** | Next PATCH release | Regular patch cycle |
| **Low** | Next MINOR release | Regular release cycle |

### Security Disclosure Process

1. **Report**: Email security issues to `i.pinchuk.work@gmail.com`
2. **Acknowledgment**: Response within 48 hours
3. **Assessment**: Severity assessment within 7 days
4. **Fix**: Develop and test fix (timeline depends on severity)
5. **Release**: Hotfix release with security advisory
6. **Disclosure**: Public disclosure 7 days after fix is available

### Security Advisories

Security advisories are published via:
- GitHub Security Advisories
- CHANGELOG.md
- GitHub Releases
- Project README.md

**Example Advisory**:

```markdown
## Security Advisory: Path Traversal Vulnerability (CVE-2024-XXXXX)

**Severity**: High  
**Affected Versions**: 1.0.0 - 1.5.3  
**Fixed in**: 1.5.4, 1.4.5

### Description
A path traversal vulnerability allowed malicious input to...

### Impact
Attackers could potentially...

### Mitigation
Upgrade to version 1.5.4 or 1.4.5 immediately.

### Credit
Reported by: Security Researcher Name
```

---

## Dependency Update Policy

### Direct Dependencies

The library has minimal dependencies:

```json
{
  "require": {
    "php": "^8.2",
    "brick/math": "^0.12.3"
  }
}
```

### Dependency Update Strategy

| Dependency | Update Policy |
|------------|---------------|
| **PHP** | Support active + security PHP versions |
| **brick/math** | Keep current, update in MINOR versions |
| **Dev dependencies** | Update regularly (doesn't affect users) |

### When Dependencies Are Updated

**PATCH version** (1.5.3 → 1.5.4):
- ✅ Security fixes for dependencies
- ✅ Bug fix updates within same MINOR version
- ❌ NO major dependency updates

**MINOR version** (1.5.x → 1.6.0):
- ✅ Update dependencies to latest MINOR/PATCH
- ✅ Add new optional dependencies
- ❌ NO breaking dependency updates

**MAJOR version** (1.x.x → 2.0.0):
- ✅ Update dependencies to latest versions
- ✅ Update minimum PHP version
- ✅ Replace or remove dependencies

### Dependency Security Monitoring

Dependencies are monitored for security vulnerabilities:

```bash
# Automated checks in CI
composer audit

# Dependabot alerts enabled on GitHub
```

Security vulnerabilities in dependencies trigger:
1. Immediate assessment
2. PATCH release if critical
3. Update in next regular release if non-critical

---

## End of Life Policy

### When a Version Reaches EOL

A library version reaches **End of Life (EOL)** when:
1. Two newer MINOR versions have been released (for MINOR EOL)
2. 6 months after a new MAJOR version (for MAJOR EOL)
3. The minimum PHP version it requires reaches PHP EOL

### What Happens at EOL

When a version reaches EOL:
- ❌ No more bug fixes
- ❌ No more security fixes
- ❌ No more support
- ⚠️ Users MUST upgrade to receive fixes
- 📢 EOL announcement published

### EOL Announcement

EOL is announced **3 months in advance** via:
- GitHub Discussions
- CHANGELOG.md
- README.md banner
- GitHub Release notes

**Example EOL Announcement**:

```markdown
## End of Life Notice: Version 1.x

**Version 1.x will reach End of Life on July 1, 2025.**

After this date:
- No bug fixes
- No security fixes
- No support

**Action Required**:
- Upgrade to version 2.x before July 1, 2025
- See UPGRADING.md for migration guide

**Timeline**:
- April 1, 2025: EOL announced (3 months warning)
- July 1, 2025: Version 1.x reaches EOL
```

---

## Getting Help

### Support Channels

| Channel | Purpose | Response Time |
|---------|---------|---------------|
| **GitHub Issues** | Bug reports, feature requests | Best effort |
| **GitHub Discussions** | Questions, help, discussions | Community-driven |
| **Documentation** | Self-service help | Instant |
| **Security Email** | Security vulnerabilities | 48 hours |

### Before Asking for Help

1. **Read the documentation**:
   - [Getting Started Guide](getting-started.md)
   - [Troubleshooting Guide](troubleshooting.md)
   - [API Documentation](api/index.md)

2. **Search existing issues**:
   - Check [GitHub Issues](https://github.com/somework/p2p-path-finder/issues)
   - Check [GitHub Discussions](https://github.com/somework/p2p-path-finder/discussions)

3. **Verify your version**:
   ```bash
   composer show somework/p2p-path-finder
   ```
   - Check if your version is supported
   - Try upgrading to latest version

### Creating a Bug Report

Include:
- Library version
- PHP version
- Minimal reproducible example
- Expected behavior
- Actual behavior
- Error messages (if any)

**Good Example**:

```markdown
**Library Version**: 1.5.3  
**PHP Version**: 8.3.0  

**Issue**: PathFinder returns incorrect results

**Code**:
\`\`\`php
$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds('0.0', '0.0')
    ->build();
// ... minimal code to reproduce
\`\`\`

**Expected**: Path with cost 100.00  
**Actual**: Path with cost 105.00

**Error Messages**: None
```

### Feature Requests

Feature requests should include:
- Use case description
- Why existing features don't work
- Proposed API (if applicable)
- Willingness to contribute

### Commercial Support

For commercial support inquiries, contact:
- Email: i.pinchuk.work@gmail.com

---

## Support Timeline Summary

| Activity | Timeline |
|----------|----------|
| **Bug fixes** | Latest MINOR only |
| **Security fixes** | Latest MAJOR + 6 months for previous MAJOR |
| **New features** | Latest MINOR only |
| **EOL warning** | 3 months before EOL |
| **Hotfix response** | 24 hours for critical security issues |

---

## Version Support Examples

### Example 1: Upgrading from Maintenance to Active

```
Current: 1.4.5 (Maintenance - only critical bugs fixed)
Latest:  1.5.2 (Active - all bugs fixed, new features)

Recommendation: Upgrade to 1.5.2
Effort: Low (MINOR version, backward compatible)
Benefits: Bug fixes, new features, full support
```

### Example 2: Staying on EOL Version

```
Current: 0.9.0 (EOL)
Latest:  1.5.2 (Active)

Risk: No security fixes, no bug fixes
Recommendation: Upgrade immediately to 1.5.2
Effort: Medium (MAJOR version, may have BC breaks)
Benefits: Security fixes, bug fixes, new features

See: UPGRADING.md for migration guide
```

### Example 3: PHP Version EOL

```
Your Environment: PHP 8.1 (EOL November 2024)
Library: 1.5.2 (requires PHP 8.2+)

Issue: Cannot install/update library
Recommendation: Upgrade PHP to 8.2 or 8.3
Benefits: Security, performance, library support
```

---

## Related Documentation

- [Versioning Policy](versioning.md) - Semantic versioning rules
- [Release Process](release-process.md) - How releases are created
- [Getting Started Guide](getting-started.md) - Quick start
- [Troubleshooting Guide](troubleshooting.md) - Common issues

---

*This support policy balances maintainability with user needs. Feedback is welcome via GitHub Discussions.*

