.PHONY: help test phpstan psalm cs-fix cs-check check check-full infection bench examples build up down shell

.DEFAULT_GOAL := help

help: ## Show available commands
	@echo "Usage: make <target> [ARGS=\"...\"]"
	@echo ""
	@echo "Targets:"
	@echo "  help        Show available commands"
	@echo "  test        Run PHPUnit tests (supports ARGS, e.g. make test ARGS=\"--filter test_name\")"
	@echo "  phpstan     Run PHPStan static analysis"
	@echo "  psalm       Run Psalm static analysis"
	@echo "  cs-fix      Auto-fix code style with PHP-CS-Fixer"
	@echo "  cs-check    Check code style (dry-run)"
	@echo "  check       Run PHPStan + Psalm + CS Fixer dry-run"
	@echo "  check-full  Run check + PHPUnit + Infection"
	@echo "  infection   Run Infection mutation testing"
	@echo "  bench       Run PhpBench benchmarks"
	@echo "  examples    Run all examples"
	@echo "  build       Build Docker images"
	@echo "  up          Start containers in background"
	@echo "  down        Stop containers"
	@echo "  shell       Open a bash shell in the PHP container"

test: ## Run PHPUnit tests
	docker compose run --rm php vendor/bin/phpunit $(ARGS)

phpstan: ## Run PHPStan static analysis
	docker compose run --rm php vendor/bin/phpstan analyse --no-progress

psalm: ## Run Psalm static analysis
	docker compose run --rm php vendor/bin/psalm --no-progress

cs-fix: ## Auto-fix code style
	docker compose run --rm php vendor/bin/php-cs-fixer fix

cs-check: ## Check code style (dry-run)
	docker compose run --rm php vendor/bin/php-cs-fixer fix --dry-run --diff

check: ## Run PHPStan + Psalm + CS Fixer dry-run
	docker compose run --rm php composer check

check-full: ## Run check + PHPUnit + Infection
	docker compose run --rm php composer check:full

infection: ## Run Infection mutation testing
	docker compose run --rm php composer infection

bench: ## Run PhpBench benchmarks
	docker compose run --rm php composer phpbench

examples: ## Run all examples
	docker compose run --rm php composer examples

build: ## Build Docker images
	docker compose build

up: ## Start containers in background
	docker compose up -d

down: ## Stop containers
	docker compose down

shell: ## Open a bash shell in the PHP container
	docker compose run --rm php bash
