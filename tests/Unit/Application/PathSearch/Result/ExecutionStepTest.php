<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionStep;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHop;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

#[CoversClass(ExecutionStep::class)]
final class ExecutionStepTest extends TestCase
{
    public function test_construction_normalizes_assets_and_exposes_values(): void
    {
        $spent = Money::fromString('USD', '100.00', 2);
        $received = Money::fromString('BTC', '0.00250000', 8);
        $order = OrderFactory::sell('USD', 'BTC');
        $fees = MoneyMap::fromList([
            Money::fromString('USD', '1.00', 2),
        ]);

        $step = new ExecutionStep('usd', 'btc', $spent, $received, $order, $fees, 1);

        self::assertSame('USD', $step->from());
        self::assertSame('BTC', $step->to());
        self::assertSame($spent, $step->spent());
        self::assertSame($received, $step->received());
        self::assertSame($order, $step->order());
        self::assertSame($fees, $step->fees());
        self::assertSame(1, $step->sequenceNumber());
    }

    public function test_construction_validates_spent_currency_matches_from_asset(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Execution step spent currency must match the from asset.');

        new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('EUR', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );
    }

    public function test_construction_validates_received_currency_matches_to_asset(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Execution step received currency must match the to asset.');

        new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('ETH', '0.50000000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );
    }

    public function test_construction_validates_from_asset_not_empty(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Execution step from asset cannot be empty.');

        new ExecutionStep(
            '  ',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );
    }

    public function test_construction_validates_to_asset_not_empty(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Execution step to asset cannot be empty.');

        new ExecutionStep(
            'USD',
            '',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );
    }

    public function test_construction_validates_sequence_number_minimum(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Execution step sequence number must be at least 1.');

        new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            0,
        );
    }

    public function test_construction_validates_negative_sequence_number(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Execution step sequence number must be at least 1.');

        new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            -1,
        );
    }

    public function test_to_array_serialization(): void
    {
        $spent = Money::fromString('USD', '100.00', 2);
        $received = Money::fromString('BTC', '0.00250000', 8);
        $fees = MoneyMap::fromList([
            Money::fromString('USD', '1.00', 2),
            Money::fromString('BTC', '0.00001000', 8),
        ]);

        $step = new ExecutionStep(
            'USD',
            'BTC',
            $spent,
            $received,
            OrderFactory::sell('USD', 'BTC'),
            $fees,
            3,
        );

        $array = $step->toArray();

        self::assertSame('USD', $array['from']);
        self::assertSame('BTC', $array['to']);
        self::assertSame('100.00', $array['spent']);
        self::assertSame('0.00250000', $array['received']);
        self::assertSame(3, $array['sequence']);
        self::assertArrayHasKey('USD', $array['fees']);
        self::assertArrayHasKey('BTC', $array['fees']);
        self::assertSame('1.00', $array['fees']['USD']);
        self::assertSame('0.00001000', $array['fees']['BTC']);
    }

    public function test_to_array_with_empty_fees(): void
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

        $array = $step->toArray();

        self::assertSame([], $array['fees']);
    }

    public function test_from_path_hop_conversion(): void
    {
        $spent = Money::fromString('USD', '50.00', 2);
        $received = Money::fromString('EUR', '45.00', 2);
        $order = OrderFactory::sell('USD', 'EUR');
        $fees = MoneyMap::fromList([
            Money::fromString('USD', '0.50', 2),
        ]);

        $hop = new PathHop('USD', 'EUR', $spent, $received, $order, $fees);

        $step = ExecutionStep::fromPathHop($hop, 2);

        self::assertSame('USD', $step->from());
        self::assertSame('EUR', $step->to());
        self::assertSame($spent, $step->spent());
        self::assertSame($received, $step->received());
        self::assertSame($order, $step->order());
        self::assertSame($fees, $step->fees());
        self::assertSame(2, $step->sequenceNumber());
    }

    public function test_from_path_hop_preserves_empty_fees(): void
    {
        $hop = new PathHop(
            'ETH',
            'USDT',
            Money::fromString('ETH', '1.00000000', 8),
            Money::fromString('USDT', '2000.00', 2),
            OrderFactory::sell('ETH', 'USDT'),
        );

        $step = ExecutionStep::fromPathHop($hop, 1);

        self::assertTrue($step->fees()->isEmpty());
    }

    public function test_sequence_numbers_can_be_large(): void
    {
        $step = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            999999,
        );

        self::assertSame(999999, $step->sequenceNumber());
    }

    public function test_normalize_asset_trims_and_uppercases(): void
    {
        $step = new ExecutionStep(
            '  usd  ',
            '  btc  ',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        self::assertSame('USD', $step->from());
        self::assertSame('BTC', $step->to());
    }

    public function test_from_path_hop_throws_for_sequence_zero(): void
    {
        $hop = new PathHop(
            'USD',
            'EUR',
            Money::fromString('USD', '50.00', 2),
            Money::fromString('EUR', '45.00', 2),
            OrderFactory::sell('USD', 'EUR'),
            MoneyMap::empty(),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Execution step sequence number must be at least 1.');

        ExecutionStep::fromPathHop($hop, 0);
    }

    public function test_to_array_returns_expected_keys_and_money_amounts(): void
    {
        $spent = Money::fromString('USD', '100.00', 2);
        $received = Money::fromString('BTC', '0.00250000', 8);
        $fees = MoneyMap::fromList([
            Money::fromString('USD', '1.00', 2),
        ]);

        $step = new ExecutionStep(
            'USD',
            'BTC',
            $spent,
            $received,
            OrderFactory::sell('USD', 'BTC'),
            $fees,
            2,
        );

        $array = $step->toArray();

        self::assertArrayHasKey('from', $array);
        self::assertArrayHasKey('to', $array);
        self::assertArrayHasKey('spent', $array);
        self::assertArrayHasKey('received', $array);
        self::assertArrayHasKey('fees', $array);
        self::assertArrayHasKey('sequence', $array);
        self::assertSame('USD', $array['from']);
        self::assertSame('BTC', $array['to']);
        self::assertSame('100.00', $array['spent']);
        self::assertSame('0.00250000', $array['received']);
        self::assertSame(2, $array['sequence']);
        self::assertSame(['USD' => '1.00'], $array['fees']);
    }
}
