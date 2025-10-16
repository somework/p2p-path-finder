<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfigBuilder;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

final class PathSearchConfigTest extends TestCase
{
    public function test_it_calculates_tolerance_adjusted_spend_bounds(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.10, 0.25)
            ->withHopLimits(1, 3)
            ->build();

        self::assertSame('90.00', $config->minimumSpendAmount()->amount());
        self::assertSame('125.00', $config->maximumSpendAmount()->amount());
        self::assertSame(2, $config->minimumSpendAmount()->scale());
        self::assertSame(2, $config->maximumSpendAmount()->scale());
    }

    public function test_path_finder_tolerance_prefers_larger_bound(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '250.00', 2))
            ->withToleranceBounds(0.15, 0.05)
            ->withHopLimits(1, 4)
            ->build();

        self::assertSame(0.15, $config->pathFinderTolerance());
    }

    public function test_path_finder_tolerance_is_capped_below_one(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '250.00', 2))
            ->withToleranceBounds(0.50, 0.9999995)
            ->withHopLimits(1, 4)
            ->build();

        self::assertSame(0.999999, $config->pathFinderTolerance());
    }

    public function test_builder_provides_default_search_guards(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '50.00', 2))
            ->withToleranceBounds(0.0, 0.1)
            ->withHopLimits(1, 2)
            ->build();

        self::assertSame(PathFinder::DEFAULT_MAX_EXPANSIONS, $config->pathFinderMaxExpansions());
        self::assertSame(PathFinder::DEFAULT_MAX_VISITED_STATES, $config->pathFinderMaxVisitedStates());
    }

    public function test_builder_defaults_to_single_result_limit(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '25.00', 2))
            ->withToleranceBounds(0.05, 0.10)
            ->withHopLimits(1, 2)
            ->build();

        self::assertSame(1, $config->resultLimit());
    }

    public function test_builder_accepts_result_limit_of_one(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '25.00', 2))
            ->withToleranceBounds(0.05, 0.10)
            ->withHopLimits(1, 2)
            ->withResultLimit(1)
            ->build();

        self::assertSame(1, $config->resultLimit());
    }

    public function test_builder_accepts_custom_search_guards(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '50.00', 2))
            ->withToleranceBounds(0.0, 0.1)
            ->withHopLimits(1, 2)
            ->withSearchGuards(42, 64)
            ->build();

        self::assertSame(64, $config->pathFinderMaxExpansions());
        self::assertSame(42, $config->pathFinderMaxVisitedStates());
    }

    public function test_builder_requires_spend_amount(): void
    {
        $builder = PathSearchConfig::builder()
            ->withToleranceBounds(0.0, 0.1)
            ->withHopLimits(1, 2);

        $this->expectException(InvalidArgumentException::class);
        $builder->build();
    }

    public function test_builder_requires_tolerance_bounds(): void
    {
        $builder = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '10.00', 2))
            ->withHopLimits(1, 2);

        $this->expectException(InvalidArgumentException::class);
        $builder->build();
    }

    public function test_builder_requires_hop_limits(): void
    {
        $builder = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '10.00', 2))
            ->withToleranceBounds(0.0, 0.1);

        $this->expectException(InvalidArgumentException::class);
        $builder->build();
    }

    public function test_result_limit_must_be_positive(): void
    {
        $builder = PathSearchConfig::builder();

        $this->expectException(InvalidArgumentException::class);
        $builder->withResultLimit(0);
    }

    /**
     * @dataProvider provideInvalidToleranceBounds
     */
    public function test_tolerance_bounds_are_validated(float $minimum, float $maximum): void
    {
        $builder = PathSearchConfig::builder();

        $this->expectException(InvalidArgumentException::class);
        $builder->withToleranceBounds($minimum, $maximum);
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function provideInvalidToleranceBounds(): iterable
    {
        yield 'negative minimum' => [-0.01, 0.1];
        yield 'negative maximum' => [0.1, -0.5];
        yield 'minimum equal to one' => [1.0, 0.1];
        yield 'maximum equal to one' => [0.1, 1.0];
    }

    /**
     * @dataProvider provideInvalidHopLimits
     */
    public function test_hop_limits_are_validated(int $minimum, int $maximum): void
    {
        $builder = PathSearchConfig::builder();

        $this->expectException(InvalidArgumentException::class);
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
            ->withToleranceBounds(0.0, 0.1)
            ->withHopLimits(1, 2);

        $this->expectException(InvalidArgumentException::class);
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

    public function test_calculate_bounded_spend_rejects_negative_multiplier(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '10.00', 2))
            ->withToleranceBounds(0.0, 0.1)
            ->withHopLimits(1, 2)
            ->build();

        $method = new ReflectionMethod(PathSearchConfig::class, 'calculateBoundedSpend');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $method->invoke($config, -0.01);
    }

    public function test_calculate_bounded_spend_allows_zero_multiplier(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '10.00', 2))
            ->withToleranceBounds(0.0, 0.1)
            ->withHopLimits(1, 2)
            ->build();

        $method = new ReflectionMethod(PathSearchConfig::class, 'calculateBoundedSpend');
        $method->setAccessible(true);

        $zero = $method->invoke($config, 0.0);

        self::assertTrue($zero->equals(Money::fromString('EUR', '0.00', 2)));
    }

    public function test_float_to_string_collapses_negative_zero(): void
    {
        $method = new ReflectionMethod(PathSearchConfig::class, 'floatToString');
        $method->setAccessible(true);

        self::assertSame('0.0000', $method->invoke(null, -0.0, 4));
        self::assertSame('0.1234', $method->invoke(null, 0.1234, 4));
    }

    public function test_float_to_string_uses_guard_digits_for_rounding(): void
    {
        $method = new ReflectionMethod(PathSearchConfig::class, 'floatToString');
        $method->setAccessible(true);

        self::assertSame('0.0002', $method->invoke(null, 0.0001499, 4));
        self::assertSame('0.0001', $method->invoke(null, 0.0001001, 4));
    }

    public function test_builder_requires_both_tolerance_bounds_to_be_configured(): void
    {
        $builder = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '10.00', 2))
            ->withHopLimits(1, 2);

        $minimum = new ReflectionProperty(PathSearchConfigBuilder::class, 'minimumTolerance');
        $minimum->setAccessible(true);
        $minimum->setValue($builder, 0.1);

        $this->expectException(InvalidArgumentException::class);
        $builder->build();
    }

    public function test_builder_requires_both_hop_limits_to_be_configured(): void
    {
        $builder = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '10.00', 2))
            ->withToleranceBounds(0.0, 0.1);

        $minimum = new ReflectionProperty(PathSearchConfigBuilder::class, 'minimumHops');
        $minimum->setAccessible(true);
        $minimum->setValue($builder, 1);

        $this->expectException(InvalidArgumentException::class);
        $builder->build();
    }
}
