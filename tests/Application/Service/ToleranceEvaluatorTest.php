<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Service\ToleranceEvaluator;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

final class ToleranceEvaluatorTest extends TestCase
{
    public function test_it_accepts_amount_within_bounds(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.2)
            ->withHopLimits(1, 1)
            ->build();

        $evaluator = new ToleranceEvaluator();
        $actual = Money::fromString('EUR', '110.00', 2);

        $residual = $evaluator->evaluate($config->spendAmount(), $actual, $config);

        self::assertNotNull($residual);
        self::assertSame(0.1, $residual);
    }

    public function test_it_rejects_amount_below_minimum_tolerance(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.05, 0.2)
            ->withHopLimits(1, 1)
            ->build();

        $evaluator = new ToleranceEvaluator();
        $actual = Money::fromString('EUR', '90.00', 2);

        self::assertNull($evaluator->evaluate($config->spendAmount(), $actual, $config));
    }
}
