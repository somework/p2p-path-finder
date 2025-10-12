<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\Order;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class OrderTest extends TestCase
{
    /**
     * @param non-empty-string $baseCurrency
     * @param non-empty-string $partialFillCurrency
     * @param numeric-string   $amount
     *
     * @dataProvider provideInvalidPartialFillAmounts
     */
    public function test_validate_partial_fill_rejects_out_of_bounds_amounts(
        string $baseCurrency,
        string $partialFillCurrency,
        string $amount,
    ): void {
        $order = OrderFactory::buy(base: $baseCurrency);

        $this->expectException(InvalidArgumentException::class);

        $order->validatePartialFill(OrderFactory::partialFill($partialFillCurrency, $amount));
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string, numeric-string}>
     */
    public static function provideInvalidPartialFillAmounts(): iterable
    {
        yield 'below minimum amount' => ['BTC', 'BTC', '0.050'];
        yield 'above maximum amount' => ['BTC', 'BTC', '1.500'];
        yield 'currency mismatch' => ['BTC', 'ETH', '0.500'];
    }

    /**
     * @param numeric-string $amount
     *
     * @dataProvider provideValidPartialFillAmounts
     */
    public function test_validate_partial_fill_accepts_edge_amounts(string $amount): void
    {
        $order = OrderFactory::buy();

        $order->validatePartialFill(OrderFactory::partialFill('BTC', $amount));

        self::assertTrue(true, 'No exception should be thrown for valid partial fills.');
    }

    /**
     * @return iterable<string, array{numeric-string}>
     */
    public static function provideValidPartialFillAmounts(): iterable
    {
        yield 'minimum boundary' => ['0.100'];
        yield 'maximum boundary' => ['1.000'];
        yield 'mid range amount' => ['0.550'];
    }

    public function test_calculate_effective_quote_amount_without_fee(): void
    {
        $order = OrderFactory::buy();

        $quote = $order->calculateEffectiveQuoteAmount(CurrencyScenarioFactory::money('BTC', '0.500', 3));

        self::assertTrue($quote->equals(CurrencyScenarioFactory::money('USD', '15000.000', 3)));
    }

    public function test_calculate_effective_quote_amount_adds_fee_for_buy_order(): void
    {
        $order = OrderFactory::buy(feePolicy: $this->percentageFeePolicy('0.10'));

        $quote = $order->calculateEffectiveQuoteAmount(CurrencyScenarioFactory::money('BTC', '0.500', 3));

        self::assertTrue($quote->equals(CurrencyScenarioFactory::money('USD', '16500.000', 3)));
    }

    public function test_calculate_effective_quote_amount_subtracts_fee_for_sell_order(): void
    {
        $order = OrderFactory::sell(feePolicy: $this->percentageFeePolicy('0.10'));

        $quote = $order->calculateEffectiveQuoteAmount(CurrencyScenarioFactory::money('BTC', '0.500', 3));

        self::assertTrue($quote->equals(CurrencyScenarioFactory::money('USD', '13500.000', 3)));
    }

    public function test_calculate_effective_quote_amount_rejects_fee_currency_mismatch(): void
    {
        $order = OrderFactory::buy(feePolicy: new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): Money
            {
                return CurrencyScenarioFactory::money('BTC', '1.000', 3);
            }
        });

        $this->expectException(InvalidArgumentException::class);

        $order->calculateEffectiveQuoteAmount(CurrencyScenarioFactory::money('BTC', '0.500', 3));
    }

    public function test_calculate_quote_amount_honors_highest_scale_between_rate_and_amount(): void
    {
        $order = OrderFactory::createOrder(
            OrderSide::BUY,
            'BTC',
            'USD',
            '0.100',
            '2.000',
            '123.456789',
            amountScale: 3,
            rateScale: 6,
        );

        $baseAmount = CurrencyScenarioFactory::money('BTC', '0.55', 2);
        $quote = $order->calculateQuoteAmount($baseAmount);

        self::assertSame(6, $quote->scale());
        self::assertTrue($quote->equals(CurrencyScenarioFactory::money('USD', '67.901234', 6)));
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
