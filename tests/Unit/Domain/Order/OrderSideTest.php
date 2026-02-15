<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Domain\Order;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;

#[CoversClass(OrderSide::class)]
final class OrderSideTest extends TestCase
{
    #[TestDox('BUY case has value "buy"')]
    public function test_buy_case_value(): void
    {
        self::assertSame('buy', OrderSide::BUY->value);
    }

    #[TestDox('SELL case has value "sell"')]
    public function test_sell_case_value(): void
    {
        self::assertSame('sell', OrderSide::SELL->value);
    }

    #[TestDox('Enum has exactly two cases')]
    public function test_enum_has_two_cases(): void
    {
        self::assertCount(2, OrderSide::cases());
    }

    #[TestDox('Can be created from "buy" string')]
    public function test_from_buy_string(): void
    {
        $side = OrderSide::from('buy');

        self::assertSame(OrderSide::BUY, $side);
    }

    #[TestDox('Can be created from "sell" string')]
    public function test_from_sell_string(): void
    {
        $side = OrderSide::from('sell');

        self::assertSame(OrderSide::SELL, $side);
    }

    #[TestDox('tryFrom returns null for invalid value')]
    public function test_try_from_returns_null_for_invalid_value(): void
    {
        self::assertNull(OrderSide::tryFrom('invalid'));
    }
}
