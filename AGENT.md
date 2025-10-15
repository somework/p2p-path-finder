# Development Guidelines

## Testing Requirements
You should create PHPUnit tests for all functional code.
You should use PHPUnit tests to verify your code works as expected.
You should use PHPUnit tests to verify your code does not break existing functionality.

## Local Verification (Mirror GitHub Actions Pipelines)

Before committing, ALWAYS run these commands locally to ensure CI will pass:

### 1. PHPUnit Tests (verbose, no coverage for speed)
```bash
vendor/bin/phpunit --testdox
```
This shows a readable summary of what was tested.

### 2. PHPStan Static Analysis (max level)
```bash
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --level=max
```
This must pass with zero errors.

### 3. PHP-CS-Fixer Code Style (dry-run with diff)
```bash
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff
```
Shows what would be changed. To auto-fix:
```bash
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php
```

### 4. Infection Mutation Testing (optional, slower)
```bash
XDEBUG_MODE=coverage vendor/bin/infection --threads=4 --min-msi=0 --min-covered-msi=0
```
Only run when you want to verify test quality.

### 5. PhpBench Performance (optional)
```bash
vendor/bin/phpbench run --config=phpbench.json.dist --report=aggregate --iterations=1 --revs=1
```
Only run when making performance-related changes.

## Quick Check (Run This Before Every Commit)
```bash
vendor/bin/phpunit --testdox && \
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --level=max && \
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff
```
