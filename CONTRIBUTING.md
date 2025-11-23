# Contributing

Thank you for your interest in improving **p2p-path-finder**! This guide summarises the
expectations for contributors so changes can be reviewed quickly and shipped with
confidence.

## Getting started

1. Fork the repository and clone it locally.
2. Install dependencies with `composer install`.
3. Run tests locally with `composer phpunit` and quality checks with `composer check`.

## Development workflow

- Follow the project coding style by running:
  ```bash
  # Quick quality checks (recommended before commit)
  composer check
  
  # Or run individually:
  vendor/bin/phpunit --testdox
  vendor/bin/phpstan analyse    # Includes custom decimal arithmetic rules
  vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff
  
  # Full quality suite (includes mutation testing)
  composer check:full
  ```
  Run the fixer without `--dry-run` if the diff highlights style issues.
  
  **Note**: PHPStan automatically runs custom rules that enforce decimal arithmetic
  consistency. See the "Decimal arithmetic guidelines" section below for details.
- Keep new public API surface area documented with phpdoc blocks where appropriate and add
  unit tests that cover the behaviour you are changing.
- Update the [CHANGELOG](CHANGELOG.md) for user-visible changes, especially when a new
  feature advances the path toward the `1.0.0-rc` milestone.

### Decimal arithmetic guidelines

This project uses **custom PHPStan rules** to enforce consistent decimal arithmetic and
prevent precision errors. The rules automatically detect:

1. **Float literals in arithmetic** - All monetary calculations must use `BigDecimal` or
   `numeric-string` types, never float literals like `10.5` or `20.3`.
   
   ❌ **Bad**:
   ```php
   $result = $amount * 1.5;  // Float literal - will trigger error
   ```
   
   ✅ **Good**:
   ```php
   $result = $money->multiply('1.5');  // String literal - correct
   ```

2. **Missing RoundingMode parameter** - All `BigDecimal::toScale()` calls must include an
   explicit `RoundingMode` parameter for deterministic behavior.
   
   ❌ **Bad**:
   ```php
   $value->toScale(2);  // Missing RoundingMode - will trigger error
   ```
   
   ✅ **Good**:
   ```php
   $value->toScale(2, RoundingMode::HALF_UP);  // Explicit rounding - correct
   ```

3. **BCMath function calls** - Use `BigDecimal` methods instead of BCMath functions for
   consistency and type safety.
   
   ❌ **Bad**:
   ```php
   $sum = bcadd('10.5', '20.3', 2);  // BCMath - will trigger error
   ```
   
   ✅ **Good**:
   ```php
   $sum = BigDecimal::of('10.5')->plus('20.3')->toScale(2, RoundingMode::HALF_UP);
   ```

**Note**: Float literals are allowed in time calculations (e.g., converting seconds to
milliseconds with `* 1000.0`) where precision loss is acceptable. The rules detect this
context automatically.

- The path-finding search queue must resolve ties using the following precedence: lowest
  cost → fewest hops → lexicographically smallest route signature → earliest discovery.
  The regression suites enforce this via
  `PathFinderInternalsTest::test_search_queue_tie_breaks_by_cost_hops_signature_then_discovery`
  and
  `PathFinderTest::test_it_orders_equal_cost_paths_by_hops_signature_and_discovery`.
  Adjust those harnesses alongside any comparator changes to avoid accidental ordering
  regressions.

### Mutation testing

- Mutation testing is powered by [Infection](https://infection.github.io/) to ensure guard
  rails such as guard escalation, tie-break comparators and property helpers stay
  well-tested.
- Run it locally with:
  ```bash
  INFECTION=1 XDEBUG_MODE=coverage vendor/bin/infection --no-progress
  ```
  The command honours the thresholds defined in `infection.json.dist` (MSI ≥80, covered MSI
  ≥85). Expect the run to take roughly five minutes on a quad-core machine.
- Property-based suites detect the `INFECTION` environment variable and automatically dial
  back iteration counts to keep the feedback loop reasonable.
- A convenient alias is available via Composer: `composer infection` mirrors the CI
  configuration and is a good candidate for inclusion in custom Git hooks or lightweight
  automation jobs.

## Submitting a pull request

1. Ensure your branch is rebased on the latest `main`.
2. Fill in the PR template with a concise summary of the change, test coverage and any
   follow-up actions.
3. Link to open issues when applicable so maintainers can track progress.
4. Confirm that you have read and will follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Security

If you discover a vulnerability, please follow the process outlined in
[SECURITY.md](SECURITY.md) instead of opening a public issue.
