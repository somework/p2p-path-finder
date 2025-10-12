<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Fixture;

use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

final class OrderFactory
{
    /**
     * @param non-empty-string $base
     * @param non-empty-string $quote
     * @param numeric-string   $minAmount
     * @param numeric-string   $maxAmount
     * @param numeric-string   $rate
     */
    public static function buy(
        string $base = 'BTC',
        string $quote = 'USD',
        string $minAmount = '0.100',
        string $maxAmount = '1.000',
        string $rate = '30000',
        int $amountScale = 3,
        int $rateScale = 2,
        ?FeePolicy $feePolicy = null,
    ): Order {
        return self::createOrder(OrderSide::BUY, $base, $quote, $minAmount, $maxAmount, $rate, $amountScale, $rateScale, $feePolicy);
    }

    /**
     * @param non-empty-string $base
     * @param non-empty-string $quote
     * @param numeric-string   $minAmount
     * @param numeric-string   $maxAmount
     * @param numeric-string   $rate
     */
    public static function sell(
        string $base = 'BTC',
        string $quote = 'USD',
        string $minAmount = '0.100',
        string $maxAmount = '1.000',
        string $rate = '30000',
        int $amountScale = 3,
        int $rateScale = 2,
        ?FeePolicy $feePolicy = null,
    ): Order {
        return self::createOrder(OrderSide::SELL, $base, $quote, $minAmount, $maxAmount, $rate, $amountScale, $rateScale, $feePolicy);
    }

    /**
     * @param non-empty-string $base
     * @param non-empty-string $quote
     * @param numeric-string   $minAmount
     * @param numeric-string   $maxAmount
     * @param numeric-string   $rate
     */
    public static function createOrder(
        OrderSide $side,
        string $base,
        string $quote,
        string $minAmount,
        string $maxAmount,
        string $rate,
        int $amountScale = 3,
        int $rateScale = 2,
        ?FeePolicy $feePolicy = null,
    ): Order {
        $assetPair = CurrencyScenarioFactory::assetPair($base, $quote);
        $bounds = OrderBounds::from(
            CurrencyScenarioFactory::money($base, $minAmount, $amountScale),
            CurrencyScenarioFactory::money($base, $maxAmount, $amountScale),
        );
        $exchangeRate = ExchangeRate::fromString($base, $quote, $rate, $rateScale);

        return new Order($side, $assetPair, $bounds, $exchangeRate, $feePolicy);
    }

    /**
     * @param non-empty-string $currency
     * @param numeric-string   $amount
     */
    public static function partialFill(string $currency, string $amount, int $scale = 3): Money
    {
        return CurrencyScenarioFactory::money($currency, $amount, $scale);
    }
}
