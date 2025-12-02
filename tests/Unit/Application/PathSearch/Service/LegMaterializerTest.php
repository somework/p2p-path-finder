<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Service;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\LegMaterializer;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeeBreakdown;
use SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function sprintf;

#[CoversClass(LegMaterializer::class)]
final class LegMaterializerTest extends TestCase
{
    public function test_resolve_buy_fill_rejects_when_minimum_spend_exceeds_ceiling(): void
    {
        $order = OrderFactory::buy('AAA', 'USD', '5.000', '10.000', '2.000', 3, 3);
        $materializer = new LegMaterializer();

        $netSeed = Money::fromString('AAA', '5.000', 3);
        $grossSeed = Money::fromString('AAA', '5.000', 3);
        $insufficientCeiling = Money::fromString('AAA', '4.999', 3);

        self::assertNull(
            $materializer->resolveBuyFill($order, $netSeed, $grossSeed, $insufficientCeiling)
        );
    }

    public function test_resolve_buy_fill_rejects_when_budget_ratio_collapses(): void
    {
        $order = OrderFactory::buy('AAA', 'USD', '0.000', '10.000', '2.000', 3, 3);
        $materializer = new LegMaterializer();

        $netSeed = Money::fromString('AAA', '5.000', 3);
        $grossSeed = Money::fromString('AAA', '5.000', 3);
        $zeroCeiling = Money::fromString('AAA', '0.000', 3);

        self::assertNull(
            $materializer->resolveBuyFill($order, $netSeed, $grossSeed, $zeroCeiling),
            'Expected the adjustment ratio to collapse to zero, causing a null result.'
        );
    }

    public function test_calculate_sell_adjustment_ratio_returns_null_when_actual_zero(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'calculateSellAdjustmentRatio');
        $method->setAccessible(true);

        $target = Money::fromString('USD', '100.000', 3);
        $actual = Money::fromString('USD', '0.000', 3);

