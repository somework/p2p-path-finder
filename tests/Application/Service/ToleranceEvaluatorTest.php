<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Service\ToleranceEvaluator;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

final class ToleranceEvaluatorTest extends TestCase
{
    public function test_it_evaluates_residual_within_bounds(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.05)
            ->withHopLimits(1, 1)
            ->build();

        $evaluator = new ToleranceEvaluator();

        $withinBounds = Money::fromString('EUR', '102.000', 3);
        $residual = $evaluator->evaluate($config, $config->spendAmount(), $withinBounds);

        self::assertNotNull($residual);
        self::assertGreaterThan(0.0, $residual);
        self::assertLessThanOrEqual($config->maximumTolerance(), $residual);

        $exceeding = Money::fromString('EUR', '110.000', 3);
        self::assertNull($evaluator->evaluate($config, $config->spendAmount(), $exceeding));
    }
}
