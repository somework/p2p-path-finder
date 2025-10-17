<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Service\ToleranceEvaluator;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

final class ToleranceEvaluatorTest extends TestCase
{
    /**
     * @dataProvider provideSpendScenariosWithinTolerance
     */
    public function test_it_accepts_spend_within_configured_tolerance(
        PathSearchConfig $config,
        Money $requestedSpend,
        Money $actualSpend,
        string $expectedResidual
    ): void {
        $evaluator = new ToleranceEvaluator();

        $residual = $evaluator->evaluate($config, $requestedSpend, $actualSpend);

        self::assertNotNull($residual);
        self::assertSame($expectedResidual, $residual->ratio());
        self::assertTrue($residual->isGreaterThanOrEqual('0'));
        self::assertTrue($residual->isLessThanOrEqual($config->maximumTolerance(), 18));
    }

    public function test_it_handles_zero_requested_spend_without_dividing_by_zero(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '0.00', 2))
            ->withToleranceBounds('0.0', '0.5')
            ->withHopLimits(1, 1)
            ->build();

        $requested = Money::fromString('EUR', '0.00', 2);
        $actual = Money::fromString('EUR', '0.00', 2);

        $evaluator = new ToleranceEvaluator();
        $residual = $evaluator->evaluate($config, $requested, $actual);

        self::assertNotNull($residual);
        self::assertTrue($residual->isZero());
        self::assertSame('0.000000000000000000', $residual->ratio());
    }

    /**
     * @return iterable<string, array{PathSearchConfig, Money, Money, string}>
     */
    public static function provideSpendScenariosWithinTolerance(): iterable
    {
        $requested = Money::fromString('EUR', '100.00', 2);

        yield 'zero tolerance requires exact spend' => [
            PathSearchConfig::builder()
                ->withSpendAmount($requested)
                ->withToleranceBounds('0.0', '0.0')
                ->withHopLimits(1, 1)
                ->build(),
            $requested,
            Money::fromString('EUR', '100.000000', 6),
            '0.000000000000000000',
        ];

        yield 'asymmetric tolerance allows lower boundary spend' => [
            PathSearchConfig::builder()
                ->withSpendAmount($requested)
                ->withToleranceBounds('0.02', '0.10')
                ->withHopLimits(1, 3)
                ->build(),
            $requested,
            Money::fromString('EUR', '98.000', 3),
            '0.020000000000000000',
        ];

        $narrowWindowConfig = PathSearchConfig::builder()
            ->withSpendAmount($requested)
            ->withToleranceBounds('0.015', '0.035')
            ->withHopLimits(1, 2)
            ->build();

        yield 'minimum spend window lower edge is accepted' => [
            $narrowWindowConfig,
            $requested,
            $narrowWindowConfig->minimumSpendAmount(),
            '0.015000000000000000',
        ];

        $maximumWindowConfig = PathSearchConfig::builder()
            ->withSpendAmount($requested)
            ->withToleranceBounds('0.015', '0.035')
            ->withHopLimits(1, 2)
            ->build();

        yield 'maximum spend window upper edge is accepted' => [
            $maximumWindowConfig,
            $requested,
            $maximumWindowConfig->maximumSpendAmount(),
            '0.035000000000000000',
        ];

        $upperBoundaryConfig = PathSearchConfig::builder()
            ->withSpendAmount($requested)
            ->withToleranceBounds('0.01', '0.05')
            ->withHopLimits(2, 4)
            ->build();

        yield 'overspend at exact upper tolerance is accepted' => [
            $upperBoundaryConfig,
            $requested,
            $upperBoundaryConfig->maximumSpendAmount(),
            '0.050000000000000000',
        ];

        $highPrecisionSpend = Money::fromString('BTC', '0.12345678', 8);

        yield 'high precision rounding noise remains within tolerance' => [
            PathSearchConfig::builder()
                ->withSpendAmount($highPrecisionSpend)
                ->withToleranceBounds('0.0', '0.000002')
                ->withHopLimits(1, 4)
                ->build(),
            $highPrecisionSpend,
            Money::fromString('BTC', '0.12345690', 8),
            '0.000000972000079704',
        ];

        yield 'ultra high precision overspend remains representable as string' => [
            PathSearchConfig::builder()
                ->withSpendAmount(Money::fromString('USD', '1.000000000000000000', 18))
                ->withToleranceBounds('0.0', '0.000000000000000200')
                ->withHopLimits(1, 1)
                ->build(),
            Money::fromString('USD', '1.000000000000000000', 18),
            Money::fromString('USD', '1.000000000000000123', 18),
            '0.000000000000000123',
        ];
    }

    /**
     * @dataProvider provideUnderSpendRejectionCases
     */
    public function test_it_rejects_spend_below_lower_tolerance_window(
        PathSearchConfig $config,
        Money $requestedSpend,
        Money $actualSpend
    ): void {
        $evaluator = new ToleranceEvaluator();

        self::assertNull($evaluator->evaluate($config, $requestedSpend, $actualSpend));
    }

    /**
     * @return iterable<string, array{PathSearchConfig, Money, Money}>
     */
    public static function provideUnderSpendRejectionCases(): iterable
    {
        $requested = Money::fromString('EUR', '100.00', 2);

        yield 'zero tolerance rejects fractional under spend' => [
            PathSearchConfig::builder()
                ->withSpendAmount($requested)
                ->withToleranceBounds('0.0', '0.05')
                ->withHopLimits(1, 3)
                ->build(),
            $requested,
            Money::fromString('EUR', '99.99', 2),
        ];

        yield 'underspend exceeding configured minimum tolerance is rejected' => [
            PathSearchConfig::builder()
                ->withSpendAmount($requested)
                ->withToleranceBounds('0.015', '0.02')
                ->withHopLimits(1, 2)
                ->build(),
            $requested,
            Money::fromString('EUR', '98.30', 2),
        ];
    }

    /**
     * @return iterable<string, array{PathSearchConfig, Money, Money}>
     */
    public static function provideOverSpendRejectionCases(): iterable
    {
        $requested = Money::fromString('EUR', '100.00', 2);

        yield 'overspend exceeding configured maximum tolerance is rejected' => [
            PathSearchConfig::builder()
                ->withSpendAmount($requested)
                ->withToleranceBounds('0.01', '0.05')
                ->withHopLimits(1, 3)
                ->build(),
            $requested,
            Money::fromString('EUR', '105.10', 2),
        ];

        yield 'high precision overspend beyond tolerance is rejected' => [
            PathSearchConfig::builder()
                ->withSpendAmount(Money::fromString('BTC', '0.12345678', 8))
                ->withToleranceBounds('0.0', '0.000002')
                ->withHopLimits(1, 4)
                ->build(),
            Money::fromString('BTC', '0.12345678', 8),
            Money::fromString('BTC', '0.12345780', 8),
        ];
    }

    /**
     * @dataProvider provideOverSpendRejectionCases
     */
    public function test_it_rejects_spend_above_upper_tolerance_window(
        PathSearchConfig $config,
        Money $requestedSpend,
        Money $actualSpend
    ): void {
        $evaluator = new ToleranceEvaluator();

        self::assertNull($evaluator->evaluate($config, $requestedSpend, $actualSpend));
    }
}
