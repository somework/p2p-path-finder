<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Support;

use LogicException;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class OrderFillEvaluatorTest extends TestCase
{
    /**
     * @testdox Buy orders without fees mirror the raw quote and base amounts.
     */
    public function test_it_returns_raw_amounts_for_fee_less_orders(): void
    {
        $order = OrderFactory::buy(
            base: 'BTC',
            quote: 'USD',
            minAmount: '0.100',
            maxAmount: '2.000',
            rate: '20000',
            amountScale: 3,
            rateScale: 2,
            feePolicy: null,
        );

        $baseAmount = OrderFactory::partialFill('BTC', '0.250', 3);

        $result = (new OrderFillEvaluator())->evaluate($order, $baseAmount);

        self::assertTrue($result['quote']->equals(Money::fromString('USD', '5000.000', 3)));
        self::assertTrue($result['grossBase']->equals(Money::fromString('BTC', '0.250', 3)));
        self::assertTrue($result['netBase']->equals(Money::fromString('BTC', '0.250', 3)));

        $fees = $result['fees'];
        self::assertInstanceOf(FeeBreakdown::class, $fees);
        self::assertFalse($fees->hasBaseFee());
        self::assertFalse($fees->hasQuoteFee());
        self::assertTrue($fees->isZero());
        self::assertNull($fees->baseFee());
        self::assertNull($fees->quoteFee());
    }

    /**
     * @testdox Buy legs with quote fees reduce the net quote while keeping base consumption unchanged.
     */
    public function test_it_deducts_quote_fees_for_buy_orders(): void
    {
        $feePolicy = new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                if (OrderSide::BUY !== $side) {
                    throw new LogicException('Buy fee policy invoked for a non-buy leg.');
                }

                $feeScale = max($quoteAmount->scale(), 6);
                $quoteFee = $quoteAmount->multiply('0.005', $feeScale)->withScale($quoteAmount->scale());

                return FeeBreakdown::forQuote($quoteFee);
            }

            public function fingerprint(): string
            {
                return 'buy-percentage-quote:0.005';
            }
        };

        $order = OrderFactory::buy(
            base: 'ETH',
            quote: 'USD',
            minAmount: '1.000',
            maxAmount: '5.000',
            rate: '1800',
            amountScale: 3,
            rateScale: 2,
            feePolicy: $feePolicy,
        );

        $baseAmount = OrderFactory::partialFill('ETH', '1.500', 3);

        $result = (new OrderFillEvaluator())->evaluate($order, $baseAmount);

        $expectedQuote = Money::fromString('USD', '2686.500', 3);
        self::assertTrue($result['quote']->equals($expectedQuote));
        self::assertTrue($result['grossBase']->equals(Money::fromString('ETH', '1.500', 3)));
        self::assertTrue($result['netBase']->equals(Money::fromString('ETH', '1.500', 3)));

        $fees = $result['fees'];
        self::assertInstanceOf(FeeBreakdown::class, $fees);
        self::assertFalse($fees->hasBaseFee());
        self::assertTrue($fees->hasQuoteFee());
        self::assertNull($fees->baseFee());

        $quoteFee = $fees->quoteFee();
        self::assertNotNull($quoteFee);
        self::assertTrue($quoteFee->equals(Money::fromString('USD', '13.500', 3)));
    }

    /**
     * @testdox Sell legs with base and quote fees inflate the gross spend while shrinking net base proceeds and boosting quote returns.
     */
    public function test_it_handles_sell_orders_with_base_and_quote_fees(): void
    {
        $feePolicy = new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                if (OrderSide::SELL !== $side) {
                    throw new LogicException('Sell fee policy invoked for a non-sell leg.');
                }

                $baseFeeScale = max($baseAmount->scale(), 6);
                $baseFee = $baseAmount->multiply('0.025', $baseFeeScale)->withScale($baseAmount->scale());

                $quoteFeeScale = max($quoteAmount->scale(), 6);
                $quoteFee = $quoteAmount->multiply('0.015', $quoteFeeScale)->withScale($quoteAmount->scale());

                return FeeBreakdown::of($baseFee, $quoteFee);
            }

            public function fingerprint(): string
            {
                return 'sell-percentage-mixed:0.025:0.015';
            }
        };

        $order = OrderFactory::sell(
            base: 'LTC',
            quote: 'USD',
            minAmount: '1.000',
            maxAmount: '5.000',
            rate: '150',
            amountScale: 3,
            rateScale: 2,
            feePolicy: $feePolicy,
        );

        $baseAmount = OrderFactory::partialFill('LTC', '2.000', 3);

        $result = (new OrderFillEvaluator())->evaluate($order, $baseAmount);

        self::assertTrue($result['quote']->equals(Money::fromString('USD', '304.500', 3)));
        self::assertTrue($result['grossBase']->equals(Money::fromString('LTC', '2.050', 3)));
        self::assertTrue($result['netBase']->equals(Money::fromString('LTC', '1.950', 3)));

        $fees = $result['fees'];
        self::assertInstanceOf(FeeBreakdown::class, $fees);
        self::assertTrue($fees->hasBaseFee());
        self::assertTrue($fees->hasQuoteFee());

        $baseFee = $fees->baseFee();
        self::assertNotNull($baseFee);
        self::assertTrue($baseFee->equals(Money::fromString('LTC', '0.050', 3)));

        $quoteFee = $fees->quoteFee();
        self::assertNotNull($quoteFee);
        self::assertTrue($quoteFee->equals(Money::fromString('USD', '4.500', 3)));
    }
}
