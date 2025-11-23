# Development Guidelines

## Testing Requirements
You should create PHPUnit tests for all functional code.
You should use PHPUnit tests to verify your code works as expected.
You should use PHPUnit tests to verify your code does not break existing functionality.

## Examples Guidelines
When creating or modifying examples:
- All examples must be runnable and production-ready
- Examples should demonstrate best practices and real-world patterns
- Include comprehensive inline documentation explaining design decisions
- Add the example to `composer.json` scripts section
- Update `examples/README.md` with example description and classification
- Verify examples run without errors before committing

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

### 5. Examples Verification (when adding/modifying examples)
```bash
composer examples
```
Runs all examples to ensure they execute without errors. Individual examples:
```bash
composer examples:custom-order-filter
composer examples:custom-ordering-strategy
composer examples:custom-fee-policy
composer examples:error-handling
composer examples:performance-optimization
composer examples:guarded-search
composer examples:bybit-p2p-integration
```

### 6. PhpBench Performance (optional)
```bash
vendor/bin/phpbench run --config=phpbench.json.dist --report=p2p_aggregate --iterations=1 --revs=1
```
Only run when making performance-related changes.

## Composer Scripts (Convenience Commands)

Quick access to common tasks via composer:
```bash
composer check              # Run PHPStan, Psalm, and PHP-CS-Fixer (dry-run)
composer check:full         # Full check including PHPUnit and Infection
composer phpunit            # Run PHPUnit tests
composer phpstan            # Run PHPStan analysis
composer phpstan:baseline   # Generate PHPStan baseline
composer psalm              # Run Psalm analysis
composer php-cs-fixer       # Auto-fix code style issues
composer infection          # Run Infection mutation testing
composer phpbench           # Run performance benchmarks
composer examples           # Run all examples
composer phpdoc             # Generate API documentation
```

## Quick Check (Run This Before Every Commit)

### Basic Check (Fast)
```bash
vendor/bin/phpunit --testdox && \
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --level=max && \
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff
```

### With Examples Verification (when modifying examples)
```bash
vendor/bin/phpunit --testdox && \
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --level=max && \
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff && \
composer examples
```

### Using Composer (Alternative)
```bash
composer check
```
