<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

final class PathSearchConfigTest extends TestCase
{
    public function test_build_requires_spend_amount(): void
    {
        $builder = PathSearchConfig::builder()
            ->withToleranceBounds(0.10, 0.20)
            ->withHopLimits(1, 3);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Spend amount must be provided.');

        $builder->build();
    }

    public function test_build_requires_tolerance_bounds(): void
    {
        $builder = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withHopLimits(1, 3);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tolerance bounds must be configured.');

        $builder->build();
    }

    public function test_build_requires_hop_limits(): void
    {
        $builder = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.10, 0.20);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Hop limits must be configured.');

        $builder->build();
    }

    /**
     * @dataProvider provideInvalidToleranceBounds
     */
    public function test_builder_rejects_invalid_tolerance_bounds(float $minimumTolerance, float $maximumTolerance, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        PathSearchConfig::builder()->withToleranceBounds($minimumTolerance, $maximumTolerance);
    }

    /**
     * @return iterable<string, array{float, float, string}>
     */
    public static function provideInvalidToleranceBounds(): iterable
    {
        yield 'minimum below zero' => [-0.0001, 0.10, 'Minimum tolerance must be in the [0, 1) range.'];
        yield 'minimum equal to one' => [1.0, 0.10, 'Minimum tolerance must be in the [0, 1) range.'];
        yield 'maximum below zero' => [0.10, -0.0001, 'Maximum tolerance must be in the [0, 1) range.'];
        yield 'maximum equal to one' => [0.10, 1.0, 'Maximum tolerance must be in the [0, 1) range.'];
    }

    /**
     * @dataProvider provideInvalidHopLimits
     */
    public function test_builder_rejects_invalid_hop_limits(int $minimumHops, int $maximumHops, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        PathSearchConfig::builder()->withHopLimits($minimumHops, $maximumHops);
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function provideInvalidHopLimits(): iterable
    {
        yield 'minimum below one' => [0, 1, 'Minimum hops must be at least one.'];
        yield 'maximum below minimum' => [2, 1, 'Maximum hops must be greater than or equal to minimum hops.'];
    }

    /**
     * @dataProvider provideToleranceScenarios
     */
    public function test_it_calculates_minimum_and_maximum_spend_amounts(
        Money $spendAmount,
        float $minimumTolerance,
        float $maximumTolerance,
        string $expectedMinimum,
        string $expectedMaximum
    ): void {
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds($minimumTolerance, $maximumTolerance)
            ->withHopLimits(1, 3)
            ->build();

        self::assertSame($spendAmount->currency(), $config->minimumSpendAmount()->currency());
        self::assertSame($expectedMinimum, $config->minimumSpendAmount()->amount());
        self::assertSame($spendAmount->currency(), $config->maximumSpendAmount()->currency());
        self::assertSame($expectedMaximum, $config->maximumSpendAmount()->amount());
    }

    /**
     * @return iterable<string, array{Money, float, float, string, string}>
     */
    public static function provideToleranceScenarios(): iterable
    {
        yield 'symmetric tolerance around integer amount' => [
            Money::fromString('EUR', '100.00', 2),
            0.05,
            0.10,
            '95.00',
            '110.00',
        ];

        yield 'asymmetric tolerance retains currency scale' => [
            Money::fromString('USD', '12.34567', 5),
            0.123456,
            0.654321,
            '10.82152',
            '20.42370',
        ];
    }

    /**
     * @dataProvider providePathFinderToleranceCases
     */
    public function test_it_normalizes_path_finder_tolerance(
        float $minimumTolerance,
        float $maximumTolerance,
        float $expectedTolerance
    ): void {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds($minimumTolerance, $maximumTolerance)
            ->withHopLimits(1, 2)
            ->build();

        self::assertEqualsWithDelta($expectedTolerance, $config->pathFinderTolerance(), 1e-12);
    }

    /**
     * @return iterable<string, array{float, float, float}>
     */
    public static function providePathFinderToleranceCases(): iterable
    {
        yield 'uses upper tolerance when it is greater' => [0.10, 0.25, 0.25];
        yield 'uses minimum tolerance when it dominates upper bound' => [0.30, 0.20, 0.30];
        yield 'clamps result to hard upper limit' => [0.50, 0.9999995, 0.999999];
    }
}
