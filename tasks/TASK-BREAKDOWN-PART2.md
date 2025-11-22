# Task Breakdown Part 2 - Tasks 0006-0014

Continuation of detailed task breakdowns. See TASK-BREAKDOWN.md for tasks 0001-0005.

---

## 0006: Test Coverage Analysis for Critical Paths

### 0006.1: Generate Coverage Report
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Run `vendor/bin/phpunit --coverage-html coverage-report`
- Analyze HTML report
- Identify classes with < 90% coverage
- Identify uncovered methods and branches
- Document findings

**Done When**:
- [ ] Coverage report generated
- [ ] Low-coverage areas identified
- [ ] Findings documented for review

---

### 0006.2: Analyze PathFinder Coverage
**Effort**: M (2h)  
**Dependencies**: 0006.1

**Actions**:
- Review PathFinder coverage specifically
- Identify uncovered code paths
- Determine if gaps are intentional or need tests
- Create list of missing tests

**Done When**:
- [ ] PathFinder coverage analyzed
- [ ] Missing tests listed
- [ ] Priority assigned to each

---

### 0006.3: Analyze PathFinderService Coverage
**Effort**: M (2h)  
**Dependencies**: 0006.1

**Actions**:
- Review PathFinderService coverage
- Check callback path coverage
- Check error handling coverage
- List missing tests

**Done When**:
- [ ] PathFinderService coverage analyzed
- [ ] Gaps identified
- [ ] Missing tests listed

---

### 0006.4: Analyze GraphBuilder Coverage
**Effort**: M (2h)  
**Dependencies**: 0006.1

**Actions**:
- Review GraphBuilder coverage
- Check edge construction coverage
- Check segment handling coverage
- List missing tests

**Done When**:
- [ ] GraphBuilder coverage analyzed
- [ ] Edge cases identified
- [ ] Missing tests listed

---

### 0006.5: Analyze Domain Value Objects Coverage
**Effort**: M (2h)  
**Dependencies**: 0006.1

**Actions**:
- Review Money, ExchangeRate, OrderBounds coverage
- Check arithmetic operations coverage
- Check validation coverage
- List missing tests

**Done When**:
- [ ] Domain VO coverage analyzed
- [ ] Gaps identified
- [ ] Missing tests listed

---

### 0006.6: Add Multi-Hop with Fees Integration Test
**Effort**: M (2-3h)  
**Dependencies**: None

**Actions**:
- Create realistic 3-hop path with fees scenario
- Test end-to-end path finding
- Verify fee calculation correctness
- Add to PathFinderServiceIntegrationTest.php (create if needed)

**Done When**:
- [ ] Multi-hop fee test added
- [ ] Test passes
- [ ] Covers realistic scenario

---

### 0006.7: Add Dense Order Book Integration Test
**Effort**: M (2-3h)  
**Dependencies**: None

**Actions**:
- Create test with 100+ orders
- Test multiple path discovery
- Verify performance acceptable
- Add to PathFinderServiceIntegrationTest.php

**Done When**:
- [ ] Dense order book test added
- [ ] Multiple paths found correctly
- [ ] Performance acceptable

---

### 0006.8: Add Tolerance Boundary Integration Tests
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Test min=max tolerance (zero flexibility)
- Test very wide tolerance
- Test tolerance at 0 and near 1.0
- Add to PathFinderServiceIntegrationTest.php

**Done When**:
- [ ] Tolerance boundary tests added
- [ ] All edge cases covered
- [ ] Tests pass

---

### 0006.9: Add Guard Breach Integration Test
**Effort**: S (1-2h)  
**Dependencies**: None

**Actions**:
- Test search that hits guard limits
- Verify partial results returned
- Verify guard metadata accurate
- Add to PathFinderServiceGuardsTest.php

**Done When**:
- [ ] Guard breach test added
- [ ] Metadata verified
- [ ] Test passes

---

### 0006.10: Test All OrderFilter Implementations
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Test AmountRangeFilter
- Test ToleranceWindowFilter  
- Test CurrencyPairFilter (if exists)
- Test filter combinations
- Add to OrderFiltersTest.php

