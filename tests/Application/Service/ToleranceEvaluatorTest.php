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
        float $expectedResidual
    ): void {
        $evaluator = new ToleranceEvaluator();

        $residual = $evaluator->evaluate($config, $requestedSpend, $actualSpend);

        self::assertNotNull($residual);
        self::assertEqualsWithDelta($expectedResidual, $residual, 1e-12);
        self::assertGreaterThanOrEqual(0.0, $residual);
        self::assertLessThanOrEqual($config->maximumTolerance() + 1e-12, $residual);
    }

    /**
     * @return iterable<string, array{PathSearchConfig, Money, Money, float}>
     */
    public static function provideSpendScenariosWithinTolerance(): iterable
    {
        $requested = Money::fromString('EUR', '100.00', 2);

        yield 'zero tolerance requires exact spend' => [
            PathSearchConfig::builder()
                ->withSpendAmount($requested)
                ->withToleranceBounds(0.0, 0.0)
                ->withHopLimits(1, 1)
                ->build(),
            $requested,
            Money::fromString('EUR', '100.000000', 6),
            0.0,
        ];

        yield 'asymmetric tolerance allows lower boundary spend' => [
            PathSearchConfig::builder()
                ->withSpendAmount($requested)
                ->withToleranceBounds(0.02, 0.10)
                ->withHopLimits(1, 3)
                ->build(),
            $requested,
            Money::fromString('EUR', '98.000', 3),
            0.02,
        ];

        $narrowWindowConfig = PathSearchConfig::builder()
            ->withSpendAmount($requested)
            ->withToleranceBounds(0.015, 0.035)
            ->withHopLimits(1, 2)
            ->build();

        yield 'minimum spend window lower edge is accepted' => [
            $narrowWindowConfig,
            $requested,
            $narrowWindowConfig->minimumSpendAmount(),
            0.015,
        ];

        $maximumWindowConfig = PathSearchConfig::builder()
            ->withSpendAmount($requested)
            ->withToleranceBounds(0.015, 0.035)
            ->withHopLimits(1, 2)
            ->build();

        yield 'maximum spend window upper edge is accepted' => [
            $maximumWindowConfig,
            $requested,
            $maximumWindowConfig->maximumSpendAmount(),
            0.035,
        ];

        $highPrecisionSpend = Money::fromString('BTC', '0.12345678', 8);

        yield 'high precision rounding noise remains within tolerance' => [
            PathSearchConfig::builder()
                ->withSpendAmount($highPrecisionSpend)
                ->withToleranceBounds(0.0, 0.000002)
                ->withHopLimits(1, 4)
                ->build(),
            $highPrecisionSpend,
            Money::fromString('BTC', '0.12345690', 8),
            9.720000797040065e-7,
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
                ->withToleranceBounds(0.0, 0.05)
                ->withHopLimits(1, 3)
                ->build(),
            $requested,
            Money::fromString('EUR', '99.99', 2),
        ];

        yield 'underspend exceeding configured minimum tolerance is rejected' => [
            PathSearchConfig::builder()
                ->withSpendAmount($requested)
                ->withToleranceBounds(0.015, 0.02)
                ->withHopLimits(1, 2)
                ->build(),
            $requested,
            Money::fromString('EUR', '98.30', 2),
        ];
    }
}
