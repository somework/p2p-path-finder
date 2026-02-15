# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PHP 8.2+ library for deterministic peer-to-peer path finding across order-driven currency conversions. Uses `brick/math` BigDecimal for arbitrary precision arithmetic (no floating-point). Finds optimal multi-hop, split/merge execution plans through an order book graph.

## Commands

A `Makefile` wraps all Docker Compose commands. Run `make help` for the full list.

```bash
# Tests
make test                                    # full suite (1814 tests)
make test ARGS="tests/Unit/.../FooTest.php"  # single file
make test ARGS="--filter test_name"          # single method

# Static analysis (both must pass with zero errors)
make phpstan
make psalm

# Code style
make cs-check   # check (dry-run)
make cs-fix     # auto-fix

# Combined checks
make check       # PHPStan + Psalm + CS Fixer dry-run
make check-full  # above + PHPUnit + Infection

# Other
make infection   # mutation testing
make bench       # benchmarks (only for performance changes)
make examples    # run all examples
make shell       # open bash in PHP container
```

## Architecture

### Layered Structure (DDD)

**Domain Layer** (`src/Domain/`) — Immutable value objects, no dependencies on application layer:
- `Money` — monetary amount with arbitrary precision decimal and scale
- `Order`, `OrderBook` — tradeable orders with bounds, fees, and asset pairs
- `AssetPair`, `ExchangeRate` — currency pairs (conversion vs transfer) and rates
- `FeePolicy` (interface) — pluggable fee calculation
- `OrderFilterInterface` — pluggable order filtering
- `DecimalTolerance`, `ToleranceWindow` — tolerance constraints
- `DecimalHelperTrait` — shared BigDecimal parsing/scaling helpers (canonical scale=18)

**Application Layer** (`src/Application/PathSearch/`):
- `ExecutionPlanService::findBestPlans()` — **main public API entry point**. Takes `PathSearchRequest`, returns `SearchOutcome<ExecutionPlan>`
- `ExecutionPlanSearchEngine` — successive shortest augmenting paths algorithm (Dijkstra with `PortfolioState` tracking multi-currency balances). Uses SplPriorityQueue with 9's complement string keys for min-heap behavior
- `GraphBuilder` → `Graph` → `GraphNodeCollection` → `GraphNode` → `GraphEdgeCollection` → `GraphEdge` — directed graph from orders
- `ExecutionPlanMaterializer` + `LegMaterializer` — converts raw fills into `ExecutionPlan` with fees
- `ToleranceEvaluator` — validates residual tolerance between requested and actual spend
- `PathOrderStrategy` (interface) — pluggable result ranking; default `CostHopsSignatureOrderingStrategy`

**Configuration**: `PathSearchConfig` via builder pattern — spend amount, tolerance bounds, hop limits, result limit (Top-K), disjoint mode, guard config (max expansions, visited states, time budget).

### Top-K Search Modes

The service finds up to K best execution plans (`withResultLimit(K)`):
- **Disjoint** (default, `withDisjointPlans(true)`) — each plan uses completely different orders via iterative exclusion with `Graph::withoutOrders()`
- **Reusable** (`withDisjointPlans(false)`) — plans may share orders, diversity via `Graph::withOrderPenalties()` penalty-based scoring

### Key Extension Points (Strategy Pattern)

- `PathOrderStrategy` — custom result ordering
- `FeePolicy` — custom fee calculation per order
- `OrderFilterInterface` — custom order filtering

## Testing Conventions

- Run via Makefile: `make test` (or `make test ARGS="--filter test_name"`)
- Test suites: Unit, Integration, Helpers, Fixture (configured in `phpunit.xml.dist`)
- Do **not** use `assertSame()` or `assertEquals()` on whole objects — compare specific properties or use domain equality methods like `Money::equals()`
- Use `#[TestDox('...')]` attributes on test methods
- Tests for `src/Foo/Bar.php` go in `tests/Unit/Foo/BarTest.php`

## Code Style

- PSR-12 + Symfony rules enforced by PHP-CS-Fixer
- `declare(strict_types=1)` required in all files
- Ordered imports: class, then function, then const (alphabetical within each group)
- All value objects are immutable
- Use `RoundingMode::HalfUp` (not the deprecated `HALF_UP` constant)
- Use `@var numeric-string` annotations when `BigDecimal::__toString()` feeds a `numeric-string` parameter

## CI Pipeline

GitHub Actions runs on every push: PHPStan (max + custom rules), Psalm (strict), PHP-CS-Fixer, PHPUnit (PHP 8.2 + 8.3 + 8.4), Infection mutation testing, PhpBench, example validation, and custom PHPStan rules verification. All must pass.