        self::assertNull($method->invoke($materializer, $target, $actual, 3));
    }

    public function test_calculate_sell_adjustment_ratio_returns_precise_ratio(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'calculateSellAdjustmentRatio');
        $method->setAccessible(true);

        $target = Money::fromString('USD', '95.000', 3);
        $actual = Money::fromString('USD', '100.000', 3);

        $ratio = $method->invoke($materializer, $target, $actual, 3);

        $ratioScale = 3 + $this->sellResolutionExtraScale();
        $targetDecimal = BigDecimal::of($target->amount())->toScale($ratioScale, \Brick\Math\RoundingMode::HALF_UP);
        $actualDecimal = BigDecimal::of($actual->amount())->toScale($ratioScale, \Brick\Math\RoundingMode::HALF_UP);
        $expectedRatio = $targetDecimal
            ->dividedBy($actualDecimal, $ratioScale, \Brick\Math\RoundingMode::HALF_UP)
            ->toScale($ratioScale, \Brick\Math\RoundingMode::HALF_UP)
            ->__toString();

        self::assertSame($expectedRatio, $ratio);
    }

    public function test_is_within_sell_resolution_tolerance_requires_matching_zeroes(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'isWithinSellResolutionTolerance');
        $method->setAccessible(true);

        $targetZero = Money::fromString('USD', '0.000', 3);
        $actualZero = Money::fromString('USD', '0.000', 3);
        $actualPositive = Money::fromString('USD', '0.001', 3);

        self::assertTrue($method->invoke($materializer, $targetZero, $actualZero));
        self::assertFalse($method->invoke($materializer, $targetZero, $actualPositive));
    }

    public function test_is_within_sell_resolution_tolerance_obeys_relative_threshold(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'isWithinSellResolutionTolerance');
        $method->setAccessible(true);

        $target = Money::fromString('USD', '100.000000', 6);
        $withinTolerance = Money::fromString('USD', '100.000050', 6);
        $outsideTolerance = Money::fromString('USD', '100.100000', 6);

        self::assertTrue($method->invoke($materializer, $target, $withinTolerance));
        self::assertFalse($method->invoke($materializer, $target, $outsideTolerance));
    }

    public function test_convert_fees_to_map_filters_zero_and_sorts(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'convertFeesToMap');
        $method->setAccessible(true);

        $fees = FeeBreakdown::of(
            Money::fromString('ZZZ', '1.250', 3),
            Money::fromString('AAA', '0.750', 3),
        );

        $map = $method->invoke($materializer, $fees);
        $mapArray = $map->toArray();

        self::assertSame(['AAA', 'ZZZ'], array_keys($mapArray));
        self::assertSame('0.750', $this->fee($map, 'AAA')->amount());
        self::assertSame('1.250', $this->fee($map, 'ZZZ')->amount());

        $zeroFees = FeeBreakdown::of(Money::zero('AAA', 3), Money::zero('BBB', 3));
        $zeroMap = $method->invoke($materializer, $zeroFees);
        self::assertTrue($zeroMap->isEmpty());
    }

    public function test_reduce_budget_clamps_to_zero_when_spend_exceeds_budget(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'reduceBudget');
        $method->setAccessible(true);

        $budget = Money::fromString('USD', '100.00', 2);
        $spent = Money::fromString('USD', '250.00', 2);

        $remaining = $method->invoke($materializer, $budget, $spent);

        self::assertSame('0.00', $remaining->amount());
    }

    public function test_reduce_budget_ignores_spend_in_different_currency(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'reduceBudget');
        $method->setAccessible(true);

        $budget = Money::fromString('USD', '100.00', 2);
        $spent = Money::fromString('EUR', '50.00', 2);

        $remaining = $method->invoke($materializer, $budget, $spent);

        self::assertSame($budget->amount(), $remaining->amount());
        self::assertSame($budget->currency(), $remaining->currency());
    }

    public function test_calculate_sell_adjustment_ratio_handles_edge_cases(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'calculateSellAdjustmentRatio');
        $method->setAccessible(true);

        $target = Money::fromString('USD', '100.000', 3);
        $actual = Money::fromString('USD', '50.000', 3);
        /** @var string|null $ratio */
        $ratio = $method->invoke($materializer, $target, $actual, 3);
        self::assertNotNull($ratio);
        self::assertSame('2.000000000', $ratio);

        $zeroActual = Money::fromString('USD', '0.000', 3);
        self::assertNull($method->invoke($materializer, $target, $zeroActual, 3));
    }

    public function test_align_base_scale_respects_bounds_precision(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'alignBaseScale');
        $method->setAccessible(true);

        $baseAmount = Money::fromString('USD', '1.2', 1);
        /** @var Money $aligned */
        $aligned = $method->invoke($materializer, 4, 5, $baseAmount);

        self::assertSame(5, $aligned->scale());
        self::assertSame('1.20000', $aligned->amount());
        self::assertSame('USD', $aligned->currency());
    }

    public function test_evaluate_sell_quote_returns_quote_and_fees(): void
    {
        $order = OrderFactory::sell('USD', 'EUR', '100.000', '1000.000', '0.850', 3, 3);
        $materializer = new LegMaterializer();

        $baseAmount = Money::fromString('USD', '100.000', 3);
        $result = $materializer->evaluateSellQuote($order, $baseAmount);

        self::assertArrayHasKey('grossQuote', $result);
        self::assertArrayHasKey('fees', $result);
        self::assertArrayHasKey('effectiveQuote', $result);
        self::assertArrayHasKey('netBase', $result);
        self::assertInstanceOf(Money::class, $result['grossQuote']);
        self::assertInstanceOf(FeeBreakdown::class, $result['fees']);
        self::assertSame('EUR', $result['grossQuote']->currency());
        self::assertTrue($result['grossQuote']->greaterThan(Money::zero('EUR', 3)));
    }

    public function test_evaluate_sell_quote_with_fees(): void
    {
        $order = OrderFactory::sell(
            'USD',
            'EUR',
            '100.000',
            '1000.000',
            '0.850',
            3,
            3,
            FeePolicyFactory::baseAndQuoteSurcharge('0.050', '0.020', 6)
        );
        $materializer = new LegMaterializer();

        $baseAmount = Money::fromString('USD', '100.000', 3);
        $result = $materializer->evaluateSellQuote($order, $baseAmount);

        $fees = $result['fees'];
        self::assertInstanceOf(FeeBreakdown::class, $fees);

        // Check that fees are not zero (order has fees)
        self::assertFalse($fees->isZero()); // Should have fees
    }

    public function test_calculate_sell_adjustment_ratio_with_identical_values(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'calculateSellAdjustmentRatio');
        $method->setAccessible(true);

        $target = Money::fromString('USD', '100.000', 3);
        $actual = Money::fromString('USD', '100.000', 3);

        $ratio = $method->invoke($materializer, $target, $actual, 3);

        self::assertSame('1.000000000', $ratio);
    }

    public function test_calculate_sell_adjustment_ratio_with_large_target(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'calculateSellAdjustmentRatio');
        $method->setAccessible(true);

        $target = Money::fromString('USD', '1000.000', 3);
        $actual = Money::fromString('USD', '100.000', 3);

        $ratio = $method->invoke($materializer, $target, $actual, 3);

        self::assertSame('10.000000000', $ratio);
    }

    public function test_is_within_sell_resolution_tolerance_with_exact_match(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'isWithinSellResolutionTolerance');
        $method->setAccessible(true);

        $target = Money::fromString('USD', '100.000000', 6);
        $actual = Money::fromString('USD', '100.000000', 6);

        self::assertTrue($method->invoke($materializer, $target, $actual));
    }

    public function test_is_within_sell_resolution_tolerance_with_large_difference(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'isWithinSellResolutionTolerance');
        $method->setAccessible(true);

        $target = Money::fromString('USD', '100.000000', 6);
        $actual = Money::fromString('USD', '200.000000', 6);

        self::assertFalse($method->invoke($materializer, $target, $actual));
    }

    public function test_convert_fees_to_map_with_empty_breakdown(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'convertFeesToMap');
        $method->setAccessible(true);

        $emptyFees = FeeBreakdown::none();
        $map = $method->invoke($materializer, $emptyFees);

        self::assertInstanceOf(MoneyMap::class, $map);
        self::assertTrue($map->isEmpty());
    }

    public function test_convert_fees_to_map_with_single_fee(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'convertFeesToMap');
        $method->setAccessible(true);

        $fees = FeeBreakdown::forBase(Money::fromString('USD', '10.00', 2));
        $map = $method->invoke($materializer, $fees);

        self::assertInstanceOf(MoneyMap::class, $map);
        self::assertFalse($map->isEmpty());
        self::assertTrue($map->has('USD'));
        self::assertSame('10.00', $this->fee($map, 'USD')->amount());
    }

    public function test_reduce_budget_with_exact_match(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'reduceBudget');
        $method->setAccessible(true);

        $budget = Money::fromString('USD', '100.00', 2);
        $spent = Money::fromString('USD', '100.00', 2);

        $remaining = $method->invoke($materializer, $budget, $spent);

        self::assertSame('0.00', $remaining->amount());
    }

    public function test_reduce_budget_with_partial_spend(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'reduceBudget');
        $method->setAccessible(true);

        $budget = Money::fromString('USD', '100.00', 2);
        $spent = Money::fromString('USD', '30.00', 2);

        $remaining = $method->invoke($materializer, $budget, $spent);

        self::assertSame('70.00', $remaining->amount());
    }

    private function sellResolutionExtraScale(): int
    {
        $reflection = new ReflectionClass(LegMaterializer::class);
        $constant = $reflection->getReflectionConstant('SELL_RESOLUTION_RATIO_EXTRA_SCALE');
        self::assertNotFalse($constant);

        return (int) $constant->getValue();
    }

    private function fee(MoneyMap $fees, string $currency): Money
    {
        $fee = $fees->get($currency);
        self::assertNotNull($fee, sprintf('Missing fee for currency "%s".', $currency));

        return $fee;
    }
}
