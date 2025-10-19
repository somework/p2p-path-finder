<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Config;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfigBuilder;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
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
            '0.0',
            '0.1',
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
            '0.0',
            '0.1',
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
            '0.0',
            '0.1',
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
            '0.0',
            '0.1',
            1,
            2,
            pathFinderMaxExpansions: 0,
        );
    }

    public function test_constructor_rejects_max_visited_states_below_one(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Maximum visited states must be at least one.');

        new PathSearchConfig(
            Money::fromString('EUR', '50.00', 2),
            '0.0',
            '0.1',
            1,
            2,
            pathFinderMaxVisitedStates: 0,
        );
    }

    public function test_path_finder_tolerance_prefers_larger_bound(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '250.00', 2))
            ->withToleranceBounds('0.15', '0.05')
            ->withHopLimits(1, 4)
            ->build();

        self::assertSame('0.150000000000000000', $config->pathFinderTolerance());
        self::assertSame('override', $config->pathFinderToleranceSource());
    }

    public function test_path_finder_tolerance_preserves_high_precision_string(): void
    {
        $config = new PathSearchConfig(
            Money::fromString('USD', '250.00', 2),
            '0.50',
            '0.999999999999999999',
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
            '0.1',
            '0.2',
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
            '0.250000000000000000',
            '0.250000000000000000',
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
            '0.05',
            '0.20',
            1,
            3,
        );

        self::assertSame('0.200000000000000000', $config->pathFinderTolerance());
        self::assertSame('maximum', $config->pathFinderToleranceSource());
    }

    public function test_path_finder_tolerance_records_minimum_when_lower_bound_exceeds_upper(): void
    {
        $config = new PathSearchConfig(
            Money::fromString('EUR', '42.00', 2),
            '0.150000000000000000',
            '0.050000000000000000',
            1,
            4,
        );

        self::assertSame('0.150000000000000000', $config->pathFinderTolerance());
        self::assertSame('minimum', $config->pathFinderToleranceSource());
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

        self::assertSame(PathFinder::DEFAULT_MAX_EXPANSIONS, $config->pathFinderMaxExpansions());
        self::assertSame(PathFinder::DEFAULT_MAX_VISITED_STATES, $config->pathFinderMaxVisitedStates());
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

    public function test_constructor_enforces_default_limits_and_bounds(): void
    {
        $config = new PathSearchConfig(
            Money::fromString('EUR', '100.00', 2),
            '0.1',
            '0.2',
            1,
            3,
        );

        self::assertSame(1, $config->resultLimit());
        self::assertSame(PathFinder::DEFAULT_MAX_EXPANSIONS, $config->pathFinderMaxExpansions());
        self::assertSame(PathFinder::DEFAULT_MAX_VISITED_STATES, $config->pathFinderMaxVisitedStates());
    }

    public function test_constructor_rejects_tolerance_equal_to_one(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Minimum tolerance must be in the [0, 1) range.');

        new PathSearchConfig(
            Money::fromString('EUR', '100.00', 2),
            '1.0',
            '0.2',
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
            '0.1',
            '1.0',
            1,
            3,
        );
    }

    public function test_path_finder_tolerance_prefers_minimum_when_bounds_inverted(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '200.00', 2))
            ->withToleranceBounds('0.20', '0.05')
            ->withHopLimits(1, 3)
            ->build();

        self::assertSame('0.200000000000000000', $config->pathFinderTolerance());
    }

    public function test_builder_preserves_minimum_tolerance_when_bounds_are_equal(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '150.00', 2))
            ->withToleranceBounds('0.150000000000000000', '0.150000000000000000')
            ->withHopLimits(1, 3)
            ->build();

        self::assertSame('0.150000000000000000', $config->pathFinderTolerance());
        self::assertSame('override', $config->pathFinderToleranceSource());
    }

    public function test_bounded_spend_calculation_maintains_scale_precision(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '123.45', 2))
            ->withToleranceBounds('0.50008101', '0.0')
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

        $minimum = new ReflectionProperty(PathSearchConfigBuilder::class, 'minimumTolerance');
        $minimum->setAccessible(true);
        $minimum->setValue($builder, '0.100000000000000000');

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
}
