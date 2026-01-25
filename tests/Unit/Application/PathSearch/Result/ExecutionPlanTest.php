<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionStep;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionStepCollection;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\Path;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

#[CoversClass(ExecutionPlan::class)]
#[CoversClass(ExecutionStepCollection::class)]
final class ExecutionPlanTest extends TestCase
{
    public function test_linear_plan_aggregates_totals(): void
    {
        // Linear: USD -> BTC -> ETH
        $step1 = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::fromList([Money::fromString('USD', '1.00', 2)]),
            1,
        );

        $step2 = new ExecutionStep(
            'BTC',
            'ETH',
            Money::fromString('BTC', '0.00250000', 8),
            Money::fromString('ETH', '0.05000000', 8),
            OrderFactory::sell('BTC', 'ETH'),
            MoneyMap::fromList([Money::fromString('BTC', '0.00001000', 8)]),
            2,
        );

        $steps = ExecutionStepCollection::fromList([$step1, $step2]);
        $tolerance = DecimalTolerance::fromNumericString('0.01');

        $plan = ExecutionPlan::fromSteps($steps, 'USD', 'ETH', $tolerance);

        self::assertSame('100.00', $plan->totalSpent()->amount());
        self::assertSame('USD', $plan->totalSpent()->currency());
        self::assertSame('0.05000000', $plan->totalReceived()->amount());
        self::assertSame('ETH', $plan->totalReceived()->currency());
        self::assertSame(2, $plan->stepCount());
    }

    public function test_split_plan_aggregates_totals(): void
    {
        // Split: USD -> BTC and USD -> ETH, then both merge into USDT
        // This represents: spend 100 USD to get BTC, spend 50 USD to get ETH
        $step1 = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $step2 = new ExecutionStep(
            'USD',
            'ETH',
            Money::fromString('USD', '50.00', 2),
            Money::fromString('ETH', '0.02500000', 8),
            OrderFactory::sell('USD', 'ETH'),
            MoneyMap::empty(),
            1,
        );

        $step3 = new ExecutionStep(
            'BTC',
            'USDT',
            Money::fromString('BTC', '0.00250000', 8),
            Money::fromString('USDT', '100.00', 2),
            OrderFactory::sell('BTC', 'USDT'),
            MoneyMap::empty(),
            2,
        );

        $step4 = new ExecutionStep(
            'ETH',
            'USDT',
            Money::fromString('ETH', '0.02500000', 8),
            Money::fromString('USDT', '50.00', 2),
            OrderFactory::sell('ETH', 'USDT'),
            MoneyMap::empty(),
            2,
        );

        $steps = ExecutionStepCollection::fromList([$step1, $step2, $step3, $step4]);
        $tolerance = DecimalTolerance::fromNumericString('0.01');

        $plan = ExecutionPlan::fromSteps($steps, 'USD', 'USDT', $tolerance);

        // Total spent should be sum of USD spends: 100 + 50 = 150
        self::assertSame('150.00', $plan->totalSpent()->amount());
        self::assertSame('USD', $plan->totalSpent()->currency());

        // Total received should be sum of USDT receives: 100 + 50 = 150
        self::assertSame('150.00', $plan->totalReceived()->amount());
        self::assertSame('USDT', $plan->totalReceived()->currency());

        self::assertSame(4, $plan->stepCount());
    }

    public function test_is_linear_true_for_chain(): void
    {
        // Linear: USD -> BTC -> ETH
        $step1 = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $step2 = new ExecutionStep(
            'BTC',
            'ETH',
            Money::fromString('BTC', '0.00250000', 8),
            Money::fromString('ETH', '0.05000000', 8),
            OrderFactory::sell('BTC', 'ETH'),
            MoneyMap::empty(),
            2,
        );

        $steps = ExecutionStepCollection::fromList([$step1, $step2]);
        $tolerance = DecimalTolerance::fromNumericString('0.01');

        $plan = ExecutionPlan::fromSteps($steps, 'USD', 'ETH', $tolerance);

        self::assertTrue($plan->isLinear());
    }

    public function test_is_linear_true_for_single_step(): void
    {
        $step = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $steps = ExecutionStepCollection::fromList([$step]);
        $tolerance = DecimalTolerance::fromNumericString('0.01');

        $plan = ExecutionPlan::fromSteps($steps, 'USD', 'BTC', $tolerance);

        self::assertTrue($plan->isLinear());
    }

    public function test_is_linear_false_for_split(): void
    {
        // Split: USD -> BTC and USD -> ETH (two steps with same source)
        $step1 = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $step2 = new ExecutionStep(
            'USD',
            'ETH',
            Money::fromString('USD', '50.00', 2),
            Money::fromString('ETH', '0.02500000', 8),
            OrderFactory::sell('USD', 'ETH'),
            MoneyMap::empty(),
            1,
        );

        $steps = ExecutionStepCollection::fromList([$step1, $step2]);
        $tolerance = DecimalTolerance::fromNumericString('0.01');

        $plan = ExecutionPlan::fromSteps($steps, 'USD', 'BTC', $tolerance);

        self::assertFalse($plan->isLinear());
    }

    public function test_is_linear_false_for_merge(): void
    {
        // Merge: BTC -> USDT and ETH -> USDT (two steps with same destination)
        $step1 = new ExecutionStep(
            'BTC',
            'USDT',
            Money::fromString('BTC', '0.00250000', 8),
            Money::fromString('USDT', '100.00', 2),
            OrderFactory::sell('BTC', 'USDT'),
            MoneyMap::empty(),
            1,
        );

        $step2 = new ExecutionStep(
            'ETH',
            'USDT',
            Money::fromString('ETH', '0.02500000', 8),
            Money::fromString('USDT', '50.00', 2),
            OrderFactory::sell('ETH', 'USDT'),
            MoneyMap::empty(),
            1,
        );

        $steps = ExecutionStepCollection::fromList([$step1, $step2]);
        $tolerance = DecimalTolerance::fromNumericString('0.01');

        // Both start currencies are different (BTC, ETH), target is USDT
        // Using BTC as source to test - should not be linear due to merge
        $plan = ExecutionPlan::fromSteps($steps, 'BTC', 'USDT', $tolerance);

        self::assertFalse($plan->isLinear());
    }

    public function test_as_linear_path_returns_path_for_linear(): void
    {
        // Linear: USD -> BTC -> ETH
        $step1 = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::fromList([Money::fromString('USD', '1.00', 2)]),
            1,
        );

        $step2 = new ExecutionStep(
            'BTC',
            'ETH',
            Money::fromString('BTC', '0.00250000', 8),
            Money::fromString('ETH', '0.05000000', 8),
            OrderFactory::sell('BTC', 'ETH'),
            MoneyMap::fromList([Money::fromString('BTC', '0.00001000', 8)]),
            2,
        );

        $steps = ExecutionStepCollection::fromList([$step1, $step2]);
        $tolerance = DecimalTolerance::fromNumericString('0.01');

        $plan = ExecutionPlan::fromSteps($steps, 'USD', 'ETH', $tolerance);

        $path = $plan->asLinearPath();

        self::assertInstanceOf(Path::class, $path);
        self::assertSame(2, $path->hops()->count());
        self::assertSame('100.00', $path->totalSpent()->amount());
        self::assertSame('0.05000000', $path->totalReceived()->amount());

        // Verify hop order
        $hops = $path->hopsAsArray();
        self::assertSame('USD', $hops[0]->from());
        self::assertSame('BTC', $hops[0]->to());
        self::assertSame('BTC', $hops[1]->from());
        self::assertSame('ETH', $hops[1]->to());
    }

    public function test_as_linear_path_returns_null_for_split(): void
    {
        // Split: USD -> BTC and USD -> ETH
        $step1 = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $step2 = new ExecutionStep(
            'USD',
            'ETH',
            Money::fromString('USD', '50.00', 2),
            Money::fromString('ETH', '0.02500000', 8),
            OrderFactory::sell('USD', 'ETH'),
            MoneyMap::empty(),
            1,
        );

        $steps = ExecutionStepCollection::fromList([$step1, $step2]);
        $tolerance = DecimalTolerance::fromNumericString('0.01');

        $plan = ExecutionPlan::fromSteps($steps, 'USD', 'BTC', $tolerance);

        self::assertNull($plan->asLinearPath());
    }

    public function test_fee_merging_across_branches(): void
    {
        // Split scenario with fees on multiple branches
        $step1 = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::fromList([
                Money::fromString('USD', '1.00', 2),
                Money::fromString('BTC', '0.00001000', 8),
            ]),
            1,
        );

        $step2 = new ExecutionStep(
            'USD',
            'ETH',
            Money::fromString('USD', '50.00', 2),
            Money::fromString('ETH', '0.02500000', 8),
            OrderFactory::sell('USD', 'ETH'),
            MoneyMap::fromList([
                Money::fromString('USD', '0.50', 2),
                Money::fromString('ETH', '0.00010000', 8),
            ]),
            1,
        );

        $step3 = new ExecutionStep(
            'BTC',
            'USDT',
            Money::fromString('BTC', '0.00250000', 8),
            Money::fromString('USDT', '100.00', 2),
            OrderFactory::sell('BTC', 'USDT'),
            MoneyMap::fromList([
                Money::fromString('USDT', '0.10', 2),
            ]),
            2,
        );

        $step4 = new ExecutionStep(
            'ETH',
            'USDT',
            Money::fromString('ETH', '0.02500000', 8),
            Money::fromString('USDT', '50.00', 2),
            OrderFactory::sell('ETH', 'USDT'),
            MoneyMap::fromList([
                Money::fromString('USDT', '0.05', 2),
            ]),
            2,
        );

        $steps = ExecutionStepCollection::fromList([$step1, $step2, $step3, $step4]);
        $tolerance = DecimalTolerance::fromNumericString('0.01');

        $plan = ExecutionPlan::fromSteps($steps, 'USD', 'USDT', $tolerance);

        $feeBreakdown = $plan->feeBreakdown();

        // USD fees: 1.00 + 0.50 = 1.50
        self::assertTrue($feeBreakdown->has('USD'));
        self::assertSame('1.50', $feeBreakdown->get('USD')?->amount());

        // BTC fees: 0.00001000
        self::assertTrue($feeBreakdown->has('BTC'));
        self::assertSame('0.00001000', $feeBreakdown->get('BTC')?->amount());

        // ETH fees: 0.00010000
        self::assertTrue($feeBreakdown->has('ETH'));
        self::assertSame('0.00010000', $feeBreakdown->get('ETH')?->amount());

        // USDT fees: 0.10 + 0.05 = 0.15
        self::assertTrue($feeBreakdown->has('USDT'));
        self::assertSame('0.15', $feeBreakdown->get('USDT')?->amount());
    }

    public function test_to_array_serialization(): void
    {
        $step = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::fromList([Money::fromString('USD', '1.00', 2)]),
            1,
        );

        $steps = ExecutionStepCollection::fromList([$step]);
        $tolerance = DecimalTolerance::fromNumericString('0.05');

        $plan = ExecutionPlan::fromSteps($steps, 'USD', 'BTC', $tolerance);

        $array = $plan->toArray();

        self::assertSame('USD', $array['sourceCurrency']);
        self::assertSame('BTC', $array['targetCurrency']);
        self::assertSame('100.00', $array['totalSpent']);
        self::assertSame('0.00250000', $array['totalReceived']);
        self::assertSame('0.050000000000000000', $array['residualTolerance']);
        self::assertArrayHasKey('steps', $array);
        self::assertCount(1, $array['steps']);
        self::assertArrayHasKey('feeBreakdown', $array);
        self::assertSame('1.00', $array['feeBreakdown']['USD']);
    }

    public function test_from_steps_requires_non_empty_collection(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Execution plan must contain at least one step.');

        ExecutionPlan::fromSteps(
            ExecutionStepCollection::empty(),
            'USD',
            'BTC',
            DecimalTolerance::fromNumericString('0.01'),
        );
    }

    public function test_from_steps_requires_non_empty_source_currency(): void
    {
        $step = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Source currency cannot be empty.');

        ExecutionPlan::fromSteps(
            ExecutionStepCollection::fromList([$step]),
            '  ',
            'BTC',
            DecimalTolerance::fromNumericString('0.01'),
        );
    }

    public function test_from_steps_requires_non_empty_target_currency(): void
    {
        $step = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Target currency cannot be empty.');

        ExecutionPlan::fromSteps(
            ExecutionStepCollection::fromList([$step]),
            'USD',
            '',
            DecimalTolerance::fromNumericString('0.01'),
        );
    }

    public function test_from_steps_requires_steps_spending_source_currency(): void
    {
        $step = new ExecutionStep(
            'BTC',
            'ETH',
            Money::fromString('BTC', '0.00250000', 8),
            Money::fromString('ETH', '0.05000000', 8),
            OrderFactory::sell('BTC', 'ETH'),
            MoneyMap::empty(),
            1,
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('No steps found spending the source currency.');

        ExecutionPlan::fromSteps(
            ExecutionStepCollection::fromList([$step]),
            'USD',
            'ETH',
            DecimalTolerance::fromNumericString('0.01'),
        );
    }

    public function test_from_steps_requires_steps_receiving_target_currency(): void
    {
        $step = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('No steps found receiving the target currency.');

        ExecutionPlan::fromSteps(
            ExecutionStepCollection::fromList([$step]),
            'USD',
            'ETH',
            DecimalTolerance::fromNumericString('0.01'),
        );
    }

    public function test_getters_expose_all_properties(): void
    {
        $step = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::fromList([Money::fromString('USD', '1.00', 2)]),
            1,
        );

        $steps = ExecutionStepCollection::fromList([$step]);
        $tolerance = DecimalTolerance::fromNumericString('0.05');

        $plan = ExecutionPlan::fromSteps($steps, 'usd', 'btc', $tolerance);

        self::assertSame('USD', $plan->sourceCurrency());
        self::assertSame('BTC', $plan->targetCurrency());
        self::assertSame($steps, $plan->steps());
        self::assertSame($tolerance, $plan->residualTolerance());
        self::assertSame(1, $plan->stepCount());
        self::assertFalse($plan->feeBreakdown()->isEmpty());
    }

    public function test_three_hop_linear_path_conversion(): void
    {
        // Linear: USD -> BTC -> ETH -> USDT
        $step1 = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '1000.00', 2),
            Money::fromString('BTC', '0.02500000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $step2 = new ExecutionStep(
            'BTC',
            'ETH',
            Money::fromString('BTC', '0.02500000', 8),
            Money::fromString('ETH', '0.50000000', 8),
            OrderFactory::sell('BTC', 'ETH'),
            MoneyMap::empty(),
            2,
        );

        $step3 = new ExecutionStep(
            'ETH',
            'USDT',
            Money::fromString('ETH', '0.50000000', 8),
            Money::fromString('USDT', '1000.00', 2),
            OrderFactory::sell('ETH', 'USDT'),
            MoneyMap::empty(),
            3,
        );

        $steps = ExecutionStepCollection::fromList([$step1, $step2, $step3]);
        $tolerance = DecimalTolerance::fromNumericString('0.01');

        $plan = ExecutionPlan::fromSteps($steps, 'USD', 'USDT', $tolerance);

        self::assertTrue($plan->isLinear());

        $path = $plan->asLinearPath();
        self::assertInstanceOf(Path::class, $path);
        self::assertSame(3, $path->hops()->count());

        $hops = $path->hopsAsArray();
        self::assertSame('USD', $hops[0]->from());
        self::assertSame('BTC', $hops[0]->to());
        self::assertSame('BTC', $hops[1]->from());
        self::assertSame('ETH', $hops[1]->to());
        self::assertSame('ETH', $hops[2]->from());
        self::assertSame('USDT', $hops[2]->to());
    }

    public function test_is_linear_false_for_disconnected_chain(): void
    {
        // Steps that don't form a continuous chain from source to target
        $step1 = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        // This step doesn't connect - it starts from EUR instead of BTC
        $step2 = new ExecutionStep(
            'EUR',
            'ETH',
            Money::fromString('EUR', '100.00', 2),
            Money::fromString('ETH', '0.05000000', 8),
            OrderFactory::sell('EUR', 'ETH'),
            MoneyMap::empty(),
            2,
        );

        $steps = ExecutionStepCollection::fromList([$step1, $step2]);
        $tolerance = DecimalTolerance::fromNumericString('0.01');

        // Source is USD, target is ETH - but BTC doesn't connect to EUR
        $plan = ExecutionPlan::fromSteps($steps, 'USD', 'ETH', $tolerance);

        self::assertFalse($plan->isLinear());
    }

    public function test_is_linear_false_for_single_step_not_matching_target(): void
    {
        // Single step that doesn't end at declared target
        $step = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $steps = ExecutionStepCollection::fromList([$step]);
        $tolerance = DecimalTolerance::fromNumericString('0.01');

        // Using public constructor to bypass fromSteps validation
        // Target is ETH but step ends at BTC
        $plan = new ExecutionPlan(
            $steps,
            'USD',
            'ETH',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('ETH', '0.05000000', 8),
            MoneyMap::empty(),
            $tolerance,
        );

        self::assertFalse($plan->isLinear());
        self::assertNull($plan->asLinearPath());
    }

    public function test_is_linear_false_for_single_step_not_matching_source(): void
    {
        // Single step that doesn't start at declared source
        $step = new ExecutionStep(
            'BTC',
            'ETH',
            Money::fromString('BTC', '0.00250000', 8),
            Money::fromString('ETH', '0.05000000', 8),
            OrderFactory::sell('BTC', 'ETH'),
            MoneyMap::empty(),
            1,
        );

        $steps = ExecutionStepCollection::fromList([$step]);
        $tolerance = DecimalTolerance::fromNumericString('0.01');

        // Using public constructor to bypass fromSteps validation
        // Source is USD but step starts at BTC
        $plan = new ExecutionPlan(
            $steps,
            'USD',
            'ETH',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('ETH', '0.05000000', 8),
            MoneyMap::empty(),
            $tolerance,
        );

        self::assertFalse($plan->isLinear());
        self::assertNull($plan->asLinearPath());
    }

    public function test_currency_normalization_in_from_steps(): void
    {
        $step = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $steps = ExecutionStepCollection::fromList([$step]);
        $tolerance = DecimalTolerance::fromNumericString('0.01');

        // Lowercase currencies should be normalized
        $plan = ExecutionPlan::fromSteps($steps, 'usd', 'btc', $tolerance);

        self::assertSame('USD', $plan->sourceCurrency());
        self::assertSame('BTC', $plan->targetCurrency());
        self::assertTrue($plan->isLinear());
    }

    public function test_as_linear_path_preserves_fees(): void
    {
        $fees = MoneyMap::fromList([
            Money::fromString('USD', '1.00', 2),
            Money::fromString('BTC', '0.00001000', 8),
        ]);

        $step = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            $fees,
            1,
        );

        $steps = ExecutionStepCollection::fromList([$step]);
        $tolerance = DecimalTolerance::fromNumericString('0.01');

        $plan = ExecutionPlan::fromSteps($steps, 'USD', 'BTC', $tolerance);
        $path = $plan->asLinearPath();

        self::assertInstanceOf(Path::class, $path);
        $hopFees = $path->hopsAsArray()[0]->fees();
        self::assertSame('1.00', $hopFees->get('USD')?->amount());
        self::assertSame('0.00001000', $hopFees->get('BTC')?->amount());
    }

    public function test_as_linear_path_preserves_tolerance(): void
    {
        $step = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $steps = ExecutionStepCollection::fromList([$step]);
        $tolerance = DecimalTolerance::fromNumericString('0.123456789');

        $plan = ExecutionPlan::fromSteps($steps, 'USD', 'BTC', $tolerance);
        $path = $plan->asLinearPath();

        self::assertInstanceOf(Path::class, $path);
        self::assertSame($tolerance->ratio(), $path->residualTolerance()->ratio());
    }
}
