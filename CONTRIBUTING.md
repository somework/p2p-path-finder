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

## Submitting a pull request

1. Ensure your branch is rebased on the latest `main`.
2. Fill in the PR template with a concise summary of the change, test coverage and any
   follow-up actions.
3. Link to open issues when applicable so maintainers can track progress.
4. Confirm that you have read and will follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Security

If you discover a vulnerability, please follow the process outlined in
[SECURITY.md](SECURITY.md) instead of opening a public issue.