**Done When**:
- [ ] All filters tested
- [ ] Edge cases covered
- [ ] Filter combinations tested

---

### 0006.11: Test Custom PathOrderStrategy
**Effort**: S (1-2h)  
**Dependencies**: None

**Actions**:
- Create and test custom ordering strategy
- Test with equal-cost paths
- Verify determinism
- Add to PathOrderStrategyTest.php

**Done When**:
- [ ] Custom strategy tested
- [ ] Determinism verified
- [ ] Test demonstrates usage

---

### 0006.12: Test FeePolicy Edge Cases
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Test zero-fee policy
- Test high-fee policy (fee > amount)
- Test multi-currency fees
- Test FeeBreakdown accumulation
- Add to FeePolicyTest.php

**Done When**:
- [ ] Fee edge cases tested
- [ ] All scenarios covered
- [ ] Tests pass

---

### 0006.13: Add JSON Serialization Round-Trip Tests
**Effort**: M (2-3h)  
**Dependencies**: None

**Actions**:
- Test PathResult serialization and verify structure
- Test SearchOutcome serialization
- Test with extreme values
- Test with various scales
- Add to SerializationTest.php (create)

**Done When**:
- [ ] Round-trip tests added
- [ ] Structure verified against docs
- [ ] Edge cases covered

---

### 0006.14: Test Documentation Examples
**Effort**: M (2-3h)  
**Dependencies**: None

**Actions**:
- Extract all README code examples
- Create tests that run them
- Verify output matches docs
- Add to Documentation/ReadmeExamplesTest.php (create)

**Done When**:
- [ ] All README examples tested
- [ ] Tests pass
- [ ] Examples verified current

---

### 0006.15: Review Property Test Iteration Counts
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Review current iteration counts
- Check InfectionIterationLimiter behavior
- Determine if counts adequate
- Document findings

**Done When**:
- [ ] Iteration counts reviewed
- [ ] Adequacy determined
- [ ] Recommendations documented

---

### 0006.16: Review Mutation Testing Report
**Effort**: M (2-3h)  
**Dependencies**: None

**Actions**:
- Run INFECTION=1 XDEBUG_MODE=coverage vendor/bin/infection
- Analyze surviving mutants
- Identify high-value mutants to kill
- List areas needing better tests

**Done When**:
- [ ] Mutation report analyzed
- [ ] Surviving mutants catalogued
- [ ] Test gaps identified

---

### 0006.17: Add Tests to Kill High-Value Mutants
**Effort**: L (3-5h)  
**Dependencies**: 0006.16

**Actions**:
- Focus on critical code paths
- Add tests targeting surviving mutants
- Re-run Infection to verify
- Document improved mutation score

**Done When**:
- [ ] High-value mutants killed
- [ ] Mutation score improved
- [ ] Critical paths better tested

---

### 0006.18: Add Concurrency/Immutability Tests
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Test value objects cannot be mutated
- Test OrderBook reuse across searches
- Test PathSearchConfig reuse
- Add to ConcurrencyTest.php (create)

**Done When**:
- [ ] Immutability verified
- [ ] Reuse safety verified
- [ ] Tests comprehensive

---

## 0007: Documentation Completeness and DX

### 0007.1: Create docs/exceptions.md
**Effort**: M (2-3h)  
**Dependencies**: Task 0005 complete

**Actions**:
- List all exception types
- Document when each is thrown
- Provide examples
- Document catch strategies
- Link from README

**Done When**:
- [ ] docs/exceptions.md created
- [ ] All exceptions documented
- [ ] Linked from README

---

### 0007.2: Create docs/api-contracts.md
**Effort**: M (2-3h)  
**Dependencies**: Task 0001 complete

**Actions**:
- Document JSON structures
- Include PathResult, SearchOutcome, SearchGuardReport
- Add versioning policy
- Link from README

**Done When**:
- [ ] docs/api-contracts.md created
- [ ] All JSON contracts documented
- [ ] Linked from README

