# Task: Packaging Quality, Repository Hygiene and Metadata Review

## Context

The repository has good foundational infrastructure:
- composer.json with metadata (keywords, homepage, support, funding)
- GitHub Actions workflows (tests.yml, quality.yml)
- Quality tools configured (PHPStan, Psalm, PHP-CS-Fixer, Infection, PhpBench)
- Community files (CODE_OF_CONDUCT, CONTRIBUTING, SECURITY)
- LICENSE file (exists but not reviewed)
- .gitignore, .gitattributes (not reviewed)
- README badges for builds

Before 1.0 release, these need review for:
- Completeness
- Correctness
- Best practices
- Missing elements

## Problem

**Potential gaps and issues:**

1. **composer.json quality**:
   - License field missing (should be added per task 0008)
   - Keywords adequate? ("path-finding", "p2p", "exchange", "graph", "routing")
   - Description adequate? ("Deterministic Brick\\Math BigDecimal path-finding toolkit for order-driven conversions")
   - PHP platform requirements (ext-ctype, ext-json, etc. listed in README but not required in composer.json)
   - Should ext-bcmath be explicitly excluded since it's not used?
   - Funding links appropriate?
   - Suggest/recommend sections needed?

2. **.gitattributes**:
   - Does it exist?
   - Are test files, docs, CI configs excluded from export?
   - Is .gitattributes itself included?

3. **.gitignore**:
   - Are all generated files ignored?
   - Are IDE files ignored?
   - Are OS files ignored?
   - Is .phpunit.cache ignored?

4. **LICENSE**:
   - What license is it? (need to verify)
   - Is it OSI-approved?
   - Is copyright holder correct?
   - Is year current?
   - Does it match composer.json?

5. **README badges**:
   - Tests badge ✓ (present)
   - Quality badge ✓ (present)
   - Version/release badge? (probably not needed until 1.0)
   - Coverage badge? (consider adding)
   - License badge? (consider adding)
   - PHP version badge? (consider adding)

6. **GitHub repository settings**:
   - Repository description matches composer.json?
   - Topics/tags set?
   - Website URL set?
   - License detected?
   - README renders correctly?

7. **GitHub templates**:
   - Issue templates? (bug report, feature request)
   - Pull request template?
   - Discussion templates?
   - FUNDING.yml? (for sponsor button)

8. **CI workflow completeness**:
   - Are all PHP versions tested? (8.2, 8.3) ✓
   - Should 8.4 be added?
   - Are all quality gates enforced? ✓
   - Is workflow efficient? (caching, parallelization)
   - Are artifacts saved? (coverage, benchmarks) ✓

9. **Code style consistency**:
   - Is .php-cs-fixer.dist.php configured correctly? ✓ (already reviewed)
   - Are all files formatted? (verify)
   - Are there any style violations in production code?

10. **Static analysis baselines**:
    - phpstan-baseline.neon: empty ✓ (good)
    - Is there a psalm baseline? (check)
    - Are there any suppressed issues that should be fixed?

11. **Dependency security**:
    - Are dependencies up-to-date?
    - Should dependabot be enabled?
    - Should security scanning be enabled? (Snyk, LGTM, etc.)

12. **Obsolete files**:
    - Are there any legacy files that should be removed?
    - Old configurations?
    - Deprecated code?
    - Unused fixtures?

## Proposed Changes

### 1. Enhance composer.json

**Add missing fields**:
```json
{
  "license": "MIT",
  "prefer-stable": true,
  "config": {
    "sort-packages": true,
    "optimize-autoloader": true
  }
}
```

**Review keywords** - consider adding:
- "dijkstra"
- "shortest-path"
- "bigdecimal"
- "deterministic"
- "cryptocurrency" (if applicable)

**Review platform requirements** - consider explicitly requiring key extensions:
```json
{
  "require": {
    "ext-json": "*",
    "ext-mbstring": "*"
  }
}
```

