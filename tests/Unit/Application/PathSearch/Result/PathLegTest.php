<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathLeg;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

#[CoversClass(PathLeg::class)]
final class PathLegTest extends TestCase
{
    public function test_fee_normalization_merges_duplicates_and_discards_zero_values(): void
    {
        $leg = new PathLeg(
            'btc',
            'eth',
            Money::fromString('BTC', '0.10', 8),
            Money::fromString('ETH', '1.50', 8),
            MoneyMap::fromList([
                Money::fromString('USD', '1', 2),
                Money::fromString('USD', '0.5', 2),
                Money::fromString('EUR', '0', 2),
            ], true),
        );

        $fees = $leg->fees();
        $this->assertSame(['USD'], array_keys($fees->toArray()));

        $usdFee = $fees->get('USD');
        self::assertNotNull($usdFee);
        $this->assertSame('1.50', $usdFee->amount());
    }

    public function test_zero_fee_entries_do_not_interrupt_later_fees(): void
    {
        $leg = new PathLeg(
            'usd',
            'eur',
            Money::fromString('USD', '5', 2),
            Money::fromString('EUR', '4.5', 2),
            MoneyMap::fromList([
                Money::fromString('USD', '0.00', 2),
                Money::fromString('EUR', '0.25', 2),
                Money::fromString('USD', '0.75', 2),
            ], true),
        );

        $fees = $leg->fees();
        $this->assertSame(['EUR', 'USD'], array_keys($fees->toArray()));

        $eurFee = $fees->get('EUR');
        $usdFee = $fees->get('USD');

        self::assertNotNull($eurFee);
        self::assertNotNull($usdFee);

        $this->assertSame('0.25', $eurFee->amount());
        $this->assertSame('0.75', $usdFee->amount());
    }

    public function test_empty_asset_symbol_throws_exception(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path leg from asset cannot be empty.');

        new PathLeg(
            '',
            'usd',
            Money::fromString('USD', '1', 2),
            Money::fromString('USD', '1', 2),
        );
    }

    public function test_empty_to_asset_symbol_throws_exception(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path leg to asset cannot be empty.');

        new PathLeg(
            'usd',
            '',
            Money::fromString('USD', '1', 2),
            Money::fromString('USD', '1', 2),
        );
    }

    public function test_whitespace_only_from_asset_throws_exception(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path leg from asset cannot be empty.');

        new PathLeg(
            '   ',
            'usd',
            Money::fromString('USD', '1', 2),
            Money::fromString('USD', '1', 2),
        );
    }

    public function test_whitespace_only_to_asset_throws_exception(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path leg to asset cannot be empty.');

        new PathLeg(
            'usd',
            '   ',
            Money::fromString('USD', '1', 2),
            Money::fromString('USD', '1', 2),
        );
    }

    public function test_asset_symbols_are_normalized_to_uppercase(): void
    {
        $leg = new PathLeg(
            'btc',
            'usd',
            Money::fromString('BTC', '1', 8),
            Money::fromString('USD', '30000', 2),
        );

        $this->assertSame('BTC', $leg->from());
        $this->assertSame('USD', $leg->to());
    }

    public function test_mixed_case_asset_symbols_are_normalized(): void
    {
        $leg = new PathLeg(
            'BtC',
            'UsD',
            Money::fromString('BTC', '1', 8),
            Money::fromString('USD', '30000', 2),
        );

        $this->assertSame('BTC', $leg->from());
        $this->assertSame('USD', $leg->to());
    }

    public function test_to_array_returns_correct_structure(): void
    {
        $spent = Money::fromString('USD', '100.50', 2);
        $received = Money::fromString('EUR', '85.25', 2);
        $fees = MoneyMap::fromList([Money::fromString('USD', '2.50', 2)], true);

        $leg = new PathLeg('usd', 'eur', $spent, $received, $fees);

        $array = $leg->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('from', $array);
        $this->assertArrayHasKey('to', $array);
        $this->assertArrayHasKey('spent', $array);
        $this->assertArrayHasKey('received', $array);
        $this->assertArrayHasKey('fees', $array);

        $this->assertSame('USD', $array['from']);
        $this->assertSame('EUR', $array['to']);
        $this->assertSame($spent, $array['spent']);
        $this->assertSame($received, $array['received']);
        $this->assertSame($fees, $array['fees']);
    }

    public function test_fees_as_array_returns_correct_structure(): void
    {
        $fees = MoneyMap::fromList([
            Money::fromString('USD', '2.50', 2),
            Money::fromString('EUR', '1.75', 2),
        ], true);

        $leg = new PathLeg(
            'btc',
            'eth',
            Money::fromString('BTC', '1', 8),
            Money::fromString('ETH', '30', 8),
            $fees
        );

        $feesArray = $leg->feesAsArray();

        $this->assertIsArray($feesArray);
        $this->assertCount(2, $feesArray);
        $this->assertArrayHasKey('USD', $feesArray);
        $this->assertArrayHasKey('EUR', $feesArray);

        $this->assertSame('USD', $feesArray['USD']->currency());
        $this->assertSame('2.50', $feesArray['USD']->amount());
        $this->assertSame(2, $feesArray['USD']->scale());

        $this->assertSame('EUR', $feesArray['EUR']->currency());
        $this->assertSame('1.75', $feesArray['EUR']->amount());
        $this->assertSame(2, $feesArray['EUR']->scale());
    }

