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

    // ==================== Order Consistency Validation Tests (0002.11) ====================

    public function test_order_with_bounds_currency_mismatch_throws_exception(): void
    {
        // Bounds must be in base currency
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Order bounds must be expressed in the base asset.');

        $assetPair = AssetPair::fromString('BTC', 'USD');
        $bounds = OrderBounds::from(
            Money::fromString('EUR', '0.100', 3),
            Money::fromString('EUR', '1.000', 3),
        );
        $rate = ExchangeRate::fromString('BTC', 'USD', '30000', 2);

        new Order(OrderSide::BUY, $assetPair, $bounds, $rate);
    }

    public function test_order_with_bounds_currency_matching_quote_throws_exception(): void
    {
        // Bounds in quote currency should be rejected
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Order bounds must be expressed in the base asset.');

        $assetPair = AssetPair::fromString('ETH', 'USD');
        $bounds = OrderBounds::from(
            Money::fromString('USD', '1000.00', 2),
            Money::fromString('USD', '5000.00', 2),
        );
        $rate = ExchangeRate::fromString('ETH', 'USD', '2000.00', 2);

        new Order(OrderSide::SELL, $assetPair, $bounds, $rate);
    }

    public function test_order_with_rate_base_currency_mismatch_throws_exception(): void
    {
        // Rate base must match asset pair base
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Effective rate base currency must match asset pair base.');

        $assetPair = AssetPair::fromString('BTC', 'USD');
        $bounds = OrderBounds::from(
            Money::fromString('BTC', '0.100', 3),
            Money::fromString('BTC', '1.000', 3),
        );
        $rate = ExchangeRate::fromString('ETH', 'USD', '30000', 2);

        new Order(OrderSide::BUY, $assetPair, $bounds, $rate);
    }

    public function test_order_with_rate_quote_currency_mismatch_throws_exception(): void
    {
        // Rate quote must match asset pair quote
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Effective rate quote currency must match asset pair quote.');

        $assetPair = AssetPair::fromString('BTC', 'USD');
        $bounds = OrderBounds::from(
            Money::fromString('BTC', '0.100', 3),
            Money::fromString('BTC', '1.000', 3),
        );
        $rate = ExchangeRate::fromString('BTC', 'EUR', '25000', 2);

        new Order(OrderSide::BUY, $assetPair, $bounds, $rate);
    }

    public function test_order_with_fee_currency_mismatch(): void
    {
        // Fee in wrong currency should be rejected during calculation
        $policy = new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                // Return quote fee in wrong currency
                return FeeBreakdown::forQuote(Money::fromString('GBP', '10.00', 2));
            }

            public function fingerprint(): string
            {
                return 'wrong-currency-fee';
            }
        };

        $order = OrderFactory::buy(feePolicy: $policy);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Fee policy must return money in quote asset currency.');

        $order->calculateEffectiveQuoteAmount(CurrencyScenarioFactory::money('BTC', '0.500', 3));
    }

    public function test_order_validation_passes_with_consistent_currencies(): void
    {
        // All currencies properly aligned should work
        $assetPair = AssetPair::fromString('ETH', 'USDT');
        $bounds = OrderBounds::from(
            Money::fromString('ETH', '0.1', 1),
            Money::fromString('ETH', '10.0', 1),
        );
        $rate = ExchangeRate::fromString('ETH', 'USDT', '2000.00', 2);

        $order = new Order(OrderSide::BUY, $assetPair, $bounds, $rate);

        // Verify construction succeeded
        self::assertSame('ETH', $order->assetPair()->base());
        self::assertSame('USDT', $order->assetPair()->quote());
        self::assertSame('ETH', $order->bounds()->min()->currency());
        self::assertSame('ETH', $order->effectiveRate()->baseCurrency());
        self::assertSame('USDT', $order->effectiveRate()->quoteCurrency());
    }

    // ==================== Fee Policy Edge Case Tests (0002.12) ====================

    public function test_fee_exceeds_amount_allowed_by_implementation(): void
    {
        // Fee larger than quote amount - implementation allows but caller must validate
        // This documents that the Order class doesn't prevent excessive fees
        $policy = new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                // Fee is 50% of quote amount
                $fee = $quoteAmount->multiply('0.5', $quoteAmount->scale());

                return FeeBreakdown::forQuote($fee);
            }

            public function fingerprint(): string
            {
                return 'large-fee:50%';
            }
        };

        $order = OrderFactory::buy(feePolicy: $policy);
        $baseAmount = CurrencyScenarioFactory::money('BTC', '0.500', 3);

        $effectiveQuote = $order->calculateEffectiveQuoteAmount($baseAmount);

        // Quote = 15000, Fee = 7500, Effective = 7500
        self::assertTrue($effectiveQuote->equals(CurrencyScenarioFactory::money('USD', '7500.000', 3)));

        // Verify fee reduces effective quote significantly
        $rawQuote = $order->calculateQuoteAmount($baseAmount);
        self::assertTrue($effectiveQuote->lessThan($rawQuote));
    }

    public function test_fee_equals_amount_results_in_zero(): void
    {
        // Fee exactly equals quote amount (100% fee)
        $policy = new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                // Fee is exactly 100% of quote amount
                return FeeBreakdown::forQuote($quoteAmount);
            }

            public function fingerprint(): string
            {
                return 'full-fee:100%';
            }
        };

        $order = OrderFactory::buy(feePolicy: $policy);
        $baseAmount = CurrencyScenarioFactory::money('BTC', '0.500', 3);

        $effectiveQuote = $order->calculateEffectiveQuoteAmount($baseAmount);

        // Quote = 15000, Fee = 15000, Effective = 0
        self::assertTrue($effectiveQuote->isZero());
        self::assertSame('0.000', $effectiveQuote->amount());
    }

    public function test_zero_fee(): void
    {
        // Zero fee policy
        $policy = new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                return FeeBreakdown::forQuote(Money::zero($quoteAmount->currency(), $quoteAmount->scale()));
            }

            public function fingerprint(): string
            {
                return 'zero-fee';
            }
        };

        $order = OrderFactory::buy(feePolicy: $policy);
        $baseAmount = CurrencyScenarioFactory::money('BTC', '0.500', 3);

        $effectiveQuote = $order->calculateEffectiveQuoteAmount($baseAmount);
        $rawQuote = $order->calculateQuoteAmount($baseAmount);

        // With zero fee, effective should equal raw quote
        self::assertTrue($effectiveQuote->equals($rawQuote));
        self::assertTrue($effectiveQuote->equals(CurrencyScenarioFactory::money('USD', '15000.000', 3)));
    }

    public function test_null_fee_breakdown_behavior(): void
    {
        // Test with null fee breakdown (no fees)
        $policy = new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                // Return breakdown with null fees
                return FeeBreakdown::of(null, null);
            }

            public function fingerprint(): string
            {
                return 'null-fees';
            }
        };

        $order = OrderFactory::buy(feePolicy: $policy);
        $baseAmount = CurrencyScenarioFactory::money('BTC', '0.500', 3);

        $effectiveQuote = $order->calculateEffectiveQuoteAmount($baseAmount);
        $rawQuote = $order->calculateQuoteAmount($baseAmount);

        // Null fees should behave like zero fees
        self::assertTrue($effectiveQuote->equals($rawQuote));
    }

    public function test_fee_accumulation_across_orders(): void
    {
        // Test multiple orders with fees accumulate correctly
        $fee10Percent = $this->percentageFeePolicy('0.10');

        $order1 = OrderFactory::createOrder(
            OrderSide::BUY,
            'BTC',
            'USD',
            '0.1',
            '1.0',
            '30000',
            amountScale: 3,
            rateScale: 2,
            feePolicy: $fee10Percent,
        );

        $order2 = OrderFactory::createOrder(
            OrderSide::BUY,
            'ETH',
            'BTC',
            '0.1',
            '10.0',
            '0.05',
            amountScale: 3,
            rateScale: 2,
            feePolicy: $fee10Percent,
        );

        // Calculate effective quotes for both orders
        $base1 = CurrencyScenarioFactory::money('BTC', '0.500', 3);
        $effective1 = $order1->calculateEffectiveQuoteAmount($base1);
        // Raw: 15000, Fee: 1500, Effective: 13500

        $base2 = CurrencyScenarioFactory::money('ETH', '1.000', 3);
        $effective2 = $order2->calculateEffectiveQuoteAmount($base2);
        // Raw: 0.05, Fee: 0.005, Effective: 0.045

        // Verify fees were applied to both
        self::assertTrue($effective1->equals(CurrencyScenarioFactory::money('USD', '13500.000', 3)));
        self::assertTrue($effective2->equals(CurrencyScenarioFactory::money('BTC', '0.045', 3)));

        // Verify fees are cumulative effect
        $rawQuote1 = $order1->calculateQuoteAmount($base1);
        $rawQuote2 = $order2->calculateQuoteAmount($base2);

        self::assertTrue($effective1->lessThan($rawQuote1));
        self::assertTrue($effective2->lessThan($rawQuote2));
    }

    public function test_very_small_fee(): void
    {
        // Very small fee (1 satoshi equivalent)
        $policy = new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                return FeeBreakdown::forQuote(Money::fromString($quoteAmount->currency(), '0.001', 3));
            }

            public function fingerprint(): string
            {
                return 'tiny-fee:0.001';
            }
        };

        $order = OrderFactory::createOrder(
            OrderSide::BUY,
            'BTC',
            'USD',
            '0.1',
            '1.0',
            '30000.00',
            amountScale: 3,
            rateScale: 2,
            feePolicy: $policy,
        );

        $baseAmount = CurrencyScenarioFactory::money('BTC', '0.500', 3);
        $effectiveQuote = $order->calculateEffectiveQuoteAmount($baseAmount);

        // Quote = 15000.000, Fee = 0.001, Effective = 14999.999
        self::assertSame('14999.999', $effectiveQuote->amount());
    }

    public function test_fee_policy_with_both_base_and_quote_fees(): void
    {
        // Policy that charges both base and quote fees
        $policy = new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $baseFee = $baseAmount->multiply('0.01', $baseAmount->scale());
                $quoteFee = $quoteAmount->multiply('0.02', $quoteAmount->scale());

                return FeeBreakdown::of($baseFee, $quoteFee);
            }

            public function fingerprint(): string
            {
                return 'dual-fee:base-1%:quote-2%';
            }
        };

        $order = OrderFactory::buy(feePolicy: $policy);
        $baseAmount = CurrencyScenarioFactory::money('BTC', '1.000', 3);

        $effectiveQuote = $order->calculateEffectiveQuoteAmount($baseAmount);
        $grossBase = $order->calculateGrossBaseSpend($baseAmount);

        // Raw quote: 30000
        // Quote fee (2%): 600
        // Effective quote: 29400
        self::assertTrue($effectiveQuote->equals(CurrencyScenarioFactory::money('USD', '29400.000', 3)));

        // Base: 1.000
        // Base fee (1%): 0.010
        // Gross base: 1.010
        self::assertTrue($grossBase->equals(CurrencyScenarioFactory::money('BTC', '1.010', 3)));
    }

    public function test_fee_larger_than_base_amount(): void
    {
        // Base fee exceeds base amount
        $policy = new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                // Fee is 200% of base amount
                $fee = $baseAmount->multiply('2.0', $baseAmount->scale());

                return FeeBreakdown::forBase($fee);
            }

            public function fingerprint(): string
            {
                return 'excessive-base-fee:200%';
            }
        };

        $order = OrderFactory::buy(feePolicy: $policy);
        $baseAmount = CurrencyScenarioFactory::money('BTC', '0.100', 3);

        $grossBase = $order->calculateGrossBaseSpend($baseAmount);

        // Base: 0.100
        // Fee: 0.200
        // Gross: 0.300
        self::assertTrue($grossBase->equals(CurrencyScenarioFactory::money('BTC', '0.300', 3)));

        // Effective quote should be unaffected by base fee
        $effectiveQuote = $order->calculateEffectiveQuoteAmount($baseAmount);
        $rawQuote = $order->calculateQuoteAmount($baseAmount);
        self::assertTrue($effectiveQuote->equals($rawQuote));
    }
}