---

### 0007.3: Create docs/domain-invariants.md
**Effort**: M (2-3h)  
**Dependencies**: Task 0002 complete

**Actions**:
- List all value object constraints
- Document valid ranges
- Document currency format requirements
- Link from README

**Done When**:
- [ ] docs/domain-invariants.md created
- [ ] All invariants documented
- [ ] Linked from README

---

### 0007.4: Create docs/troubleshooting.md
**Effort**: M (2-3h)  
**Dependencies**: None

**Actions**:
- Document common issues (no paths, guard limits, precision, currency mismatch)
- Add solutions for each
- Add performance troubleshooting
- Link from README

**Done When**:
- [ ] docs/troubleshooting.md created
- [ ] Common issues covered
- [ ] Solutions provided

---

### 0007.5: Create docs/getting-started.md
**Effort**: M (2-3h)  
**Dependencies**: None

**Actions**:
- Write installation instructions
- Create first simple example
- Explain results
- Add next steps
- Link prominently from README

**Done When**:
- [ ] docs/getting-started.md created
- [ ] Simple, clear onboarding path
- [ ] Linked from README

---

### 0007.6: Enhance README - Add Table of Contents
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Add table of contents near top
- Link to all major sections
- Make navigation easier

**Done When**:
- [ ] TOC added to README
- [ ] All links working
- [ ] Navigation improved

---

### 0007.7: Enhance README - Move Quick-Start Higher
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Move quick-start scenarios up (after requirements)
- Make it easier to find first example
- Simplify if too complex

**Done When**:
- [ ] Quick-start moved higher
- [ ] Easier for new users to find
- [ ] Examples clear

---

### 0007.8: Enhance README - Add Extension Points Section
**Effort**: M (2h)  
**Dependencies**: Task 0001 extension examples complete

**Actions**:
- Add dedicated "Extension Points" section
- Show inline examples of custom filter, strategy, fee policy
- Link to full examples in examples/

**Done When**:
- [ ] Extension Points section added
- [ ] Inline examples provided
- [ ] Links to full examples

---

### 0007.9: Enhance README - Add Common Patterns Section
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Document pre-filtering order books
- Document choosing guard limits
- Document interpreting outcomes
- Document error handling

**Done When**:
- [ ] Common Patterns section added
- [ ] Best practices documented
- [ ] Examples provided

---

### 0007.10: Enhance README - Add Documentation Index
**Effort**: S (1h)  
**Dependencies**: 0007.1-0007.5

**Actions**:
- Add section at bottom linking all docs
- Organize by category
- Make docs discoverable

**Done When**:
- [ ] Documentation index added
- [ ] All docs linked
- [ ] Well organized

---

### 0007.11: Audit PHPDoc Comments
**Effort**: L (3-4h)  
**Dependencies**: None

**Actions**:
- Review all public methods for complete @param/@return/@throws
- Add @example tags where helpful
- Add @see tags for related classes
- Ensure @internal tags correct

**Done When**:
- [ ] All public methods have complete PHPDoc
- [ ] Examples added where helpful
- [ ] Cross-references added

---

### 0007.12: Create examples/custom-order-filter.php
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Implement realistic custom filter
- Show how to use with PathFinderService
- Add comments explaining design
- Test that it works

**Done When**:
- [ ] Example created and tested
- [ ] Well commented
- [ ] Demonstrates best practices

---

### 0007.13: Create examples/custom-ordering-strategy.php
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Implement custom PathOrderStrategy
- Show different prioritization (e.g., minimize hops)
- Demonstrate usage
- Test that it works

**Done When**:
- [ ] Example created and tested
- [ ] Shows alternative ordering
- [ ] Well documented

---

### 0007.14: Create examples/custom-fee-policy.php
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Implement custom FeePolicy
- Show percentage fees, tiered fees
- Demonstrate usage
- Test that it works

**Done When**:
- [ ] Example created and tested
- [ ] Shows realistic fees
- [ ] Well documented

---

