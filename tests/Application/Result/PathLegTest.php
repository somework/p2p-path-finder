<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Result;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Result\MoneyMap;
use SomeWork\P2PPathFinder\Application\Result\PathLeg;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class PathLegTest extends TestCase
{
    public function test_json_serialization(): void
    {
        $leg = new PathLeg(
            'usd',
            'eur',
            Money::fromString('USD', '50', 2),
            Money::fromString('EUR', '45', 2),
            MoneyMap::fromList([Money::fromString('USD', '0.50', 2)], true),
        );

        $this->assertSame(
            [
                'from' => 'USD',
                'to' => 'EUR',
                'spent' => ['currency' => 'USD', 'amount' => '50.00', 'scale' => 2],
                'received' => ['currency' => 'EUR', 'amount' => '45.00', 'scale' => 2],
                'fees' => [
                    'USD' => ['currency' => 'USD', 'amount' => '0.50', 'scale' => 2],
                ],
            ],
            $leg->jsonSerialize(),
        );
    }

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

        $fees = $leg->fees()->toArray();
        $this->assertSame(['USD'], array_keys($fees));
        $this->assertSame('1.50', $fees['USD']->amount());
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

        $fees = $leg->fees()->toArray();
        $this->assertSame(['EUR', 'USD'], array_keys($fees));
        $this->assertSame('0.25', $fees['EUR']->amount());
        $this->assertSame('0.75', $fees['USD']->amount());
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
}
