<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Filter;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Filter\ToleranceWindowFilter;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class ToleranceWindowFilterTest extends TestCase
{
    public function test_accepts_order_within_tolerance_window(): void
    {
        $filter = new ToleranceWindowFilter(
            ExchangeRate::fromString('AAA', 'BBB', '100.0000', 4),
            '0.05',
        );

        $order = $this->createOrder('AAA', 'BBB', '100.5000');

        self::assertTrue($filter->accepts($order));
    }

    public function test_rejects_order_outside_upper_tolerance(): void
    {
        $filter = new ToleranceWindowFilter(
            ExchangeRate::fromString('AAA', 'BBB', '50.0000', 4),
            '0.02',
        );

        $order = $this->createOrder('AAA', 'BBB', '52.0000');

        self::assertFalse($filter->accepts($order));
    }

    public function test_rejects_order_outside_lower_tolerance(): void
    {
        $filter = new ToleranceWindowFilter(
            ExchangeRate::fromString('AAA', 'BBB', '20.0000', 4),
            '0.05',
        );

        $order = $this->createOrder('AAA', 'BBB', '18.5000');

        self::assertFalse($filter->accepts($order));
    }

    public function test_rejects_order_with_mismatched_currency_pair(): void
    {
        $filter = new ToleranceWindowFilter(
            ExchangeRate::fromString('AAA', 'BBB', '10.0000', 4),
            '0.10',
        );

        $order = $this->createOrder('AAA', 'CCC', '10.5000');

        self::assertFalse($filter->accepts($order));
    }

    public function test_constructor_rejects_negative_tolerance(): void
    {
        $this->expectException(InvalidInput::class);

        new ToleranceWindowFilter(
            ExchangeRate::fromString('AAA', 'BBB', '10.0000', 4),
            '-0.01',
        );
    }

    private function createOrder(string $base, string $quote, string $rate): Order
    {
        return new Order(
            OrderSide::BUY,
            AssetPair::fromString($base, $quote),
            OrderBounds::from(
                Money::fromString($base, '1.0000', 4),
                Money::fromString($base, '5.0000', 4),
            ),
            ExchangeRate::fromString($base, $quote, $rate, 4),
        );
    }
}
