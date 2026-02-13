<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Config;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfigBuilder;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\SearchGuardConfig;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Tolerance\ToleranceWindow;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class PathSearchConfigTest extends TestCase
{
    public function test_it_calculates_tolerance_adjusted_spend_bounds(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.10', '0.25')
            ->withHopLimits(1, 3)
            ->build();

        self::assertSame('90.00', $config->minimumSpendAmount()->amount());
        self::assertSame('125.00', $config->maximumSpendAmount()->amount());
        self::assertSame(2, $config->minimumSpendAmount()->scale());
        self::assertSame(2, $config->maximumSpendAmount()->scale());
    }

    public function test_constructor_rejects_minimum_hops_below_one(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Minimum hops must be at least one.');

        new PathSearchConfig(
            Money::fromString('EUR', '100.00', 2),
            ToleranceWindow::fromStrings('0.0', '0.1'),
            0,
            1,
        );
    }

    public function test_constructor_rejects_maximum_hops_below_minimum(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Maximum hops must be greater than or equal to minimum hops.');

        new PathSearchConfig(
            Money::fromString('EUR', '100.00', 2),
            ToleranceWindow::fromStrings('0.0', '0.1'),
            3,
            2,
        );
    }

    public function test_constructor_rejects_result_limit_below_one(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Result limit must be at least one.');

        new PathSearchConfig(
            Money::fromString('EUR', '50.00', 2),
            ToleranceWindow::fromStrings('0.0', '0.1'),
            1,
            2,
            resultLimit: 0,
        );
    }

    public function test_constructor_rejects_max_expansions_below_one(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Maximum expansions must be at least one.');

        new PathSearchConfig(
            Money::fromString('EUR', '50.00', 2),
            ToleranceWindow::fromStrings('0.0', '0.1'),
            1,
            2,
            searchGuards: new SearchGuardConfig(SearchGuardConfig::DEFAULT_MAX_VISITED_STATES, 0),
        );
    }

    public function test_constructor_rejects_max_visited_states_below_one(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Maximum visited states must be at least one.');

        new PathSearchConfig(
            Money::fromString('EUR', '50.00', 2),
            ToleranceWindow::fromStrings('0.0', '0.1'),
            1,
            2,
            searchGuards: new SearchGuardConfig(0, SearchGuardConfig::DEFAULT_MAX_EXPANSIONS),
        );
    }

    public function test_path_finder_tolerance_prefers_larger_bound(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '250.00', 2))
            ->withToleranceBounds('0.05', '0.15')
            ->withHopLimits(1, 4)
            ->build();

        self::assertSame('0.150000000000000000', $config->pathFinderTolerance());
        self::assertSame('maximum', $config->pathFinderToleranceSource());
    }

    public function test_path_finder_tolerance_preserves_high_precision_string(): void
    {
        $config = new PathSearchConfig(
            Money::fromString('USD', '250.00', 2),
            ToleranceWindow::fromStrings('0.50', '0.999999999999999999'),
            1,
            4,
        );

        self::assertSame('0.999999999999999999', $config->pathFinderTolerance());
        self::assertSame('maximum', $config->pathFinderToleranceSource());
    }

    public function test_path_finder_tolerance_override_is_respected(): void
    {
        $config = new PathSearchConfig(
            Money::fromString('EUR', '100.00', 2),
            ToleranceWindow::fromStrings('0.1', '0.2'),
            1,
            3,
            pathFinderToleranceOverride: '0.050000000000000000',
        );

        self::assertSame('0.050000000000000000', $config->pathFinderTolerance());
        self::assertSame('override', $config->pathFinderToleranceSource());
    }

    public function test_path_finder_tolerance_prefers_minimum_when_bounds_are_equal(): void
    {
        $config = new PathSearchConfig(
            Money::fromString('USD', '75.00', 2),
            ToleranceWindow::fromStrings('0.250000000000000000', '0.250000000000000000'),
            1,
            3,
        );

        self::assertSame('0.250000000000000000', $config->pathFinderTolerance());
        self::assertSame('minimum', $config->pathFinderToleranceSource());
    }

    public function test_path_finder_tolerance_defaults_to_maximum_without_override(): void
    {
        $config = new PathSearchConfig(
            Money::fromString('USD', '100.00', 2),
            ToleranceWindow::fromStrings('0.05', '0.20'),
            1,
            3,
        );

        self::assertSame('0.200000000000000000', $config->pathFinderTolerance());
        self::assertSame('maximum', $config->pathFinderToleranceSource());
    }

    public function test_builder_rejects_inverted_tolerance_bounds(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Minimum tolerance must be less than or equal to maximum tolerance.');

        PathSearchConfig::builder()->withToleranceBounds('0.150000000000000000', '0.050000000000000000');
    }

    public function test_string_tolerance_remains_below_float_cap(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '250.00', 2))
            ->withToleranceBounds('0.50', '0.999999999999999999')
            ->withHopLimits(1, 4)
            ->build();

        self::assertSame('0.999999999999999999', $config->maximumTolerance());
    }

    public function test_builder_provides_default_search_guards(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '50.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 2)
            ->build();

        self::assertSame(SearchGuardConfig::DEFAULT_MAX_EXPANSIONS, $config->pathFinderMaxExpansions());
        self::assertSame(SearchGuardConfig::DEFAULT_MAX_VISITED_STATES, $config->pathFinderMaxVisitedStates());
        self::assertFalse($config->throwOnGuardLimit());
    }

    public function test_builder_defaults_to_single_result_limit(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '25.00', 2))
            ->withToleranceBounds('0.05', '0.10')
            ->withHopLimits(1, 2)
            ->build();

        self::assertSame(1, $config->resultLimit());
    }

    public function test_builder_accepts_result_limit_of_one(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '25.00', 2))
            ->withToleranceBounds('0.05', '0.10')
            ->withHopLimits(1, 2)
            ->withResultLimit(1)
            ->build();

        self::assertSame(1, $config->resultLimit());
    }

    public function test_builder_accepts_custom_search_guards(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '50.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 2)
            ->withSearchGuards(42, 64)
            ->build();

        self::assertSame(64, $config->pathFinderMaxExpansions());
        self::assertSame(42, $config->pathFinderMaxVisitedStates());
    }

    public function test_constructor_accepts_time_budget_of_one_millisecond(): void
    {
        $config = new PathSearchConfig(
            Money::fromString('EUR', '50.00', 2),
            ToleranceWindow::fromStrings('0.0', '0.1'),
            1,
            2,
            searchGuards: new SearchGuardConfig(
                SearchGuardConfig::DEFAULT_MAX_VISITED_STATES,
                SearchGuardConfig::DEFAULT_MAX_EXPANSIONS,
                1,
            ),
        );

        self::assertSame(1, $config->pathFinderTimeBudgetMs());
    }

    public function test_constructor_rejects_time_budget_below_one_millisecond(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Time budget must be at least one millisecond.');

        new PathSearchConfig(
            Money::fromString('EUR', '50.00', 2),
            ToleranceWindow::fromStrings('0.0', '0.1'),
            1,
            2,
            searchGuards: new SearchGuardConfig(
                SearchGuardConfig::DEFAULT_MAX_VISITED_STATES,
                SearchGuardConfig::DEFAULT_MAX_EXPANSIONS,
                0,
            ),
        );
    }

    public function test_builder_applies_time_budget_configured_via_search_guards(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '50.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 2)
            ->withSearchGuards(42, 64, 15)
            ->build();

        self::assertSame(15, $config->pathFinderTimeBudgetMs());
    }

    public function test_builder_requires_spend_amount(): void
    {
        $builder = PathSearchConfig::builder()
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 2);

        $this->expectException(InvalidInput::class);
        $builder->build();
    }

    public function test_builder_requires_tolerance_bounds(): void
    {
        $builder = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '10.00', 2))
            ->withHopLimits(1, 2);

        $this->expectException(InvalidInput::class);
        $builder->build();
    }

    public function test_builder_rejects_non_numeric_string_tolerances(): void
    {
        $builder = PathSearchConfig::builder();

        $this->expectException(InvalidInput::class);
        $builder->withToleranceBounds('invalid', '0.1');
    }

    public function test_builder_rejects_string_tolerance_equal_to_one(): void
    {
        $builder = PathSearchConfig::builder();

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Maximum tolerance must be in the [0, 1) range.');
        $builder->withToleranceBounds('0.1', '1.000000000000000000');
    }

    public function test_builder_requires_hop_limits(): void
    {
        $builder = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '10.00', 2))
            ->withToleranceBounds('0.0', '0.1');

        $this->expectException(InvalidInput::class);
        $builder->build();
    }

    public function test_result_limit_must_be_positive(): void
    {
        $builder = PathSearchConfig::builder();

        $this->expectException(InvalidInput::class);
        $builder->withResultLimit(0);
    }

    /**
     * @dataProvider provideInvalidToleranceBounds
     */
    public function test_tolerance_bounds_are_validated(string $minimum, string $maximum): void
    {
        $builder = PathSearchConfig::builder();

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessageMatches('/tolerance must be in the \[0, 1\) range\.$/');
        $builder->withToleranceBounds($minimum, $maximum);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidToleranceBounds(): iterable
    {
        yield 'negative minimum' => ['-0.01', '0.1'];
        yield 'negative maximum' => ['0.1', '-0.5'];
        yield 'minimum equal to one' => ['1.0', '0.1'];
        yield 'maximum equal to one' => ['0.1', '1.0'];
    }

    /**
     * @dataProvider provideInvalidHopLimits
     */
    public function test_hop_limits_are_validated(int $minimum, int $maximum): void
    {
        $builder = PathSearchConfig::builder();

        $this->expectException(InvalidInput::class);
        $builder->withHopLimits($minimum, $maximum);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function provideInvalidHopLimits(): iterable
    {
        yield 'minimum zero' => [0, 2];
        yield 'maximum below minimum' => [2, 1];
    }

    /**
     * @dataProvider provideInvalidSearchGuards
     */
    public function test_search_guards_are_validated(int $maxVisited, int $maxExpansions): void
    {
        $builder = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '10.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 2);

        $this->expectException(InvalidInput::class);
        $builder->withSearchGuards($maxVisited, $maxExpansions);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function provideInvalidSearchGuards(): iterable
    {
        yield 'visited zero' => [0, 10];
        yield 'expansions zero' => [10, 0];
    }

    public function test_builder_rejects_time_budget_below_one_millisecond(): void
    {
        $builder = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '10.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 2);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Time budget must be at least one millisecond.');

        $builder->withSearchTimeBudget(0);
    }

    public function test_builder_accepts_single_millisecond_time_budget(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '25.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 2)
            ->withSearchTimeBudget(1)
            ->build();

        self::assertSame(1, $config->pathFinderTimeBudgetMs());
    }

    public function test_builder_preserves_custom_guard_limits_when_updating_time_budget(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '50.00', 2))
            ->withToleranceBounds('0.05', '0.10')
            ->withHopLimits(2, 3)
            ->withSearchGuards(123, 456)
            ->withSearchTimeBudget(789)
            ->build();

        self::assertSame(123, $config->pathFinderMaxVisitedStates());
        self::assertSame(456, $config->pathFinderMaxExpansions());
        self::assertSame(789, $config->pathFinderTimeBudgetMs());
    }

    public function test_constructor_enforces_default_limits_and_bounds(): void
    {
        $config = new PathSearchConfig(
            Money::fromString('EUR', '100.00', 2),
            ToleranceWindow::fromStrings('0.1', '0.2'),
            1,
            3,
        );

        self::assertSame(1, $config->resultLimit());
        self::assertSame(SearchGuardConfig::DEFAULT_MAX_EXPANSIONS, $config->pathFinderMaxExpansions());
        self::assertSame(SearchGuardConfig::DEFAULT_MAX_VISITED_STATES, $config->pathFinderMaxVisitedStates());
    }

    public function test_builder_can_enable_guard_limit_exception(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '25.00', 2))
            ->withToleranceBounds('0.05', '0.10')
            ->withHopLimits(1, 2)
            ->withGuardLimitException()
            ->build();

        self::assertTrue($config->throwOnGuardLimit());
    }

    public function test_builder_can_disable_guard_limit_exception(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '25.00', 2))
            ->withToleranceBounds('0.05', '0.10')
            ->withHopLimits(1, 2)
            ->withGuardLimitException(false)
            ->build();

        self::assertFalse($config->throwOnGuardLimit());
    }

    public function test_constructor_rejects_tolerance_equal_to_one(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Minimum tolerance must be in the [0, 1) range.');

        new PathSearchConfig(
            Money::fromString('EUR', '100.00', 2),
            ToleranceWindow::fromStrings('1.0', '0.2'),
            1,
            3,
        );
    }

    public function test_constructor_rejects_maximum_tolerance_equal_to_one(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Maximum tolerance must be in the [0, 1) range.');

        new PathSearchConfig(
            Money::fromString('EUR', '100.00', 2),
            ToleranceWindow::fromStrings('0.1', '1.0'),
            1,
            3,
        );
    }

    public function test_builder_rejects_inverted_bounds_after_other_configuration(): void
    {
        $builder = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '200.00', 2))
            ->withHopLimits(1, 3);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Minimum tolerance must be less than or equal to maximum tolerance.');

        $builder->withToleranceBounds('0.20', '0.05');
    }

    public function test_builder_preserves_minimum_tolerance_when_bounds_are_equal(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '150.00', 2))
            ->withToleranceBounds('0.150000000000000000', '0.150000000000000000')
            ->withHopLimits(1, 3)
            ->build();

        self::assertSame('0.150000000000000000', $config->pathFinderTolerance());
        self::assertSame('minimum', $config->pathFinderToleranceSource());
    }

    public function test_bounded_spend_calculation_maintains_scale_precision(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '123.45', 2))
            ->withToleranceBounds('0.50008101', '0.50008101')
            ->withHopLimits(1, 3)
            ->build();

        self::assertSame('61.71', $config->minimumSpendAmount()->amount());
        self::assertSame(2, $config->minimumSpendAmount()->scale());
    }

    public function test_builder_requires_both_tolerance_bounds_to_be_configured(): void
    {
        $builder = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '10.00', 2))
            ->withHopLimits(1, 2);

        $window = new ReflectionProperty(PathSearchConfigBuilder::class, 'toleranceWindow');
        $window->setAccessible(true);
        $window->setValue($builder, null);

        $this->expectException(InvalidInput::class);
        $builder->build();
    }

    public function test_builder_requires_both_hop_limits_to_be_configured(): void
    {
        $builder = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '10.00', 2))
            ->withToleranceBounds('0.0', '0.1');

        $minimum = new ReflectionProperty(PathSearchConfigBuilder::class, 'minimumHops');
        $minimum->setAccessible(true);
        $minimum->setValue($builder, 1);

        $this->expectException(InvalidInput::class);
        $builder->build();
    }

    public function test_builder_is_reusable_for_multiple_configs(): void
    {
        $builder = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.05', '0.10')
            ->withHopLimits(1, 3);

        $config1 = $builder->build();
        $config2 = $builder->build();

        self::assertNotSame($config1, $config2);
        self::assertSame('100.00', $config1->spendAmount()->amount());
        self::assertSame('100.00', $config2->spendAmount()->amount());
        self::assertSame('0.050000000000000000', $config1->minimumTolerance());
        self::assertSame('0.050000000000000000', $config2->minimumTolerance());
    }

    public function test_builder_creates_independent_configs(): void
    {
        $builder = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '50.00', 2))
            ->withToleranceBounds('0.10', '0.20')
            ->withHopLimits(1, 5);

        $config1 = $builder->build();

        $builder->withSpendAmount(Money::fromString('EUR', '75.00', 2))
            ->withToleranceBounds('0.15', '0.25')
            ->withHopLimits(2, 4);

        $config2 = $builder->build();

        self::assertSame('50.00', $config1->spendAmount()->amount());
        self::assertSame('75.00', $config2->spendAmount()->amount());
        self::assertSame('0.100000000000000000', $config1->minimumTolerance());
        self::assertSame('0.150000000000000000', $config2->minimumTolerance());
        self::assertSame(1, $config1->minimumHops());
        self::assertSame(2, $config2->minimumHops());
    }

    public function test_config_is_immutable_after_construction(): void
    {
        $spendAmount = Money::fromString('USD', '100.00', 2);
        $toleranceWindow = ToleranceWindow::fromStrings('0.05', '0.10');

        $config = new PathSearchConfig(
            $spendAmount,
            $toleranceWindow,
            1,
            3,
        );

        $reflectionClass = new \ReflectionClass($config);
        $properties = $reflectionClass->getProperties();

        foreach ($properties as $property) {
            self::assertTrue($property->isReadOnly(), "Property {$property->getName()} should be readonly");
        }
    }

    public function test_constructor_rejects_collapsed_tolerance_window_due_to_precision(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Tolerance window collapsed to zero range due to insufficient spend amount precision.');

        // Very small spend amount with tight tolerance bounds that collapse after rounding
        new PathSearchConfig(
            Money::fromString('USD', '0.01', 2),
            ToleranceWindow::fromStrings('0.00000001', '0.00000002'),
            1,
            3,
        );
    }

    public function test_constructor_accepts_valid_tight_tolerance_window_with_adequate_precision(): void
    {
        // Higher precision spend amount can handle tight tolerance bounds
        $config = new PathSearchConfig(
            Money::fromString('USD', '100.000000', 6),
            ToleranceWindow::fromStrings('0.0000001', '0.0000002'),
            1,
            3,
        );

        self::assertSame('99.999990', $config->minimumSpendAmount()->amount());
        self::assertSame('100.000020', $config->maximumSpendAmount()->amount());
    }

    public function test_constructor_accepts_equal_bounds_with_matching_tolerance(): void
    {
        // When tolerance min == max, bounds should also be equal
        $config = new PathSearchConfig(
            Money::fromString('USD', '0.01', 2),
            ToleranceWindow::fromStrings('0.1', '0.1'),
            1,
            3,
        );

        self::assertSame('0.01', $config->minimumSpendAmount()->amount());
        self::assertSame('0.01', $config->maximumSpendAmount()->amount());
    }

    public function test_constructor_handles_extreme_low_tolerance_with_low_precision(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Tolerance window collapsed to zero range due to insufficient spend amount precision.');

        // Scale 0 with very tight tolerance window will collapse
        new PathSearchConfig(
            Money::fromString('USD', '1', 0),
            ToleranceWindow::fromStrings('0.000001', '0.000002'),
            1,
            3,
        );
    }

    public function test_constructor_validates_bounds_remain_ordered_after_computation(): void
    {
        // This should work fine - normal case
        $config = new PathSearchConfig(
            Money::fromString('EUR', '100.00', 2),
            ToleranceWindow::fromStrings('0.10', '0.20'),
            1,
            3,
        );

        self::assertTrue($config->minimumSpendAmount()->lessThan($config->maximumSpendAmount()));
        self::assertSame('90.00', $config->minimumSpendAmount()->amount());
        self::assertSame('120.00', $config->maximumSpendAmount()->amount());
    }

    public function test_constructor_rejects_very_small_amounts_with_collapsed_tolerance(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Tolerance window collapsed to zero range due to insufficient spend amount precision.');

        // Very small amount where tolerance bounds collapse after rounding
        new PathSearchConfig(
            Money::fromString('BTC', '0.00000001', 8),
            ToleranceWindow::fromStrings('0.1', '0.2'),
            1,
            3,
        );
    }

    public function test_constructor_handles_very_small_amounts_with_equal_tolerance(): void
    {
        // Very small amount with equal tolerance bounds should work
        $config = new PathSearchConfig(
            Money::fromString('BTC', '0.00000001', 8),
            ToleranceWindow::fromStrings('0.1', '0.1'),
            1,
            3,
        );

        self::assertSame('0.00000001', $config->minimumSpendAmount()->amount());
        self::assertSame('0.00000001', $config->maximumSpendAmount()->amount());
        self::assertSame(8, $config->minimumSpendAmount()->scale());
    }

    public function test_constructor_handles_high_precision_with_narrow_tolerance(): void
    {
        // High precision allows narrow tolerance windows
        $config = new PathSearchConfig(
            Money::fromString('USD', '1000.00000000', 8),
            ToleranceWindow::fromStrings('0.000001', '0.000002'),
            1,
            3,
        );

        self::assertSame('999.99900000', $config->minimumSpendAmount()->amount());
        self::assertSame('1000.00200000', $config->maximumSpendAmount()->amount());
    }

    // ==================== Disjoint Plans Configuration Tests ====================

    public function test_disjoint_plans_defaults_to_true(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 3)
            ->build();

        self::assertTrue($config->disjointPlans());
    }

    public function test_disjoint_plans_can_be_set_to_false(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 3)
            ->withDisjointPlans(false)
            ->build();

        self::assertFalse($config->disjointPlans());
    }

    public function test_disjoint_plans_can_be_explicitly_set_to_true(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 3)
            ->withDisjointPlans(true)
            ->build();

        self::assertTrue($config->disjointPlans());
    }

    public function test_disjoint_plans_via_constructor_defaults_to_true(): void
    {
        $config = new PathSearchConfig(
            Money::fromString('EUR', '100.00', 2),
            ToleranceWindow::fromStrings('0.1', '0.2'),
            1,
            3,
        );

        self::assertTrue($config->disjointPlans());
    }

    public function test_disjoint_plans_via_constructor_can_be_set_to_false(): void
    {
        $config = new PathSearchConfig(
            Money::fromString('EUR', '100.00', 2),
            ToleranceWindow::fromStrings('0.1', '0.2'),
            1,
            3,
            disjointPlans: false,
        );

        self::assertFalse($config->disjointPlans());
    }

    public function test_builder_preserves_disjoint_plans_setting_with_other_options(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '500.00', 2))
            ->withToleranceBounds('0.05', '0.15')
            ->withHopLimits(1, 4)
            ->withResultLimit(5)
            ->withSearchGuards(1000, 5000)
            ->withDisjointPlans(false)
            ->withGuardLimitException(true)
            ->build();

        self::assertFalse($config->disjointPlans());
        self::assertSame(5, $config->resultLimit());
        self::assertSame(5000, $config->pathFinderMaxExpansions());
        self::assertTrue($config->throwOnGuardLimit());
    }

    // ==================== Spend Bounds Computation Tests (0002.10) ====================

    public function test_spend_bounds_computation_zero_tolerance(): void
    {
        // Test zero tolerance window produces equal min/max bounds
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 3)
            ->build();

        // With zero tolerance, both bounds should equal the spend amount
        // Formula: min = spend * (1 - 0.0) = spend * 1.0 = spend
        //          max = spend * (1 + 0.0) = spend * 1.0 = spend
        self::assertSame('100.00', $config->minimumSpendAmount()->amount());
        self::assertSame('100.00', $config->maximumSpendAmount()->amount());
        self::assertTrue($config->minimumSpendAmount()->equals($config->maximumSpendAmount()));

        // Test with different scales
        $configHighScale = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('BTC', '1.00000000', 8))
            ->withToleranceBounds('0.000000000000000000', '0.000000000000000000')
            ->withHopLimits(1, 2)
            ->build();

        self::assertSame('1.00000000', $configHighScale->minimumSpendAmount()->amount());
        self::assertSame('1.00000000', $configHighScale->maximumSpendAmount()->amount());
    }

    public function test_spend_bounds_computation_wide_tolerance(): void
    {
        // Test with very wide tolerance window (approaching 100%)
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '1000.00', 2))
            ->withToleranceBounds('0.0', '0.999999999999999999')
            ->withHopLimits(1, 3)
            ->build();

        // Formula: min = 1000 * (1 - 0.0) = 1000 * 1.0 = 1000.00
        //          max = 1000 * (1 + 0.999999999999999999) ≈ 1000 * 2.0 = 2000.00
        self::assertSame('1000.00', $config->minimumSpendAmount()->amount());
        self::assertSame('2000.00', $config->maximumSpendAmount()->amount());

        // Test with asymmetric wide window
        $config2 = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '500.00', 2))
            ->withToleranceBounds('0.50', '0.80')
            ->withHopLimits(1, 3)
            ->build();

        // min = 500 * (1 - 0.50) = 500 * 0.50 = 250.00
        // max = 500 * (1 + 0.80) = 500 * 1.80 = 900.00
        self::assertSame('250.00', $config2->minimumSpendAmount()->amount());
        self::assertSame('900.00', $config2->maximumSpendAmount()->amount());

        // Verify bounds are properly ordered
        self::assertTrue($config2->minimumSpendAmount()->lessThan($config2->maximumSpendAmount()));
    }

    public function test_spend_bounds_computation_at_boundaries(): void
    {
        // Test at exact tolerance boundaries
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('GBP', '100.00', 2))
            ->withToleranceBounds('0.10', '0.25')
            ->withHopLimits(1, 3)
            ->build();

        // min = 100 * (1 - 0.10) = 100 * 0.90 = 90.00
        // max = 100 * (1 + 0.25) = 100 * 1.25 = 125.00
        self::assertSame('90.00', $config->minimumSpendAmount()->amount());
        self::assertSame('125.00', $config->maximumSpendAmount()->amount());

        // Test with very small tolerance at boundaries
        $config2 = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('ETH', '10.000000', 6))
            ->withToleranceBounds('0.000001', '0.000002')
            ->withHopLimits(1, 2)
            ->build();

        // min = 10 * (1 - 0.000001) = 10 * 0.999999 = 9.999990
        // max = 10 * (1 + 0.000002) = 10 * 1.000002 = 10.000020
        self::assertSame('9.999990', $config2->minimumSpendAmount()->amount());
        self::assertSame('10.000020', $config2->maximumSpendAmount()->amount());

        // Test formula: result maintains spend amount scale
        self::assertSame(6, $config2->minimumSpendAmount()->scale());
        self::assertSame(6, $config2->maximumSpendAmount()->scale());
    }

    public function test_spend_bounds_computation_with_various_desired_amounts(): void
    {
        // Test small amount
        $config1 = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '1.00', 2))
            ->withToleranceBounds('0.10', '0.20')
            ->withHopLimits(1, 2)
            ->build();

        self::assertSame('0.90', $config1->minimumSpendAmount()->amount());
        self::assertSame('1.20', $config1->maximumSpendAmount()->amount());

        // Test medium amount
        $config2 = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '500.00', 2))
            ->withToleranceBounds('0.05', '0.15')
            ->withHopLimits(1, 3)
            ->build();

        self::assertSame('475.00', $config2->minimumSpendAmount()->amount());
        self::assertSame('575.00', $config2->maximumSpendAmount()->amount());

        // Test large amount
        $config3 = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('BTC', '100.00000000', 8))
            ->withToleranceBounds('0.001', '0.002')
            ->withHopLimits(1, 2)
            ->build();

        self::assertSame('99.90000000', $config3->minimumSpendAmount()->amount());
        self::assertSame('100.20000000', $config3->maximumSpendAmount()->amount());

        // Test very large amount
        $config4 = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '999999.99', 2))
            ->withToleranceBounds('0.001', '0.005')
            ->withHopLimits(1, 3)
            ->build();

        self::assertSame('998999.99', $config4->minimumSpendAmount()->amount());
        self::assertSame('1004999.99', $config4->maximumSpendAmount()->amount());
    }

    public function test_spend_bounds_formula_documentation(): void
    {
        // Document and verify the formula matches documentation
        // Formula: minSpend = spendAmount * (1 - minTolerance)
        //          maxSpend = spendAmount * (1 + maxTolerance)

        $spend = Money::fromString('USD', '1000.00', 2);
        $minTol = '0.20'; // 20%
        $maxTol = '0.30'; // 30%

        $config = new PathSearchConfig(
            $spend,
            ToleranceWindow::fromStrings($minTol, $maxTol),
            1,
            3,
        );

        // Expected: min = 1000 * (1 - 0.20) = 1000 * 0.80 = 800.00
        //           max = 1000 * (1 + 0.30) = 1000 * 1.30 = 1300.00
        self::assertSame('800.00', $config->minimumSpendAmount()->amount());
        self::assertSame('1300.00', $config->maximumSpendAmount()->amount());

        // Verify the tolerance values are preserved
        self::assertSame('0.200000000000000000', $config->minimumTolerance());
        self::assertSame('0.300000000000000000', $config->maximumTolerance());

        // Verify derived bounds are within sensible range
        self::assertTrue($config->minimumSpendAmount()->lessThan($spend));
        self::assertTrue($config->maximumSpendAmount()->greaterThan($spend));
        self::assertTrue($config->minimumSpendAmount()->lessThan($config->maximumSpendAmount()));
    }
}