### 0007.15: Create examples/error-handling.php
**Effort**: M (2h)  
**Dependencies**: 0007.1

**Actions**:
- Show try/catch patterns
- Show guard limit handling
- Show empty result handling
- Show validation error handling

**Done When**:
- [ ] Example created and tested
- [ ] All error types covered
- [ ] Best practices shown

---

### 0007.16: Create examples/performance-optimization.php
**Effort**: M (2-3h)  
**Dependencies**: None

**Actions**:
- Show order book pre-filtering
- Show guard limit tuning
- Show tolerance optimization
- Include mini-benchmarks

**Done When**:
- [ ] Example created and tested
- [ ] Optimization techniques shown
- [ ] Performance impact demonstrated

---

### 0007.17: Update examples/README.md
**Effort**: S (1h)  
**Dependencies**: 0007.12-0007.16

**Actions**:
- List all examples with descriptions
- Explain when to use each
- Organize by topic

**Done When**:
- [ ] examples/README.md updated
- [ ] All examples listed
- [ ] Usage guidance provided

---

### 0007.18: Create docs/architecture.md
**Effort**: M (2-3h)  
**Dependencies**: None

**Actions**:
- Create layer diagram (Domain → Application → Public API)
- Create component diagram
- Create sequence diagram (search flow)
- Use ASCII art or mermaid

**Done When**:
- [ ] docs/architecture.md created
- [ ] Diagrams included
- [ ] Architecture clear

---

### 0007.19: Enhance docs/decimal-strategy.md
**Effort**: S (1-2h)  
**Dependencies**: Task 0003 complete

**Actions**:
- Add concrete examples
- Add visual table of scale usage
- Add troubleshooting section

**Done When**:
- [ ] Examples added
- [ ] Table created
- [ ] More accessible

---

### 0007.20: Enhance docs/memory-characteristics.md
**Effort**: S (1-2h)  
**Dependencies**: None

**Actions**:
- Add order book optimization section
- Add filter strategies section
- Add decision tree for guard limits

**Done When**:
- [ ] Optimization guidance added
- [ ] More actionable
- [ ] Decision support provided

---

## 0008: Semantic Versioning Readiness

### 0008.1: Create docs/versioning.md
**Effort**: M (2-3h)  
**Dependencies**: Task 0001 complete

**Actions**:
- Document semver compliance
- Define what is public API
- Define what is NOT public API
- Document pre-1.0 vs post-1.0 semantics

**Done When**:
- [ ] docs/versioning.md created
- [ ] Versioning policy clear
- [ ] API boundaries defined

---

### 0008.2: Document BC Break Policy
**Effort**: M (2h)  
**Dependencies**: 0008.1

**Actions**:
- Define what constitutes BC break
- Define what is NOT BC break
- Document how BC breaks are communicated
- Add to docs/versioning.md

**Done When**:
- [ ] BC break policy documented
- [ ] Examples provided
- [ ] Policy clear

---

### 0008.3: Define Deprecation Policy
**Effort**: S (1-2h)  
**Dependencies**: 0008.1

**Actions**:
- Document deprecation process
- Show deprecation annotation format
- Define minimum deprecation period
- Add to docs/versioning.md

**Done When**:
- [ ] Deprecation policy documented
- [ ] Process clear
- [ ] Examples provided

---

### 0008.4: Create docs/release-process.md
**Effort**: M (2-3h)  
**Dependencies**: None

**Actions**:
- Document pre-release checklist
- Document release steps
- Document post-release steps
- Document hotfix process

**Done When**:
- [ ] docs/release-process.md created
- [ ] Process documented step-by-step
- [ ] Hotfix process included

---

### 0008.5: Create docs/support.md
**Effort**: M (2h)  
**Dependencies**: None

**Actions**:
- Document PHP version support policy
- Document bug fix support policy
- Document security fix support policy
- Document dependency update policy

**Done When**:
- [ ] docs/support.md created
- [ ] All support policies defined
- [ ] Timeline clear

---

### 0008.6: Add License Field to composer.json
**Effort**: XS (<30min)  
**Dependencies**: None

