<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\Order;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

final class OrderTest extends TestCase
{
    public function test_validate_partial_fill_rejects_amounts_below_minimum(): void
    {
        $order = $this->createBuyOrder();

        $this->expectException(InvalidArgumentException::class);

        $order->validatePartialFill(Money::fromString('BTC', '0.050', 3));
    }

    public function test_validate_partial_fill_rejects_amounts_above_maximum(): void
    {
        $order = $this->createBuyOrder();

        $this->expectException(InvalidArgumentException::class);

        $order->validatePartialFill(Money::fromString('BTC', '1.500', 3));
    }

    public function test_calculate_effective_quote_amount_without_fee(): void
    {
        $order = $this->createBuyOrder();

        $quote = $order->calculateEffectiveQuoteAmount(Money::fromString('BTC', '0.500', 3));

        self::assertTrue($quote->equals(Money::fromString('USD', '15000.000', 3)));
    }

    public function test_calculate_effective_quote_amount_adds_fee_for_buy_order(): void
    {
        $order = $this->createBuyOrder($this->percentageFeePolicy('0.10'));

        $quote = $order->calculateEffectiveQuoteAmount(Money::fromString('BTC', '0.500', 3));

        self::assertTrue($quote->equals(Money::fromString('USD', '16500.000', 3)));
    }

    public function test_calculate_effective_quote_amount_subtracts_fee_for_sell_order(): void
    {
        $order = $this->createSellOrder($this->percentageFeePolicy('0.10'));

        $quote = $order->calculateEffectiveQuoteAmount(Money::fromString('BTC', '0.500', 3));

        self::assertTrue($quote->equals(Money::fromString('USD', '13500.000', 3)));
    }

    private function createBuyOrder(?FeePolicy $feePolicy = null): Order
    {
        $assetPair = AssetPair::fromString('BTC', 'USD');
        $bounds = OrderBounds::from(
            Money::fromString('BTC', '0.100', 3),
            Money::fromString('BTC', '1.000', 3),
        );
        $rate = ExchangeRate::fromString('BTC', 'USD', '30000', 2);

        return new Order(OrderSide::BUY, $assetPair, $bounds, $rate, $feePolicy);
    }

    private function createSellOrder(?FeePolicy $feePolicy = null): Order
    {
        $assetPair = AssetPair::fromString('BTC', 'USD');
        $bounds = OrderBounds::from(
            Money::fromString('BTC', '0.100', 3),
            Money::fromString('BTC', '1.000', 3),
        );
        $rate = ExchangeRate::fromString('BTC', 'USD', '30000', 2);

        return new Order(OrderSide::SELL, $assetPair, $bounds, $rate, $feePolicy);
    }

    private function percentageFeePolicy(string $percentage): FeePolicy
    {
        return new class($percentage) implements FeePolicy {
            public function __construct(private readonly string $percentage)
            {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): Money
            {
                return $quoteAmount->multiply($this->percentage, $quoteAmount->scale());
            }
        };
    }
}
