# Release checklist

Use this checklist to prepare the next tagged release now that all arithmetic has migrated
to `Brick\Math\BigDecimal`.

## 1. Smoke-test determinism

1. Run the full quality gate:
   ```bash
   vendor/bin/phpunit --testdox
   vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --level=max
   vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff
   ```
2. Execute the guard example script and compare the output to the snapshot recorded in
   [`docs/audits/bigdecimal-verification.md`](audits/bigdecimal-verification.md):
   ```bash
   php examples/guarded-search-example.php
   ```
3. Re-run the PathFinder service property tests to ensure ordering and guard semantics stay
   deterministic:
   ```bash
   vendor/bin/phpunit --testdox tests/Application/Service/PathFinder/PathFinderServicePropertyTest.php
   ```

## 2. Update release artefacts

- Ensure `composer.json` describes the package so Packagist highlights the BigDecimal
  workflow.
- Extend `CHANGELOG.md` with a note covering the arithmetic migration and any API surface
  changes.
- Review the documentation set (`README.md`, `docs/*.md`, benchmarks) for lingering
  references to `BcMath` or string-based helpers.

## 3. Tag and publish

1. Create a signed tag after merging the release PR:
   ```bash
   git tag -s v1.0.0-rc1 -m "BigDecimal migration release"
   ```
2. Push the tag to GitHub and draft a release pointing to the changelog entry.
3. Share the deterministic output logs from the audit document with downstream consumers so
   they can compare their own benchmarking runs.
