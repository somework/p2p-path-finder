# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PHP 8.2+ library for deterministic peer-to-peer path finding across order-driven currency conversions. Uses `brick/math` BigDecimal for arbitrary precision arithmetic (no floating-point). Finds optimal multi-hop, split/merge execution plans through an order book graph.

## Common Commands

```bash
# Run all tests
vendor/bin/phpunit --testdox

# Run a single test file
vendor/bin/phpunit tests/Unit/Application/PathSearch/Config/PathSearchConfigTest.php

# Run a single test method
vendor/bin/phpunit --filter test_method_name

# Static analysis (must pass at max level with zero errors)
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --level=max

# Code style check (dry-run)
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff

# Auto-fix code style
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php

# Quick pre-commit check (PHPStan + Psalm + CS Fixer dry-run)
composer check

# Full check (above + PHPUnit + Infection mutation testing)
composer check:full

# Run all examples
composer examples

# Run a specific example
composer examples:execution-plan-basic

# Benchmarks (only for performance-related changes)
composer phpbench
```

Docker alternative (mirrors CI environment):
```bash
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php vendor/bin/phpstan analyse
```

## Architecture

### Layered Structure (DDD)

**Domain Layer** (`src/Domain/`) — Immutable value objects, no dependencies on application layer:
- `Money` — monetary amount with arbitrary precision decimal
- `Order`, `OrderBook` — tradeable orders and collections
- `AssetPair`, `ExchangeRate` — currency pairs and rates
- `FeePolicy` (interface) — pluggable fee calculation
- `OrderFilterInterface` — pluggable order filtering
- `DecimalTolerance`, `ToleranceWindow` — tolerance constraints

**Application Layer** (`src/Application/`) — Services and algorithm:
- `ExecutionPlanService::findBestPlans()` — **main public API entry point**. Takes a `PathSearchRequest`, returns `SearchOutcome<ExecutionPlan>`.
- `GraphBuilder` — constructs a directed graph from orders
- `ExecutionPlanSearchEngine` — low-level BFS/priority-queue path discovery with guard rails (max expansions, visited states, time budget)
- `ExecutionPlanMaterializer` — converts raw fills into `ExecutionPlan` objects
- `OrderSpendAnalyzer` — filters and constrains orders
- `ToleranceEvaluator` — validates tolerance bounds
- `PathOrderStrategy` (interface) — pluggable result ranking; default is `CostHopsSignatureOrderingStrategy`

**Configuration**: `PathSearchConfig` via builder pattern (`PathSearchConfigBuilder`) — spend amount, tolerance bounds, hop limits, result limit (Top-K), guard config.

### Top-K Search Modes

The service finds up to K best execution plans in two modes:
- **Disjoint** (default) — each plan uses completely different orders (iterative exclusion)
- **Reusable** — plans may share orders, diversity via penalty-based scoring

### Key Extension Points (Strategy Pattern)

- `PathOrderStrategy` — custom result ordering
- `FeePolicy` — custom fee calculation per order
- `OrderFilterInterface` — custom order filtering (built-in: `CurrencyPairFilter`, `MinimumAmountFilter`, `MaximumAmountFilter`, `ToleranceWindowFilter`)

## Testing Conventions

- Do **not** use `assertSame()` or `assertEquals()` on whole objects — compare specific properties or use domain equality methods like `Money::equals()`
- Test suites: Unit, Integration, Helpers, Fixture (configured in `phpunit.xml.dist`)

## Code Style

- PSR-12 + Symfony rules enforced by PHP-CS-Fixer
- `declare(strict_types=1)` required in all files
- Ordered imports (class, function, const)
- All value objects are immutable
