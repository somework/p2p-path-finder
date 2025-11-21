<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\Order;

use LogicException;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class OrderTest extends TestCase
{
    public function test_constructor_rejects_order_bounds_currency_mismatch(): void
    {
        $assetPair = AssetPair::fromString('BTC', 'USD');
        $bounds = OrderBounds::from(
            Money::fromString('ETH', '0.100', 3),
            Money::fromString('ETH', '1.000', 3),
        );
        $rate = ExchangeRate::fromString('BTC', 'USD', '30000', 3);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Order bounds must be expressed in the base asset.');

        new Order(OrderSide::BUY, $assetPair, $bounds, $rate);
    }

    public function test_constructor_rejects_effective_rate_base_currency_mismatch(): void
    {
        $assetPair = AssetPair::fromString('BTC', 'USD');
        $bounds = OrderBounds::from(
            Money::fromString('BTC', '0.100', 3),
            Money::fromString('BTC', '1.000', 3),
        );
        $rate = ExchangeRate::fromString('ETH', 'USD', '30000', 3);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Effective rate base currency must match asset pair base.');

        new Order(OrderSide::BUY, $assetPair, $bounds, $rate);
    }

    public function test_constructor_rejects_effective_rate_quote_currency_mismatch(): void
    {
        $assetPair = AssetPair::fromString('BTC', 'USD');
        $bounds = OrderBounds::from(
            Money::fromString('BTC', '0.100', 3),
            Money::fromString('BTC', '1.000', 3),
        );
        $rate = ExchangeRate::fromString('BTC', 'EUR', '30000', 3);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Effective rate quote currency must match asset pair quote.');

        new Order(OrderSide::BUY, $assetPair, $bounds, $rate);
    }

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

        $this->expectException(InvalidInput::class);

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

    public function test_calculate_effective_quote_amount_subtracts_fee_for_buy_order(): void
    {
        $order = OrderFactory::buy(feePolicy: $this->percentageFeePolicy('0.10'));

        $quote = $order->calculateEffectiveQuoteAmount(CurrencyScenarioFactory::money('BTC', '0.500', 3));

        self::assertTrue($quote->equals(CurrencyScenarioFactory::money('USD', '13500.000', 3)));
    }

    public function test_calculate_gross_base_spend_adds_fee_for_buy_order(): void
    {
        $order = OrderFactory::buy(feePolicy: $this->baseSurchargePolicy('0.005'));

        $baseAmount = CurrencyScenarioFactory::money('BTC', '0.500', 3);
        $grossBase = $order->calculateGrossBaseSpend($baseAmount);

        self::assertTrue($grossBase->equals(CurrencyScenarioFactory::money('BTC', '0.505', 3)));
    }

    public function test_calculate_effective_quote_amount_is_unaffected_by_base_fee(): void
    {
        $order = OrderFactory::buy(feePolicy: $this->baseSurchargePolicy('0.005'));

        $baseAmount = CurrencyScenarioFactory::money('BTC', '0.500', 3);
        $quote = $order->calculateEffectiveQuoteAmount($baseAmount);

        self::assertTrue($quote->equals(CurrencyScenarioFactory::money('USD', '15000.000', 3)));
    }

    public function test_calculate_effective_quote_amount_with_fee_lowers_buy_totals(): void
    {
        $order = OrderFactory::buy(feePolicy: $this->percentageFeePolicy('0.10'));

        $baseAmount = CurrencyScenarioFactory::money('BTC', '0.500', 3);
        $rawQuote = $order->calculateQuoteAmount($baseAmount);
        $effectiveQuote = $order->calculateEffectiveQuoteAmount($baseAmount);

        self::assertTrue($effectiveQuote->lessThan($rawQuote));
    }

    public function test_calculate_effective_quote_amount_rejects_mismatched_quote_fee_currency(): void
    {
        $policy = new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                return FeeBreakdown::forQuote(Money::fromString('EUR', '5.000', 3));
            }

            public function fingerprint(): string
            {
                return 'quote-mismatch:EUR:5.000@3';
            }
        };

        $order = OrderFactory::buy(feePolicy: $policy);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Fee policy must return money in quote asset currency.');

        $order->calculateEffectiveQuoteAmount(CurrencyScenarioFactory::money('BTC', '0.500', 3));
    }

    public function test_calculate_effective_quote_amount_rejects_mismatched_base_fee_currency(): void
    {
        $policy = new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                return FeeBreakdown::of(Money::fromString('ETH', '0.010', 3), null);
            }

            public function fingerprint(): string
            {
                return 'base-mismatch:ETH:0.010@3';
            }
        };

        $order = OrderFactory::buy(feePolicy: $policy);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Fee policy must return money in base asset currency.');

        $order->calculateEffectiveQuoteAmount(CurrencyScenarioFactory::money('BTC', '0.500', 3));
    }

    public function test_calculate_effective_quote_amount_with_dual_fee_components(): void
    {
        $policy = new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                return FeeBreakdown::of(
                    Money::fromString($baseAmount->currency(), '0.010', $baseAmount->scale()),
                    Money::fromString($quoteAmount->currency(), '5.000', $quoteAmount->scale()),
                );
            }

            public function fingerprint(): string
            {
                return 'dual-component:0.010:5.000';
            }
        };

        $order = OrderFactory::buy(feePolicy: $policy);
        $baseAmount = CurrencyScenarioFactory::money('BTC', '0.500', 3);

        $quote = $order->calculateEffectiveQuoteAmount($baseAmount);

        self::assertTrue($quote->equals(CurrencyScenarioFactory::money('USD', '14995.000', 3)));
    }

    public function test_calculate_gross_base_spend_uses_precomputed_fee_breakdown(): void
    {
        $order = OrderFactory::buy(feePolicy: $this->failOnCalculatePolicy());

        $baseAmount = CurrencyScenarioFactory::money('BTC', '0.500', 3);
        $precomputedFee = CurrencyScenarioFactory::money('BTC', '0.010', 3);

        $grossBase = $order->calculateGrossBaseSpend($baseAmount, FeeBreakdown::forBase($precomputedFee));

        self::assertTrue($grossBase->equals(CurrencyScenarioFactory::money('BTC', '0.510', 3)));
    }

    public function test_calculate_gross_base_spend_with_precomputed_quote_fee_leaves_base_unchanged(): void
    {
        $order = OrderFactory::buy(feePolicy: $this->failOnCalculatePolicy());

        $baseAmount = CurrencyScenarioFactory::money('BTC', '0.500', 3);
        $quoteFee = CurrencyScenarioFactory::money('USD', '1.000', 3);

        $grossBase = $order->calculateGrossBaseSpend($baseAmount, FeeBreakdown::forQuote($quoteFee));

        self::assertTrue($grossBase->equals($baseAmount));
    }

    public function test_calculate_gross_base_spend_rejects_mismatched_base_fee_currency(): void
    {
        $policy = new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                return FeeBreakdown::forBase(Money::fromString('ETH', '0.010', 3));
            }

            public function fingerprint(): string
            {
                return 'gross-base-mismatch:ETH:0.010@3';
            }
        };

        $order = OrderFactory::buy(feePolicy: $policy);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Fee policy must return money in base asset currency.');

        $order->calculateGrossBaseSpend(CurrencyScenarioFactory::money('BTC', '0.500', 3));
    }

    public function test_calculate_gross_base_spend_without_fee_policy_returns_net_amount(): void
    {
        $order = OrderFactory::buy();
        $baseAmount = CurrencyScenarioFactory::money('BTC', '0.750', 3);

        $grossBase = $order->calculateGrossBaseSpend($baseAmount);

        self::assertTrue($grossBase->equals($baseAmount));
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
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                return FeeBreakdown::forQuote(CurrencyScenarioFactory::money('BTC', '1.000', 3));
            }

            public function fingerprint(): string
            {
                return 'quote-mismatch:BTC:1.000@3';
            }
        });

        $this->expectException(InvalidInput::class);

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

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $fee = $quoteAmount->multiply($this->percentage, $quoteAmount->scale());

                return FeeBreakdown::forQuote($fee);
            }

            public function fingerprint(): string
            {
                return 'percentage-quote:'.$this->percentage;
            }
        };
    }

    private function baseSurchargePolicy(string $flatFee): FeePolicy
    {
        return new class($flatFee) implements FeePolicy {
            public function __construct(private readonly string $flatFee)
            {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $fee = Money::fromString($baseAmount->currency(), $this->flatFee, $baseAmount->scale());

                return FeeBreakdown::forBase($fee);
            }

            public function fingerprint(): string
            {
                return 'base-flat:'.$this->flatFee;
            }
        };
    }

    private function failOnCalculatePolicy(): FeePolicy
    {
        return new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                throw new LogicException('Precomputed fees should prevent FeePolicy::calculate invocation.');
            }

            public function fingerprint(): string
            {
                return 'fail-on-calculate';
            }
        };
    }

    public function test_order_side_buy_is_available(): void
    {
        $order = OrderFactory::buy();

        self::assertSame(OrderSide::BUY, $order->side());
    }

    public function test_order_side_sell_is_available(): void
    {
        $order = OrderFactory::sell();

        self::assertSame(OrderSide::SELL, $order->side());
    }

    public function test_order_exposes_asset_pair(): void
    {
        $assetPair = AssetPair::fromString('BTC', 'USD');
        $bounds = OrderBounds::from(
            Money::fromString('BTC', '0.100', 3),
            Money::fromString('BTC', '1.000', 3),
        );
        $rate = ExchangeRate::fromString('BTC', 'USD', '30000', 3);

        $order = new Order(OrderSide::BUY, $assetPair, $bounds, $rate);

        self::assertSame('BTC', $order->assetPair()->base());
        self::assertSame('USD', $order->assetPair()->quote());
    }

    public function test_order_exposes_bounds(): void
    {
        $order = OrderFactory::buy();

        $bounds = $order->bounds();

        self::assertTrue($bounds->min()->equals(CurrencyScenarioFactory::money('BTC', '0.100', 3)));
        self::assertTrue($bounds->max()->equals(CurrencyScenarioFactory::money('BTC', '1.000', 3)));
    }

    public function test_order_exposes_effective_rate(): void
    {
        $order = OrderFactory::buy();

        $rate = $order->effectiveRate();

        self::assertSame('BTC', $rate->baseCurrency());
        self::assertSame('USD', $rate->quoteCurrency());
        self::assertSame('30000.00', $rate->rate());
    }

    public function test_calculate_quote_amount_with_minimum_boundary(): void
    {
        $order = OrderFactory::buy();

        $quote = $order->calculateQuoteAmount(CurrencyScenarioFactory::money('BTC', '0.100', 3));

        self::assertTrue($quote->equals(CurrencyScenarioFactory::money('USD', '3000.000', 3)));
    }

    public function test_calculate_quote_amount_with_maximum_boundary(): void
    {
        $order = OrderFactory::buy();

        $quote = $order->calculateQuoteAmount(CurrencyScenarioFactory::money('BTC', '1.000', 3));

        self::assertTrue($quote->equals(CurrencyScenarioFactory::money('USD', '30000.000', 3)));
    }

    public function test_order_immutability(): void
    {
        $order = OrderFactory::buy();

        // Accessing methods multiple times should return consistent values
        $side1 = $order->side();
        $side2 = $order->side();
        self::assertSame($side1, $side2);

        $pair1 = $order->assetPair();
        $pair2 = $order->assetPair();
        self::assertSame($pair1, $pair2);

        $bounds1 = $order->bounds();
        $bounds2 = $order->bounds();
        self::assertSame($bounds1, $bounds2);
    }

    public function test_calculate_quote_amount_preserves_precision(): void
    {
        $order = OrderFactory::createOrder(
            OrderSide::BUY,
            'BTC',
            'USD',
            '0.001',
            '10.000',
            '45678.123456789',
            amountScale: 3,
            rateScale: 9,
        );

        $baseAmount = CurrencyScenarioFactory::money('BTC', '0.123', 3);
        $quote = $order->calculateQuoteAmount($baseAmount);

        // Should use the higher scale (9) from rate
        self::assertSame(9, $quote->scale());
        self::assertTrue($quote->equals(CurrencyScenarioFactory::money('USD', '5618.409185185', 9)));
    }

    public function test_order_with_equal_min_max_bounds(): void
    {
        $amount = Money::fromString('BTC', '0.5', 1);
        $bounds = OrderBounds::from($amount, $amount);
        $rate = ExchangeRate::fromString('BTC', 'USD', '30000', 2);

        $order = new Order(OrderSide::BUY, AssetPair::fromString('BTC', 'USD'), $bounds, $rate);

        // Should accept exactly the boundary value
        $order->validatePartialFill(Money::fromString('BTC', '0.5', 1));

        self::assertTrue(true, 'No exception should be thrown for exact boundary match.');
    }

    public function test_sell_order_calculates_effective_quote_correctly(): void
    {
        $order = OrderFactory::sell();

        $quote = $order->calculateEffectiveQuoteAmount(CurrencyScenarioFactory::money('BTC', '0.500', 3));

        self::assertTrue($quote->equals(CurrencyScenarioFactory::money('USD', '15000.000', 3)));
    }

    public function test_order_with_very_high_rate(): void
    {
        $order = OrderFactory::createOrder(
            OrderSide::BUY,
            'SATS',
            'BTC',
            '1',
            '100000',
            '0.00000001',
            amountScale: 0,
            rateScale: 8,
        );

        $quote = $order->calculateQuoteAmount(CurrencyScenarioFactory::money('SATS', '50000', 0));

        self::assertTrue($quote->equals(CurrencyScenarioFactory::money('BTC', '0.00050000', 8)));
    }

    public function test_order_with_very_low_rate(): void
    {
        $order = OrderFactory::createOrder(
            OrderSide::BUY,
            'BTC',
            'SATS',
            '0.0001',
            '1.0',
            '100000000',
            amountScale: 4,
            rateScale: 0,
        );

        $quote = $order->calculateQuoteAmount(CurrencyScenarioFactory::money('BTC', '0.5000', 4));

        self::assertTrue($quote->equals(CurrencyScenarioFactory::money('SATS', '50000000', 4)));
    }
}
