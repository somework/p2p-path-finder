<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Service\ToleranceEvaluator;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\ToleranceWindow;

final class ToleranceEvaluatorTest extends TestCase
{
    private function createConfig(): PathSearchConfig
    {
        return new PathSearchConfig(
            Money::fromString('USD', '100.00', 2),
            ToleranceWindow::fromStrings('0.05', '0.10'),
            1,
            3,
        );
    }

    public function test_returns_decimal_tolerance_when_within_bounds(): void
    {
        $config = $this->createConfig();
        $evaluator = new ToleranceEvaluator();

        $result = $evaluator->evaluate(
            $config,
            Money::fromString('USD', '100.00', 2),
            Money::fromString('USD', '104.00', 2),
        );

        self::assertNotNull($result);
        self::assertSame('0.040000000000000000', $result->ratio());
    }

    public function test_returns_null_when_underspend_exceeds_minimum_tolerance(): void
    {
        $config = $this->createConfig();
        $evaluator = new ToleranceEvaluator();

        $result = $evaluator->evaluate(
            $config,
            Money::fromString('USD', '100.00', 2),
            Money::fromString('USD', '90.00', 2),
        );

        self::assertNull($result);
    }

    public function test_returns_null_when_overspend_exceeds_maximum_tolerance(): void
    {
        $config = $this->createConfig();
        $evaluator = new ToleranceEvaluator();

        $result = $evaluator->evaluate(
            $config,
            Money::fromString('USD', '100.00', 2),
            Money::fromString('USD', '112.00', 2),
        );

        self::assertNull($result);
    }

    public function test_handles_zero_requested_spend(): void
    {
        $config = $this->createConfig();
        $evaluator = new ToleranceEvaluator();

        $result = $evaluator->evaluate(
            $config,
            Money::fromString('USD', '0.00', 2),
            Money::fromString('USD', '0.00', 2),
        );

        self::assertNotNull($result);
        self::assertSame('0.000000000000000000', $result->ratio());
    }

    public function test_returns_null_when_zero_requested_but_actual_positive(): void
    {
        $config = $this->createConfig();
        $evaluator = new ToleranceEvaluator();

        $result = $evaluator->evaluate(
            $config,
            Money::fromString('USD', '0.00', 2),
            Money::fromString('USD', '1.00', 2),
        );

        self::assertNull($result);
    }

    public function test_returns_decimal_when_underspend_matches_minimum_tolerance(): void
    {
        $config = $this->createConfig();
        $evaluator = new ToleranceEvaluator();

        $result = $evaluator->evaluate(
            $config,
            Money::fromString('USD', '100.00', 2),
            Money::fromString('USD', '95.00', 2),
        );

        self::assertNotNull($result);
        self::assertSame('0.050000000000000000', $result->ratio());
    }

    public function test_residual_calculation_exposes_exact_match_ratio(): void
    {
        $evaluator = new ToleranceEvaluator();
        $method = new ReflectionMethod(ToleranceEvaluator::class, 'calculateResidualTolerance');
        $method->setAccessible(true);

        $ratio = $method->invoke(
            $evaluator,
            Money::fromString('USD', '123.456', 3),
            Money::fromString('USD', '123.456', 3),
        );

        self::assertSame('0.000000000000000000', $ratio);
    }
}
