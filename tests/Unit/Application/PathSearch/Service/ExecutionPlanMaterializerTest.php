<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionStepCollection;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanMaterializer;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\LegMaterializer;
use SomeWork\P2PPathFinder\Application\PathSearch\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

#[CoversClass(ExecutionPlanMaterializer::class)]
final class ExecutionPlanMaterializerTest extends TestCase
{
    private ExecutionPlanMaterializer $materializer;

    protected function setUp(): void
    {
        $this->materializer = new ExecutionPlanMaterializer();
    }

    public function test_returns_null_for_empty_fills(): void
    {
        $result = $this->materializer->materialize(
            [],
            'USD',
            'BTC',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertNull($result);
    }

    public function test_materialize_single_step_buy_order(): void
    {
        // BUY order: taker spends base (USDT), receives quote (RUB)
        $order = OrderFactory::buy('USDT', 'RUB', '10.00', '1000.00', '90.00', 2, 2);
        $spend = Money::fromString('USDT', '100.00', 2);

        $result = $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'USDT',
            'RUB',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);
        self::assertSame(1, $result->stepCount());
        self::assertSame('USDT', $result->sourceCurrency());
        self::assertSame('RUB', $result->targetCurrency());
        self::assertSame('100.00', $result->totalSpent()->amount());
        self::assertSame('9000.00', $result->totalReceived()->amount());
    }

    public function test_materialize_single_step_sell_order(): void
    {
        // SELL order: taker spends quote (RUB), receives base (USDT)
        // Use a nice round rate to avoid precision issues (100 RUB per USDT)
        $order = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '100.00', 2, 6);
        $spend = Money::fromString('RUB', '10000.00', 6);