**Consider adding suggest**:
```json
{
  "suggest": {
    "ext-bcmath": "For compatibility if you need to compare against BCMath behavior"
  }
}
```
(Actually probably don't suggest bcmath since we've migrated away)

### 2. Create/update .gitattributes

**Create .gitattributes** if missing:
```
# Exclude from export
/tests export-ignore
/docs export-ignore
/.github export-ignore
/.phpunit.cache export-ignore
/var export-ignore
/examples export-ignore
/benchmarks export-ignore
/.php-cs-fixer.dist.php export-ignore
/.php-cs-fixer.cache export-ignore
/phpstan.neon.dist export-ignore
/phpstan-baseline.neon export-ignore
/psalm.xml.dist export-ignore
/phpunit.xml.dist export-ignore
/infection.json.dist export-ignore
/phpbench.json export-ignore
/phpbench.json.dist export-ignore
/.phpbench export-ignore
/CONTRIBUTING.md export-ignore
/CODE_OF_CONDUCT.md export-ignore
/AGENT.md export-ignore

# Normalize line endings
* text=auto eol=lf
*.php text eol=lf
*.json text eol=lf
*.md text eol=lf
*.yml text eol=lf
*.xml text eol=lf
```

### 3. Review and update .gitignore

**Verify .gitignore includes**:
```
/vendor/
/var/cache/
/.phpunit.cache/
/.php-cs-fixer.cache
/composer.lock (if library)
/coverage/
/build/
/.phpbench/storage/ (or keep for baseline?)
/.idea/
/.vscode/
.DS_Store
Thumbs.db
```

**Decision**: Should .phpbench/storage/ be committed? (Probably yes, for baseline)

### 4. Verify LICENSE file

**Check LICENSE file**:
- Read current license
- Verify it's OSI-approved
- Check copyright year and holder
- Add license field to composer.json (covered in task 0008)

### 5. Add README badges

**Consider adding**:
```markdown
[![PHP Version](https://img.shields.io/packagist/php-v/somework/p2p-path-finder)](https://packagist.org/packages/somework/p2p-path-finder)
[![License](https://img.shields.io/github/license/somework/p2p-path-finder)](https://github.com/somework/p2p-path-finder/blob/main/LICENSE)
[![Latest Release](https://img.shields.io/github/v/release/somework/p2p-path-finder)](https://github.com/somework/p2p-path-finder/releases)
```

Don't overdo badges - keep it minimal and useful

### 6. Review GitHub repository settings

**Via GitHub web interface, verify**:
- Description: "Deterministic BigDecimal path-finding toolkit for P2P conversions"
- Website: https://github.com/somework/p2p-path-finder (or docs site if one exists)
- Topics: php, path-finding, graph, bigdecimal, p2p, exchange, dijkstra
- License detected correctly
- Social preview image (optional but nice)

### 7. Add GitHub templates

**Create .github/ISSUE_TEMPLATE/bug_report.md**:
```markdown
---
name: Bug report
about: Report a bug
title: '[BUG] '
labels: bug
---

**Describe the bug**
A clear description of what the bug is.

**To Reproduce**
Steps to reproduce the behavior, preferably with code example.

**Expected behavior**
What you expected to happen.

**Environment**
- PHP version: 
- Package version:
- OS:

**Additional context**
Any other context about the problem.
```

**Create .github/ISSUE_TEMPLATE/feature_request.md**:
```markdown
---
name: Feature request
about: Suggest a feature
title: '[FEATURE] '
labels: enhancement
---

**Is your feature request related to a problem?**
A clear description of the problem.

**Describe the solution you'd like**
Clear description of what you want to happen.

**Describe alternatives you've considered**
Any alternative solutions or features you've considered.

**Additional context**
Any other context or screenshots.
```