**Actions**:
- Check LICENSE file for license type
- Add "license" field to composer.json
- Verify it's OSI-approved
- Commit change

**Done When**:
- [ ] License field added
- [ ] Matches LICENSE file
- [ ] Composer validate passes

---

### 0008.7: Structure CHANGELOG for Versioning
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Add version section structure
- Add categories (Added, Changed, Deprecated, Removed, Fixed, Security)
- Move Unreleased content to appropriate version
- Decide: 1.0.0-rc1 or 1.0.0

**Done When**:
- [ ] CHANGELOG structured
- [ ] Categories present
- [ ] Ready for versioned releases

---

### 0008.8: Create docs/upgrading.md Template
**Effort**: S (1h)  
**Dependencies**: 0008.2

**Actions**:
- Create upgrade guide template
- Show format for version-to-version upgrades
- Include BC break migration examples

**Done When**:
- [ ] docs/upgrading.md created
- [ ] Template clear
- [ ] Ready for future use

---

### 0008.9: Link All Versioning Docs from README
**Effort**: XS (<30min)  
**Dependencies**: 0008.1, 0008.4, 0008.5

**Actions**:
- Add links to versioning.md, release-process.md, support.md
- Add to documentation index section
- Test links

**Done When**:
- [ ] All links added to README
- [ ] Links working
- [ ] Easy to find

---

## 0009: Packaging & Repository Hygiene

### 0009.1: Review and Enhance composer.json
**Effort**: S (1h)  
**Dependencies**: 0008.6 (license)

**Actions**:
- Review keywords - add more if helpful
- Review description - ensure accurate
- Consider adding platform extensions explicitly
- Add optimize-autoloader config if missing

**Done When**:
- [ ] composer.json reviewed
- [ ] Improvements made
- [ ] Composer validate passes

---

### 0009.2: Create/Update .gitattributes
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Create .gitattributes if missing
- Add export-ignore rules for tests, docs, CI, examples
- Add line ending normalization
- Test export works correctly

**Done When**:
- [ ] .gitattributes created/updated
- [ ] Export tested
- [ ] Unnecessary files excluded from dist

---

### 0009.3: Review and Update .gitignore
**Effort**: S (30min)  
**Dependencies**: None

**Actions**:
- Verify all generated files ignored
- Verify IDE files ignored (.idea, .vscode)
- Verify OS files ignored (.DS_Store)
- Decide on .phpbench/storage/ (keep for baseline?)

**Done When**:
- [ ] .gitignore reviewed and updated
- [ ] All necessary files ignored
- [ ] Baseline decision made

---

### 0009.4: Verify LICENSE File
**Effort**: XS (<30min)  
**Dependencies**: None

**Actions**:
- Read LICENSE file
- Verify it's OSI-approved
- Check copyright year and holder
- Ensure matches composer.json

**Done When**:
- [ ] LICENSE verified
- [ ] Matches composer.json
- [ ] Copyright current

---

### 0009.5: Add README Badges
**Effort**: S (30-60min)  
**Dependencies**: None

**Actions**:
- Consider adding: PHP Version, License, Latest Release
- Don't overdo it - keep minimal
- Test badges work

**Done When**:
- [ ] Useful badges added
- [ ] Badges working
- [ ] Not cluttered

---

### 0009.6: Review GitHub Repository Settings
**Effort**: S (30min)  
**Dependencies**: None

**Actions**:
- Verify description matches composer.json
- Add topics/tags
- Set website URL
- Verify license detected

**Done When**:
- [ ] All settings verified
- [ ] Description matches
- [ ] Topics added

---

### 0009.7: Create GitHub Issue Templates
**Effort**: M (1-2h)  
**Dependencies**: None

**Actions**:
- Create .github/ISSUE_TEMPLATE/bug_report.md
- Create .github/ISSUE_TEMPLATE/feature_request.md
- Make them helpful but not burdensome

**Done When**:
- [ ] Issue templates created
- [ ] Templates tested
- [ ] Helpful format

