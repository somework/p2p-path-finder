<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfigBuilder;
use SomeWork\P2PPathFinder\Domain\Money\Money;

#[CoversClass(PathSearchConfigBuilder::class)]
final class PathSearchConfigBuilderTest extends TestCase
{
    #[TestDox('build() without withDisjointPlans() returns config with disjointPlans true (default)')]
    public function test_build_without_disjoint_plans_returns_default_true(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 3)
            ->build();

        self::assertTrue($config->disjointPlans());
    }

    #[TestDox('withDisjointPlans(false) and build() returns config with disjointPlans false')]
    public function test_with_disjoint_plans_false_returns_false(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 3)
            ->withDisjointPlans(false)
            ->build();

        self::assertFalse($config->disjointPlans());
    }

    #[TestDox('withDisjointPlans(true) and build() returns config with disjointPlans true')]
    public function test_with_disjoint_plans_true_returns_true(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 3)
            ->withDisjointPlans(true)
            ->build();

        self::assertTrue($config->disjointPlans());
    }
}