        $result = $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'RUB',
            'USDT',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);
        self::assertSame(1, $result->stepCount());
        self::assertSame('RUB', $result->sourceCurrency());
        self::assertSame('USDT', $result->targetCurrency());
        self::assertSame('10000.000000', $result->totalSpent()->amount());
        // 10000 RUB / 100 rate = 100 USDT (scale comes from rate scale)
        self::assertSame('100.000000', $result->totalReceived()->amount());
    }

    public function test_materialize_linear_path(): void
    {
        // Linear: RUB -> USDT -> IDR
        // For BUY orders: taker spends base, receives quote
        // For SELL orders: taker spends quote, receives base

        // Path: RUB -> USDT -> IDR
        // Step 1: SELL order USDT/RUB - taker spends RUB, receives USDT
        // Using scale 6 for rate to avoid precision loss on inversion (1/100 = 0.01)
        $order1 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '100.00', 2, 6);
        // Step 2: BUY order USDT/IDR - taker spends USDT, receives IDR
        $order2 = OrderFactory::buy('USDT', 'IDR', '10.00', '1000.00', '15000.00', 2, 2);

        // Spend 10000 RUB -> receive 100 USDT (10000 / 100)
        $fill1 = ['order' => $order1, 'spend' => Money::fromString('RUB', '10000.00', 6), 'sequence' => 1];
        // Spend 100 USDT -> receive 1500000 IDR (100 * 15000)
        $fill2 = ['order' => $order2, 'spend' => Money::fromString('USDT', '100.00', 2), 'sequence' => 2];

        $result = $this->materializer->materialize(
            [$fill1, $fill2],
            'RUB',
            'IDR',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);
        self::assertSame(2, $result->stepCount());
        self::assertTrue($result->isLinear());
        self::assertSame('RUB', $result->sourceCurrency());
        self::assertSame('IDR', $result->targetCurrency());
        self::assertSame('10000.000000', $result->totalSpent()->amount());
        self::assertSame('1500000.00', $result->totalReceived()->amount());
    }

    public function test_materialize_split_path(): void
    {
        // Split: USD -> BTC (order1), USD -> BTC (order2) using different amounts
        $order1 = OrderFactory::buy('USD', 'BTC', '50.00', '500.00', '0.00002', 2, 8);
        $order2 = OrderFactory::buy('USD', 'BTC', '50.00', '500.00', '0.000021', 2, 8);

        $fill1 = ['order' => $order1, 'spend' => Money::fromString('USD', '100.00', 2), 'sequence' => 1];
        $fill2 = ['order' => $order2, 'spend' => Money::fromString('USD', '150.00', 2), 'sequence' => 1];

        $result = $this->materializer->materialize(
            [$fill1, $fill2],
            'USD',
            'BTC',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);
        self::assertSame(2, $result->stepCount());
        // This is a split (two steps from same source), so not linear
        self::assertFalse($result->isLinear());
        self::assertSame('USD', $result->sourceCurrency());
        self::assertSame('BTC', $result->targetCurrency());
        // Total spent: 100 + 150 = 250
        self::assertSame('250.00', $result->totalSpent()->amount());
    }

    public function test_materialize_merge_path(): void
    {
        // Merge: BTC -> USDT, ETH -> USDT
        $order1 = OrderFactory::buy('BTC', 'USDT', '0.001', '1.000', '40000.00', 3, 2);
        $order2 = OrderFactory::buy('ETH', 'USDT', '0.01', '10.00', '2000.00', 2, 2);

        $fill1 = ['order' => $order1, 'spend' => Money::fromString('BTC', '0.010', 3), 'sequence' => 1];
        $fill2 = ['order' => $order2, 'spend' => Money::fromString('ETH', '0.20', 2), 'sequence' => 1];

        $result = $this->materializer->materialize(
            [$fill1, $fill2],
            'BTC',
            'USDT',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);
        self::assertSame(2, $result->stepCount());
        // This is a merge (two steps to same target), so not linear
        self::assertFalse($result->isLinear());
    }

    public function test_materialize_complex_path(): void
    {
        // Complex: USD splits to BTC and ETH, then BTC->USDT and ETH->USDT merge
        $usdToBtc = OrderFactory::buy('USD', 'BTC', '50.00', '500.00', '0.00002', 2, 8);
        $usdToEth = OrderFactory::buy('USD', 'ETH', '50.00', '500.00', '0.0005', 2, 6);
        $btcToUsdt = OrderFactory::buy('BTC', 'USDT', '0.001', '1.000', '40000.00', 3, 2);
        $ethToUsdt = OrderFactory::buy('ETH', 'USDT', '0.01', '10.00', '2000.00', 2, 2);

        $fills = [
            ['order' => $usdToBtc, 'spend' => Money::fromString('USD', '100.00', 2), 'sequence' => 1],
            ['order' => $usdToEth, 'spend' => Money::fromString('USD', '100.00', 2), 'sequence' => 1],
            ['order' => $btcToUsdt, 'spend' => Money::fromString('BTC', '0.002', 3), 'sequence' => 2],
            ['order' => $ethToUsdt, 'spend' => Money::fromString('ETH', '0.05', 2), 'sequence' => 2],
        ];

        $result = $this->materializer->materialize(
            $fills,
            'USD',
            'USDT',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);
        self::assertSame(4, $result->stepCount());
        self::assertFalse($result->isLinear());
        self::assertSame('USD', $result->sourceCurrency());
        self::assertSame('USDT', $result->targetCurrency());
        // Total spent: 100 + 100 = 200 USD
        self::assertSame('200.00', $result->totalSpent()->amount());
    }

    public function test_fee_calculation_correct(): void
    {
        // Order with fees
        $feePolicy = FeePolicyFactory::baseSurcharge('0.01', 6); // 1% base fee
        $order = OrderFactory::buy('USDT', 'RUB', '10.00', '1000.00', '90.00', 2, 2, $feePolicy);
        $spend = Money::fromString('USDT', '100.00', 2);

        $result = $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'USDT',
            'RUB',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);
        $feeBreakdown = $result->feeBreakdown();
        // Should have USDT fee
        self::assertTrue($feeBreakdown->has('USDT'));
        // Fee is 1% of 100 USDT = 1 USDT
        self::assertSame('1.00', $feeBreakdown->get('USDT')?->amount());
    }

    public function test_sequence_numbers_preserved(): void
    {
        // Create fills with specific sequence numbers
        $order1 = OrderFactory::buy('USD', 'BTC', '50.00', '500.00', '0.00002', 2, 8);
        $order2 = OrderFactory::buy('BTC', 'USDT', '0.001', '1.000', '40000.00', 3, 2);

        $fills = [
            ['order' => $order1, 'spend' => Money::fromString('USD', '100.00', 2), 'sequence' => 3],
            ['order' => $order2, 'spend' => Money::fromString('BTC', '0.002', 3), 'sequence' => 7],
        ];

        $result = $this->materializer->materialize(
            $fills,
            'USD',
            'USDT',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);

        $steps = $result->steps()->all();
        // ExecutionStepCollection sorts by sequence
        self::assertSame(3, $steps[0]->sequenceNumber());
        self::assertSame(7, $steps[1]->sequenceNumber());
    }

    public function test_throws_for_invalid_currency(): void
    {
        // BUY order expects spend in base currency (USDT), but we provide wrong currency (RUB)
        $order = OrderFactory::buy('USDT', 'RUB', '10.00', '1000.00', '90.00', 2, 2);
        $wrongSpend = Money::fromString('RUB', '9000.00', 2);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Spend currency "RUB" does not match expected source currency "USDT" for BUY order.');

        $this->materializer->materialize(
            [['order' => $order, 'spend' => $wrongSpend, 'sequence' => 1]],
            'RUB',
            'USDT',
            DecimalTolerance::fromNumericString('0.01'),
        );
    }

    public function test_throws_for_zero_spend(): void
    {
        $order = OrderFactory::buy('USDT', 'RUB', '10.00', '1000.00', '90.00', 2, 2);
        $zeroSpend = Money::fromString('USDT', '0.00', 2);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Cannot process order fill with zero spend amount.');

        $this->materializer->materialize(
            [['order' => $order, 'spend' => $zeroSpend, 'sequence' => 1]],
            'USDT',
            'RUB',
            DecimalTolerance::fromNumericString('0.01'),
        );
    }

    public function test_throws_for_invalid_sequence(): void
    {
        $order = OrderFactory::buy('USDT', 'RUB', '10.00', '1000.00', '90.00', 2, 2);
        $spend = Money::fromString('USDT', '100.00', 2);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Execution step sequence number must be at least 1, got: 0');

        $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 0]],
            'USDT',
            'RUB',
            DecimalTolerance::fromNumericString('0.01'),
        );
    }

    public function test_throws_for_negative_sequence(): void
    {
        $order = OrderFactory::buy('USDT', 'RUB', '10.00', '1000.00', '90.00', 2, 2);
        $spend = Money::fromString('USDT', '100.00', 2);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Execution step sequence number must be at least 1, got: -1');

        $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => -1]],
            'USDT',
            'RUB',
            DecimalTolerance::fromNumericString('0.01'),
        );
    }

    public function test_aggregates_fees_correctly(): void
    {
        // Two orders with fees, verify aggregation
        $feePolicy1 = FeePolicyFactory::baseSurcharge('0.01', 6);
        $feePolicy2 = FeePolicyFactory::baseSurcharge('0.02', 6);

        $order1 = OrderFactory::buy('USD', 'BTC', '50.00', '500.00', '0.00002', 2, 8, $feePolicy1);
        $order2 = OrderFactory::buy('USD', 'BTC', '50.00', '500.00', '0.00002', 2, 8, $feePolicy2);

        $fills = [
            ['order' => $order1, 'spend' => Money::fromString('USD', '100.00', 2), 'sequence' => 1],
            ['order' => $order2, 'spend' => Money::fromString('USD', '100.00', 2), 'sequence' => 1],
        ];

        $result = $this->materializer->materialize(
            $fills,
            'USD',
            'BTC',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);

        $feeBreakdown = $result->feeBreakdown();
        self::assertTrue($feeBreakdown->has('USD'));
        // Fee from order1: 1% of 100 = 1.00
        // Fee from order2: 2% of 100 = 2.00
        // Total: 3.00
        self::assertSame('3.00', $feeBreakdown->get('USD')?->amount());
    }

    public function test_returns_null_for_spend_outside_bounds(): void
    {
        // Order has bounds 10-100, but we try to spend 500
        $order = OrderFactory::buy('USDT', 'RUB', '10.00', '100.00', '90.00', 2, 2);
        $spend = Money::fromString('USDT', '500.00', 2);

        $result = $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'USDT',
            'RUB',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertNull($result);
    }

    public function test_returns_null_for_spend_below_minimum(): void
    {
        // Order has bounds 10-100, but we try to spend 5
        $order = OrderFactory::buy('USDT', 'RUB', '10.00', '100.00', '90.00', 2, 2);
        $spend = Money::fromString('USDT', '5.00', 2);

        $result = $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'USDT',
            'RUB',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertNull($result);
    }

    public function test_tolerance_passed_to_plan(): void
    {
        $order = OrderFactory::buy('USDT', 'RUB', '10.00', '1000.00', '90.00', 2, 2);
        $spend = Money::fromString('USDT', '100.00', 2);
        $tolerance = DecimalTolerance::fromNumericString('0.05');

        $result = $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'USDT',
            'RUB',
            $tolerance,
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);
        self::assertSame('0.050000000000000000', $result->residualTolerance()->ratio());
    }

    public function test_materializer_accepts_custom_dependencies(): void
    {
        $fillEvaluator = new OrderFillEvaluator();
        $legMaterializer = new LegMaterializer($fillEvaluator);
        $materializer = new ExecutionPlanMaterializer($fillEvaluator, $legMaterializer);

        $order = OrderFactory::buy('USDT', 'RUB', '10.00', '1000.00', '90.00', 2, 2);
        $spend = Money::fromString('USDT', '100.00', 2);

        $result = $materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'USDT',
            'RUB',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);
    }

    public function test_step_from_and_to_currencies_correct_for_buy(): void
    {
        // BUY order USDT/RUB: taker spends USDT (base), receives RUB (quote)
        $order = OrderFactory::buy('USDT', 'RUB', '10.00', '1000.00', '90.00', 2, 2);
        $spend = Money::fromString('USDT', '100.00', 2);

        $result = $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'USDT',
            'RUB',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);

        $step = $result->steps()->first();
        self::assertNotNull($step);
        self::assertSame('USDT', $step->from());
        self::assertSame('RUB', $step->to());
        self::assertSame('USDT', $step->spent()->currency());
        self::assertSame('RUB', $step->received()->currency());
    }

    public function test_step_from_and_to_currencies_correct_for_sell(): void
    {
        // SELL order USDT/RUB: taker spends RUB (quote), receives USDT (base)
        $order = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '90.00', 2, 2);
        $spend = Money::fromString('RUB', '9000.00', 2);

        $result = $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'RUB',
            'USDT',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);

        $step = $result->steps()->first();
        self::assertNotNull($step);
        self::assertSame('RUB', $step->from());
        self::assertSame('USDT', $step->to());
        self::assertSame('RUB', $step->spent()->currency());
        self::assertSame('USDT', $step->received()->currency());
    }

    public function test_sell_order_with_fees(): void
    {
        // SELL order with fees
        $feePolicy = FeePolicyFactory::baseAndQuoteSurcharge('0.01', '0.005', 6);
        $order = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '90.00', 2, 2, $feePolicy);
        $spend = Money::fromString('RUB', '9000.00', 2);

        $result = $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'RUB',
            'USDT',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);

        $feeBreakdown = $result->feeBreakdown();
        // Should have fees calculated
        self::assertFalse($feeBreakdown->isEmpty());
    }

    public function test_order_preserved_in_step(): void
    {
        $order = OrderFactory::buy('USDT', 'RUB', '10.00', '1000.00', '90.00', 2, 2);
        $spend = Money::fromString('USDT', '100.00', 2);

        $result = $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'USDT',
            'RUB',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);

        $step = $result->steps()->first();
        self::assertNotNull($step);
        self::assertSame($order, $step->order());
    }

    public function test_multiple_fills_processed_in_order(): void
    {
        $order1 = OrderFactory::buy('USD', 'BTC', '50.00', '500.00', '0.00002', 2, 8);
        $order2 = OrderFactory::buy('BTC', 'USDT', '0.001', '1.000', '40000.00', 3, 2);
        $order3 = OrderFactory::buy('USDT', 'IDR', '10.00', '10000.00', '15000.00', 2, 2);

        $fills = [
            ['order' => $order1, 'spend' => Money::fromString('USD', '100.00', 2), 'sequence' => 1],
            ['order' => $order2, 'spend' => Money::fromString('BTC', '0.002', 3), 'sequence' => 2],
            ['order' => $order3, 'spend' => Money::fromString('USDT', '80.00', 2), 'sequence' => 3],
        ];

        $result = $this->materializer->materialize(
            $fills,
            'USD',
            'IDR',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);
        self::assertSame(3, $result->stepCount());

        // Verify it's linear
        self::assertTrue($result->isLinear());
    }

    public function test_throws_when_any_fill_has_currency_mismatch(): void
    {
        // First fill is valid
        $order1 = OrderFactory::buy('USD', 'BTC', '50.00', '500.00', '0.00002', 2, 8);
        // Second fill has wrong currency
        $order2 = OrderFactory::buy('BTC', 'USDT', '0.001', '1.000', '40000.00', 3, 2);

        $fills = [
            ['order' => $order1, 'spend' => Money::fromString('USD', '100.00', 2), 'sequence' => 1],
            // Wrong currency: BTC order expects BTC spend, but we provide USDT
            ['order' => $order2, 'spend' => Money::fromString('USDT', '80.00', 2), 'sequence' => 2],
        ];

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Spend currency "USDT" does not match expected source currency "BTC" for BUY order.');

        $this->materializer->materialize(
            $fills,
            'USD',
            'USDT',
            DecimalTolerance::fromNumericString('0.01'),
        );
    }

    public function test_high_precision_amounts(): void
    {
        // Test with high precision amounts (8 decimal places like BTC)
        $order = OrderFactory::buy('BTC', 'USDT', '0.00000100', '10.00000000', '40000.00', 8, 8);
        $spend = Money::fromString('BTC', '0.00100000', 8);

        $result = $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'BTC',
            'USDT',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);
        self::assertSame('0.00100000', $result->totalSpent()->amount());
        // 0.001 BTC * 40000 rate = 40 USDT (scale matches rate scale of 8)
        self::assertSame('40.00000000', $result->totalReceived()->amount());
    }

    public function test_sell_order_correctly_inverts_rate(): void
    {
        // SELL order: 1 USDT = 100 RUB (nice round rate)
        // Taker spends 1000 RUB, should receive 10 USDT
        $order = OrderFactory::sell('USDT', 'RUB', '1.00', '1000.00', '100.00', 2, 6);
        $spend = Money::fromString('RUB', '1000.00', 6);

        $result = $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'RUB',
            'USDT',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);
        self::assertSame('1000.000000', $result->totalSpent()->amount());
        // 1000 RUB / 100 = 10 USDT (scale from rate)
        self::assertSame('10.000000', $result->totalReceived()->amount());
    }

    public function test_sell_order_returns_null_when_received_exceeds_max_bounds(): void
    {
        // SELL order with bounds 10-100 USDT (base)
        // Rate 100 RUB per USDT
        // If we spend 20000 RUB, we'd receive 200 USDT which exceeds max bound
        $order = OrderFactory::sell('USDT', 'RUB', '10.00', '100.00', '100.00', 2, 6);
        $spend = Money::fromString('RUB', '20000.00', 6);

        $result = $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'RUB',
            'USDT',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertNull($result);
    }

    public function test_sell_order_returns_null_when_received_below_min_bounds(): void
    {
        // SELL order with bounds 10-100 USDT (base)
        // Rate 100 RUB per USDT
        // If we spend 500 RUB, we'd receive 5 USDT which is below min bound
        $order = OrderFactory::sell('USDT', 'RUB', '10.00', '100.00', '100.00', 2, 6);
        $spend = Money::fromString('RUB', '500.00', 6);

        $result = $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'RUB',
            'USDT',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertNull($result);
    }

    public function test_sell_order_succeeds_at_boundary_values(): void
    {
        // SELL order with bounds 10-100 USDT (base)
        // Rate 100 RUB per USDT
        // Spend 1000 RUB -> receive 10 USDT (exactly at min bound)
        $order = OrderFactory::sell('USDT', 'RUB', '10.00', '100.00', '100.00', 2, 6);
        $spend = Money::fromString('RUB', '1000.00', 6);

        $result = $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'RUB',
            'USDT',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);
        self::assertSame('10.000000', $result->totalReceived()->amount());
    }

    public function test_buy_order_succeeds_at_boundary_values(): void
    {
        // BUY order with bounds 10-100 USDT (base = what taker spends)
        // Spend exactly 10 USDT (at min bound)
        $order = OrderFactory::buy('USDT', 'RUB', '10.00', '100.00', '90.00', 2, 2);
        $spend = Money::fromString('USDT', '10.00', 2);

        $result = $this->materializer->materialize(
            [['order' => $order, 'spend' => $spend, 'sequence' => 1]],
            'USDT',
            'RUB',
            DecimalTolerance::fromNumericString('0.01'),
        );

        self::assertInstanceOf(ExecutionPlan::class, $result);
        self::assertSame('10.00', $result->totalSpent()->amount());
        // 10 * 90 = 900 RUB
        self::assertSame('900.00', $result->totalReceived()->amount());
    }
}