---

### 0009.8: Create GitHub PR Template
**Effort**: S (30min)  
**Dependencies**: None

**Actions**:
- Create .github/PULL_REQUEST_TEMPLATE.md
- Include description, related issues, checklist
- Keep it reasonable

**Done When**:
- [ ] PR template created
- [ ] Template tested
- [ ] Checklist useful

---

### 0009.9: Optional: Create FUNDING.yml
**Effort**: XS (<15min)  
**Dependencies**: None

**Actions**:
- Create .github/FUNDING.yml if funding desired
- Add GitHub sponsor link or other platforms
- Keep it subtle

**Done When**:
- [ ] Decision made on funding
- [ ] If yes: FUNDING.yml created

---

### 0009.10: Run Code Style Check
**Effort**: S (30min-1h)  
**Dependencies**: None

**Actions**:
- Run vendor/bin/php-cs-fixer fix --dry-run --diff
- Fix any violations
- Commit fixes

**Done When**:
- [ ] Style check run
- [ ] All violations fixed
- [ ] Code style clean

---

### 0009.11: Review Static Analysis Baselines
**Effort**: S (1h)  
**Dependencies**: None

**Actions**:
- Check phpstan-baseline.neon (already empty ✓)
- Check for psalm baseline
- Review any suppressed issues

**Done When**:
- [ ] Baselines reviewed
- [ ] Empty or justified
- [ ] No unnecessary suppressions

---

### 0009.12: Optional: Set Up Dependabot
**Effort**: S (30min)  
**Dependencies**: None

**Actions**:
- Decide if Dependabot desired
- Create .github/dependabot.yml if yes
- Configure for composer, weekly updates

**Done When**:
- [ ] Decision made
- [ ] If yes: Dependabot configured

---

### 0009.13: Run composer validate
**Effort**: XS (<15min)  
**Dependencies**: 0009.1

**Actions**:
- Run composer validate --strict
- Fix any warnings or errors
- Ensure clean validation

**Done When**:
- [ ] Validation run
- [ ] All issues fixed
- [ ] Clean result

---

### 0009.14: Test Package Installation
**Effort**: S (30min)  
**Dependencies**: All 0009 tasks

**Actions**:
- Create fresh directory
- Install package: composer require somework/p2p-path-finder:dev-main
- Verify autoloading works
- Run simple example

**Done When**:
- [ ] Installation tested
- [ ] Works without errors
- [ ] Autoloading verified

---

## 0010-0014: Remaining Tasks

Due to space constraints, tasks 0010-0014 (P3/P4) can be broken down similarly if needed. These are:

- **0010**: Internal Code Organization - split into namespace documentation, reorganization evaluation, architecture docs
- **0011**: Performance Optimization - split into profiling runs, hotspot analysis, optimization implementation
- **0012**: Property Test Expansion - split per value object, property type, documentation
- **0013**: Test Fixture Refactoring - split by factory type, utility organization, documentation
- **0014**: Future Enhancements - split by research area, each a mini-investigation

**Would you like me to create detailed breakdowns for these remaining tasks (0010-0014) as well?**

---

## Summary Statistics

### P1 Tasks (0001-0005): ~60 subtasks
- **0001**: 12 subtasks (~8-12 hours)
- **0002**: 14 subtasks (~12-16 hours)
- **0003**: 12 subtasks (~10-14 hours)
- **0004**: 20 subtasks (~18-24 hours)
- **0005**: 15 subtasks (~10-14 hours)

### P2 Tasks (0006-0009): ~52 subtasks
- **0006**: 18 subtasks (~18-24 hours)
- **0007**: 20 subtasks (~24-30 hours)
- **0008**: 9 subtasks (~8-12 hours)
- **0009**: 14 subtasks (~6-10 hours)

### Total P1+P2: ~112 subtasks
### Estimated effort: ~130-170 hours of work

Each subtask is designed to be completable in 1-4 hours, making them actionable and trackable. Subtasks can be distributed across multiple developers or tackled sequentially.