    public function test_fees_as_array_with_empty_fees(): void
    {
        $leg = new PathLeg(
            'usd',
            'eur',
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '85', 2),
        );

        $feesArray = $leg->feesAsArray();

        $this->assertIsArray($feesArray);
        $this->assertEmpty($feesArray);
    }

    public function test_same_from_and_to_assets_are_allowed(): void
    {
        $leg = new PathLeg(
            'usd',
            'usd',
            Money::fromString('USD', '100', 2),
            Money::fromString('USD', '100', 2),
        );

        $this->assertSame('USD', $leg->from());
        $this->assertSame('USD', $leg->to());
    }

    public function test_money_objects_with_different_scales_are_handled_correctly(): void
    {
        $spent = Money::fromString('BTC', '0.12345678', 8);
        $received = Money::fromString('ETH', '3.14159', 5);

        $leg = new PathLeg('btc', 'eth', $spent, $received);

        $this->assertSame(8, $leg->spent()->scale());
        $this->assertSame(5, $leg->received()->scale());
    }

    public function test_large_money_amounts_are_handled_correctly(): void
    {
        $spent = Money::fromString('USD', '999999999999.99', 2);
        $received = Money::fromString('EUR', '888888888888.88', 2);

        $leg = new PathLeg('usd', 'eur', $spent, $received);

        $this->assertSame('999999999999.99', $leg->spent()->amount());
        $this->assertSame('888888888888.88', $leg->received()->amount());
    }

    public function test_very_small_money_amounts_are_handled_correctly(): void
    {
        $spent = Money::fromString('BTC', '0.00000001', 8);
        $received = Money::fromString('ETH', '0.000001', 6);

        $leg = new PathLeg('btc', 'eth', $spent, $received);

        $this->assertSame('0.00000001', $leg->spent()->amount());
        $this->assertSame('0.000001', $leg->received()->amount());
    }

    public function test_fees_with_multiple_currencies_are_handled_correctly(): void
    {
        $fees = MoneyMap::fromList([
            Money::fromString('USD', '10.00', 2),
            Money::fromString('EUR', '8.50', 2),
            Money::fromString('BTC', '0.001', 8),
        ], true);

        $leg = new PathLeg(
            'btc',
            'usd',
            Money::fromString('BTC', '1', 8),
            Money::fromString('USD', '30000', 2),
            $fees
        );

        $feesArray = $leg->feesAsArray();
        $this->assertCount(3, $feesArray);
        $this->assertArrayHasKey('USD', $feesArray);
        $this->assertArrayHasKey('EUR', $feesArray);
        $this->assertArrayHasKey('BTC', $feesArray);
    }

    public function test_non_money_fee_entries_throw_exception(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money map entries must be instances of Money.');

        MoneyMap::fromList(['not-a-money']);
    }

    public function test_spent_currency_must_match_source_asset(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path leg spent currency must match the from asset.');

        new PathLeg(
            'usd',
            'eur',
            Money::fromString('EUR', '1', 2),
            Money::fromString('EUR', '1', 2),
        );
    }

    public function test_received_currency_must_match_destination_asset(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path leg received currency must match the to asset.');

        new PathLeg(
            'usd',
            'eur',
            Money::fromString('USD', '1', 2),
            Money::fromString('USD', '1', 2),
        );
    }

    public function test_basic_construction_with_valid_parameters(): void
    {
        $spent = Money::fromString('USD', '100.50', 2);
        $received = Money::fromString('EUR', '85.25', 2);
        $fees = MoneyMap::fromList([Money::fromString('USD', '2.50', 2)], true);

        $leg = new PathLeg('usd', 'eur', $spent, $received, $fees);

        $this->assertSame('USD', $leg->from());
        $this->assertSame('EUR', $leg->to());
        $this->assertSame($spent, $leg->spent());
        $this->assertSame($received, $leg->received());
        $this->assertSame($fees, $leg->fees());
    }

    public function test_construction_without_fees_defaults_to_empty_money_map(): void
    {
        $spent = Money::fromString('USD', '100', 2);
        $received = Money::fromString('EUR', '85', 2);

        $leg = new PathLeg('usd', 'eur', $spent, $received);

        $this->assertInstanceOf(MoneyMap::class, $leg->fees());
        $this->assertTrue($leg->fees()->isEmpty());
    }

    public function test_construction_with_null_fees_defaults_to_empty_money_map(): void
    {
        $spent = Money::fromString('USD', '100', 2);
        $received = Money::fromString('EUR', '85', 2);

        $leg = new PathLeg('usd', 'eur', $spent, $received, null);

        $this->assertInstanceOf(MoneyMap::class, $leg->fees());
        $this->assertTrue($leg->fees()->isEmpty());
    }

    public function test_getter_methods_return_correct_values(): void
    {
        $spent = Money::fromString('BTC', '0.5', 8);
        $received = Money::fromString('ETH', '15.75', 8);
        $fees = MoneyMap::fromList([
            Money::fromString('USD', '5.00', 2),
            Money::fromString('EUR', '4.25', 2),
        ], true);

        $leg = new PathLeg('btc', 'eth', $spent, $received, $fees);

        $this->assertSame('BTC', $leg->from());
        $this->assertSame('ETH', $leg->to());
        $this->assertSame($spent, $leg->spent());
        $this->assertSame($received, $leg->received());
        $this->assertSame($fees, $leg->fees());
    }
}