**Create .github/PULL_REQUEST_TEMPLATE.md**:
```markdown
## Description
<!-- Describe your changes -->

## Related Issues
<!-- Link related issues: Fixes #123 -->

## Checklist
- [ ] Tests added/updated
- [ ] Documentation updated
- [ ] CHANGELOG.md updated
- [ ] All CI checks pass
- [ ] Code follows project style guide
```

**Create .github/FUNDING.yml** (if funding is desired):
```yaml
github: somework
```

### 8. Review CI workflows

**Current workflows** (tests.yml, quality.yml) look comprehensive.

**Consider adding**:
- PHP 8.4 testing (when available)
- Dependency security scanning job
- Coverage upload to coveralls.io or codecov.io
- Scheduled runs (weekly) to catch dependency issues

**Optimize**:
- Workflows already use caching ✓
- Parallelization across PHP versions ✓
- Artifact upload ✓

### 9. Verify code style compliance

**Run style check**:
```bash
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff
```

**Fix any violations**:
```bash
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php
```

**Verify no style violations in src/**

### 10. Review static analysis baselines

**Check for psalm baseline**:
```bash
ls -la psalm*.xml
```

**If baseline exists, review**:
- Are suppressed issues necessary?
- Can any be fixed?

**PHPStan baseline** is empty ✓

### 11. Set up dependency security

**Consider adding Dependabot** (.github/dependabot.yml):
```yaml
version: 2
updates:
  - package-ecosystem: "composer"
    directory: "/"
    schedule:
      interval: "weekly"
    open-pull-requests-limit: 5
```

**Consider adding security scanning workflow**:
- GitHub security scanning (already enabled by default?)
- Local security audit: `composer audit`

### 12. Clean up obsolete files

**Review repository for**:
- Old README files (README.old, etc.)
- Backup files (*.bak, *~)
- Legacy configuration files
- Unused test fixtures
- Old migration scripts
- Commented-out code blocks

**Remove what's not needed**

### 13. Validate composer.json

```bash
composer validate --strict
```

**Fix any warnings or errors**

### 14. Test package installation

**Test fresh install**:
```bash
mkdir /tmp/test-install
cd /tmp/test-install
composer require somework/p2p-path-finder:dev-main
```

**Verify**:
- Installs without errors
- Autoloading works
- Simple example runs

## Dependencies

- Task 0008 (versioning) for license field addition

## Effort Estimate

**S** (≤2 hours)
- composer.json review: 30 minutes
- .gitattributes creation: 15 minutes
- .gitignore review: 15 minutes
- LICENSE review: 10 minutes
- README badge additions: 15 minutes
- GitHub settings review: 15 minutes
- GitHub template creation: 30 minutes
- CI workflow review: 15 minutes
- Code style check: 10 minutes
- Security setup: 20 minutes
- Validation: 15 minutes

## Risks / Considerations

- **Over-engineering**: Not every repository needs every possible file/configuration
- **Maintenance**: More templates and workflows = more to maintain
- **False positives**: Security scanning can be noisy
- **Premature optimization**: Some features might not be needed until later

**Balance**: Add what's clearly beneficial for 1.0 release, defer nice-to-haves

## Definition of Done

- [ ] composer.json enhanced (license, keywords, platform requirements if needed)
- [ ] .gitattributes created/updated with export-ignore rules
- [ ] .gitignore reviewed and updated
- [ ] LICENSE file verified, composer.json license field added
- [ ] README badges reviewed, useful ones added
- [ ] GitHub repository settings verified (description, topics, website)
- [ ] GitHub issue templates created (bug, feature)
- [ ] GitHub PR template created
- [ ] FUNDING.yml created (if applicable)
- [ ] CI workflows reviewed for completeness
- [ ] PHP 8.4 consideration documented (add when stable)
- [ ] Code style violations fixed
- [ ] Static analysis baselines reviewed
- [ ] Dependabot enabled (optional, decide)
- [ ] Security scanning reviewed
- [ ] Obsolete files removed
- [ ] `composer validate --strict` passes
- [ ] Test installation successful

**Priority:** P2 – High impact

