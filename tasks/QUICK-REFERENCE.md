# Quick Reference Guide - Task Breakdown

Quick visual reference for navigating the task breakdown. Use this to quickly find what you need.

## 📊 At a Glance

```
14 Major Tasks → 134+ Subtasks → ~150 hours of work

P1 (Release-blocking): 73 subtasks, 58-80 hours  ← MUST DO FOR 1.0
P2 (High impact):      61 subtasks, 56-76 hours  ← SHOULD DO FOR 1.0
P3 (Polish):          ~20 subtasks estimated     ← NICE TO HAVE
P4 (Future):           Research only             ← POST-1.0
```

## 🗺️ Navigation Map

### I want to...

**...understand the overall project**
→ Read [`README.md`](README.md) - Project overview and task list

**...see the detailed task breakdowns**
→ P1 tasks (0001-0005): [`TASK-BREAKDOWN.md`](TASK-BREAKDOWN.md)  
→ P2 tasks (0006-0009): [`TASK-BREAKDOWN-PART2.md`](TASK-BREAKDOWN-PART2.md)

**...get a summary of the breakdown**
→ Read [`TASK-BREAKDOWN-SUMMARY.md`](TASK-BREAKDOWN-SUMMARY.md)

**...see this quick reference**
→ You're here! [`QUICK-REFERENCE.md`](QUICK-REFERENCE.md)

**...understand a specific task in detail**
→ Read the original task file: `0001-high-priority-*.md` through `0014-*.md`

## 📋 Subtask Naming Convention

```
Format: XXXX.YY

XXXX = Task number (0001-0014)
YY   = Subtask sequence (01-99)

Example: 0001.3 = Task 0001, Subtask 3
```

## ⏱️ Effort Legend

```
XS  = Less than 1 hour        (Quick wins)
S   = 1-2 hours               (Half-day task)
M   = 2-4 hours               (Most of a day)
L   = 4-8 hours               (Full day or more)
XL  = More than 8 hours       (Multiple days)
```

## 🎯 P1 Tasks Quick Reference (Release-Blocking)

### 0001: Public API Finalization (12 subtasks, 8-12h)
Key deliverables:
- `docs/api-stability.md` - Public API inventory
- `docs/api-contracts.md` - JSON serialization contracts
- Extension point examples (filter, strategy, fee policy)
- @api annotations

**Start with**: 0001.1 (Public API Inventory) - Creates foundation for rest

---

### 0002: Domain Model Validation (14 subtasks, 12-16h)
Key deliverables:
- Edge case tests for Money, ExchangeRate, OrderBounds, ToleranceWindow
- `docs/domain-invariants.md` - All constraints documented
- @invariant annotations on all VOs
- Property-based tests

**Start with**: 0002.1 (Money negative policy) - Key decision needed

---

### 0003: Decimal Arithmetic Audit (12 subtasks, 10-14h)
Key deliverables:
- Complete audit of all arithmetic
- No float/bcmath usage verified
- All scales verified consistent
- All rounding uses HALF_UP
- Optional: Custom PHPStan rules

**Start with**: 0003.1-0003.3 (Grep audits) - Fast, identify issues

---

### 0004: PathFinder Correctness (20 subtasks, 18-24h)
Key deliverables:
- Tolerance handling verified
- Guard semantics correct
- Ordering determinism proven
- Mandatory segments tested
- Comprehensive algorithm tests
- Full documentation

**Start with**: 0004.1 (Tolerance amplifier review) - Core algorithm

---

### 0005: Exception Hierarchy (15 subtasks, 10-14h)
Key deliverables:
- `docs/exceptions.md` - All exceptions documented
- Exception vs null pattern consistent
- All error paths tested
- @throws tags complete
- Messages standardized

**Start with**: 0005.1-0005.2 (Error scenario audits) - Identify gaps

---

## 🎯 P2 Tasks Quick Reference (High Impact)

### 0006: Test Coverage (18 subtasks, 18-24h)
Key deliverables:
- Coverage report analysis
- Integration tests for realistic scenarios
- All extension points tested
- Mutation score ≥ 80%
- Documentation examples tested

**Start with**: 0006.1 (Coverage report) - Identify gaps
**Critical**: 0006.16-0006.17 (Mutation testing) - Quality gate

---

### 0007: Documentation (20 subtasks, 24-30h)
Key deliverables:
- `docs/getting-started.md` - Onboarding guide
- `docs/troubleshooting.md` - Common issues
- `docs/architecture.md` - System design
- `examples/` - 5+ working examples
- README enhanced

**Start with**: 0007.5 (getting-started.md) - Most valuable for users
**Critical**: 0007.6-0007.10 (README enhancements) - First impression

---

### 0008: Versioning (9 subtasks, 8-12h)
Key deliverables:
- `docs/versioning.md` - Semver policy
- `docs/release-process.md` - How to release
- `docs/support.md` - Support policy
- `docs/upgrading.md` - Migration template
- CHANGELOG structured

**Start with**: 0008.1 (versioning.md) - Foundation
**Critical**: 0008.6 (License in composer.json) - Quick win

---

### 0009: Packaging (14 subtasks, 6-10h)
Key deliverables:
- composer.json enhanced
- .gitattributes created
- .gitignore updated
- GitHub templates created
- Code style verified
- Package installation tested

**Start with**: 0009.10 (Code style check) - Quick, fixes apparent issues
**Critical**: 0009.13-0009.14 (Validation & installation) - Final checks

