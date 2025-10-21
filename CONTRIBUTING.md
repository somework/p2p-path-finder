# Contributing

Thank you for your interest in improving **p2p-path-finder**! This guide summarises the
expectations for contributors so changes can be reviewed quickly and shipped with
confidence.

## Getting started

1. Fork the repository and clone it locally.
2. Install dependencies with `composer install`.
3. Read the [local development notes](docs/local-development.md) for tips on setting up
   the required `ext-bcmath` extension.

## Development workflow

- Follow the project coding style by running:
  ```bash
  vendor/bin/phpunit --testdox
  vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --level=max
  vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff
  ```
  Run the fixer without `--dry-run` if the diff highlights style issues.
- Keep new public API surface area documented with phpdoc blocks where appropriate and add
  unit tests that cover the behaviour you are changing.
- Update the [CHANGELOG](CHANGELOG.md) for user-visible changes, especially when a new
  feature advances the path toward the `1.0.0-rc` milestone.

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
