<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\SearchGuardConfig;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

#[CoversClass(SearchGuardConfig::class)]
final class SearchGuardConfigTest extends TestCase
{
    #[TestDox('defaults() returns config with default max visited states')]
    public function test_defaults_returns_default_max_visited_states(): void
    {
        $config = SearchGuardConfig::defaults();

        self::assertSame(SearchGuardConfig::DEFAULT_MAX_VISITED_STATES, $config->maxVisitedStates());
    }

    #[TestDox('defaults() returns config with default max expansions')]
    public function test_defaults_returns_default_max_expansions(): void
    {
        $config = SearchGuardConfig::defaults();

        self::assertSame(SearchGuardConfig::DEFAULT_MAX_EXPANSIONS, $config->maxExpansions());
    }

    #[TestDox('defaults() returns config with null time budget')]
    public function test_defaults_returns_null_time_budget(): void
    {
        $config = SearchGuardConfig::defaults();

        self::assertNull($config->timeBudgetMs());
    }

    #[TestDox('Constructor accepts custom max visited states')]
    public function test_constructor_accepts_custom_max_visited_states(): void
    {
        $config = new SearchGuardConfig(maxVisitedStates: 500);

        self::assertSame(500, $config->maxVisitedStates());
    }

    #[TestDox('Constructor accepts custom max expansions')]
    public function test_constructor_accepts_custom_max_expansions(): void
    {
        $config = new SearchGuardConfig(maxExpansions: 1000);

        self::assertSame(1000, $config->maxExpansions());
    }

    #[TestDox('Constructor accepts custom time budget')]
    public function test_constructor_accepts_custom_time_budget(): void
    {
        $config = new SearchGuardConfig(timeBudgetMs: 5000);

        self::assertSame(5000, $config->timeBudgetMs());
    }

    #[TestDox('Constructor accepts all custom values together')]
    public function test_constructor_accepts_all_custom_values(): void
    {
        $config = new SearchGuardConfig(
            maxVisitedStates: 100,
            maxExpansions: 200,
            timeBudgetMs: 3000,
        );

        self::assertSame(100, $config->maxVisitedStates());
        self::assertSame(200, $config->maxExpansions());
        self::assertSame(3000, $config->timeBudgetMs());
    }

    #[TestDox('Constructor rejects zero max visited states')]
    public function test_constructor_rejects_zero_max_visited_states(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Maximum visited states must be at least one.');

        new SearchGuardConfig(maxVisitedStates: 0);
    }

    #[TestDox('Constructor rejects negative max visited states')]
    public function test_constructor_rejects_negative_max_visited_states(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Maximum visited states must be at least one.');

        new SearchGuardConfig(maxVisitedStates: -1);
    }

    #[TestDox('Constructor rejects zero max expansions')]
    public function test_constructor_rejects_zero_max_expansions(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Maximum expansions must be at least one.');

        new SearchGuardConfig(maxExpansions: 0);
    }

    #[TestDox('Constructor rejects negative max expansions')]
    public function test_constructor_rejects_negative_max_expansions(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Maximum expansions must be at least one.');

        new SearchGuardConfig(maxExpansions: -1);
    }

    #[TestDox('Constructor rejects zero time budget')]
    public function test_constructor_rejects_zero_time_budget(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Time budget must be at least one millisecond.');

        new SearchGuardConfig(timeBudgetMs: 0);
    }

    #[TestDox('Constructor rejects negative time budget')]
    public function test_constructor_rejects_negative_time_budget(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Time budget must be at least one millisecond.');

        new SearchGuardConfig(timeBudgetMs: -100);
    }

    #[TestDox('withTimeBudget() returns new instance with specified time budget')]
    public function test_with_time_budget_returns_new_instance(): void
    {
        $original = SearchGuardConfig::defaults();
        $modified = $original->withTimeBudget(2000);

        self::assertNull($original->timeBudgetMs());
        self::assertSame(2000, $modified->timeBudgetMs());
    }

    #[TestDox('withTimeBudget() preserves other values')]
    public function test_with_time_budget_preserves_other_values(): void
    {
        $original = new SearchGuardConfig(maxVisitedStates: 100, maxExpansions: 200);
        $modified = $original->withTimeBudget(5000);

        self::assertSame(100, $modified->maxVisitedStates());
        self::assertSame(200, $modified->maxExpansions());
        self::assertSame(5000, $modified->timeBudgetMs());
    }

    #[TestDox('withTimeBudget(null) clears the time budget')]
    public function test_with_time_budget_null_clears_budget(): void
    {
        $config = new SearchGuardConfig(timeBudgetMs: 5000);
        $modified = $config->withTimeBudget(null);

        self::assertNull($modified->timeBudgetMs());
    }

    #[TestDox('withTimeBudget() rejects zero')]
    public function test_with_time_budget_rejects_zero(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Time budget must be at least one millisecond.');

        SearchGuardConfig::defaults()->withTimeBudget(0);
    }

    #[TestDox('Constructor accepts minimum valid values (1 for each)')]
    public function test_constructor_accepts_minimum_valid_values(): void
    {
        $config = new SearchGuardConfig(
            maxVisitedStates: 1,
            maxExpansions: 1,
            timeBudgetMs: 1,
        );

        self::assertSame(1, $config->maxVisitedStates());
        self::assertSame(1, $config->maxExpansions());
        self::assertSame(1, $config->timeBudgetMs());
    }

    #[TestDox('Default constants have expected values')]
    public function test_default_constants(): void
    {
        self::assertSame(250000, SearchGuardConfig::DEFAULT_MAX_VISITED_STATES);
        self::assertSame(250000, SearchGuardConfig::DEFAULT_MAX_EXPANSIONS);
    }
}