---

## 🔗 Dependencies Cheat Sheet

### Critical Path (These Must Go First)

```
0001.1 (API Inventory) ─┬─→ 0001.2 (Review withRunnerFactory)
                        ├─→ 0001.3 (Review value object exposure)
                        ├─→ 0001.4-0001.6 (Extension points)
                        └─→ 0001.11 (@api annotations)

0001.7 (JSON PathResult) ─┬─→ 0001.8 (JSON SearchOutcome)
                          ├─→ 0001.9 (JSON Money)
                          └─→ 0001.10 (JSON tests)

0005.1-0005.2 (Error audits) ─→ 0005.3 (Exception convention)
                               ─→ Rest of 0005

All 0001-0005 ─→ 0006 (Test Coverage)
            └─→ 0007 (Documentation)

0008.1 (Versioning policy) ─→ 0008.6 (License field)
                           ─→ 0009.1 (composer.json)
```

### Can Be Parallel

```
✅ 0001 + 0002 + 0003 can start simultaneously
✅ 0004 can overlap with 0001-0003 (may need 0003 findings)
✅ 0005 can start early, but incorporates 0001-0002 findings
✅ 0008 + 0009 can overlap with 0006-0007
```

## 📈 Progress Tracking Template

### Spreadsheet Column Headers

```
| ID | Task | Assignee | Status | Start Date | End Date | Notes | Blocked By |
```

### Status Values

```
🔴 Not Started
🟡 In Progress
🔵 In Review
🟢 Done
⚫ Blocked
```

### Example Entries

```
| 0001.1 | API Inventory       | Alice | 🟡 In Progress | 2025-11-22 | -          | Creating api-stability.md | - |
| 0001.2 | Review withRunner   | -     | 🔴 Not Started | -          | -          | -                         | 0001.1 |
| 0002.1 | Money negative      | Bob   | 🟢 Done        | 2025-11-20 | 2025-11-21 | Decided: reject negatives | - |
```

## 🚀 Implementation Timeline Estimate

### Aggressive (2 developers, full-time)
```
Week 1-2:   P1 Tasks (0001-0005)
Week 3-4:   P2 Tasks (0006-0009)
Week 5:     Buffer, final testing, RC
Total:      5 weeks to 1.0.0-rc1
```

### Moderate (2-3 developers, part-time)
```
Week 1-3:   P1 Tasks (0001-0005)
Week 4-6:   P2 Tasks (0006-0009)
Week 7:     Buffer, testing, polish
Total:      7 weeks to 1.0.0-rc1
```

### Conservative (1-2 developers, part-time)
```
Week 1-4:   P1 Tasks (0001-0005)
Week 5-8:   P2 Tasks (0006-0009)
Week 9-10:  Buffer, testing, documentation polish
Total:      10 weeks to 1.0.0-rc1
```

## 🎓 Learning Path for Contributors

### New to the project?
1. Read [`README.md`](../README.md) - Understand the library
2. Read [`tasks/README.md`](README.md) - Understand the task structure
3. Pick a **small S-effort subtask** from P2 or P3
4. Read the parent task document for context
5. Review subtask requirements in breakdown docs

### Want to make big impact?
1. Review P1 tasks (0001-0005) - These are critical
2. Pick a task that matches your expertise:
   - Good at API design? → 0001
   - Strong in testing? → 0002
   - Math/precision expert? → 0003
   - Algorithm specialist? → 0004
   - Error handling pro? → 0005
3. Start with the first subtask of that task
4. Work sequentially through subtasks

### Want to help with docs?
- Task 0007 is perfect for documentation focus
- Each subtask is independent
- Pick any 0007.XX subtask that interests you

## ❓ FAQs

**Q: Which task should I start with?**  
A: If you're maintainer/lead: 0001.1. If you're contributor: any S-effort subtask in your skill area.

**Q: Can I work on multiple subtasks at once?**  
A: Yes, but ensure they don't conflict. Check dependencies first.

**Q: What if I find the subtask scope is wrong?**  
A: That's OK! Document what you found and adjust. Update the breakdown doc for next time.

**Q: Do I need to follow the exact order?**  
A: For subtasks with dependencies: yes. Otherwise: no, but the order is optimized.

**Q: Can we skip subtasks?**  
A: Not P1 subtasks. P2: evaluate value. P3/P4: yes, they're optional.

**Q: How do I mark a subtask complete?**  
A: Verify all "Done When" checkboxes are ✅. Update your tracking system. Create PR.

## 📞 Getting Help

- **Stuck on a subtask?** Review the parent task document for more context
- **Not sure about scope?** Ask in GitHub Discussion or Issues
- **Found a bug in breakdown?** Open PR to fix the breakdown document
- **Need technical help?** Check docs/, examples/, or ask maintainers

## ✅ Quick Checklist: Is My Subtask Complete?

Before marking complete, verify:

- [ ] All "Done When" checkboxes in breakdown are ✅
- [ ] All code changes committed and pushed
- [ ] All tests pass: `vendor/bin/phpunit`
- [ ] Static analysis passes: `vendor/bin/phpstan`
- [ ] Code style passes: `vendor/bin/php-cs-fixer fix --dry-run`
- [ ] PR created with subtask ID in title
- [ ] PR description references parent task
- [ ] Tracking system updated

---

**Last Updated**: 2025-11-22  
**Version**: 1.0  
**Maintainer**: Review and update as tasks progress

