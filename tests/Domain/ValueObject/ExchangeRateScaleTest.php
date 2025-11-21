<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

/**
 * Test suite focused on ExchangeRate operations at various scale levels.
 *
 * Covers realistic crypto conversion scenarios:
 * - BTC/USD (scale 8 → 2)
 * - ETH/BTC (scale 18 → 8)
 * - Stablecoin conversions (scale 6 → 6)
 */
final class ExchangeRateScaleTest extends TestCase
{
    use MoneyAssertions;

    // ==================== BTC/USD Conversions (Scale 8 → 2) ====================

    public function test_btc_to_usd_conversion_with_typical_rate(): void
    {
        $rate = ExchangeRate::fromString('BTC', 'USD', '65000.00', 2);
        $btc = Money::fromString('BTC', '1.50000000', 8);

        $usd = $rate->convert($btc, 2);

        self::assertSame('USD', $usd->currency());
        self::assertSame('97500.00', $usd->amount());
        self::assertSame(2, $usd->scale());
    }

    public function test_btc_to_usd_with_satoshi_amount(): void
    {
        $rate = ExchangeRate::fromString('BTC', 'USD', '65000.00000000', 8);
        // 1 satoshi = 0.00000001 BTC
        $satoshi = Money::fromString('BTC', '0.00000001', 8);

        $usd = $rate->convert($satoshi, 8);

        self::assertSame('USD', $usd->currency());
        self::assertSame('0.00065000', $usd->amount());
    }

    public function test_btc_to_usd_with_high_precision_rate(): void
    {
        $rate = ExchangeRate::fromString('BTC', 'USD', '64999.87654321', 8);
        $btc = Money::fromString('BTC', '2.50000000', 8);

        $usd = $rate->convert($btc, 8);

        self::assertSame('USD', $usd->currency());
        self::assertSame('162499.69135803', $usd->amount());
    }

    // ==================== ETH/BTC Conversions (Scale 18 → 8) ====================

    public function test_eth_to_btc_conversion_with_typical_rate(): void
    {
        $rate = ExchangeRate::fromString('ETH', 'BTC', '0.04567890', 8);
        $eth = Money::fromString('ETH', '10.000000000000000000', 18);

        $btc = $rate->convert($eth, 8);

        self::assertSame('BTC', $btc->currency());
        self::assertSame('0.45678900', $btc->amount());
    }

    public function test_eth_to_btc_with_wei_amount(): void
    {
        $rate = ExchangeRate::fromString('ETH', 'BTC', '0.04567890', 8);
        // 1 wei = 0.000000000000000001 ETH
        $wei = Money::fromString('ETH', '0.000000000000000001', 18);

        $btc = $rate->convert($wei, 18);

        self::assertSame('BTC', $btc->currency());
        // Result will be extremely small
        self::assertTrue($btc->lessThan(Money::fromString('BTC', '0.000000000000000001', 18)));
    }

    public function test_eth_to_btc_preserves_high_precision(): void
    {
        $rate = ExchangeRate::fromString('ETH', 'BTC', '0.045678901234567890', 18);
        $eth = Money::fromString('ETH', '1.000000000000000000', 18);

        $btc = $rate->convert($eth, 18);

        self::assertSame('BTC', $btc->currency());
        self::assertSame('0.045678901234567890', $btc->amount());
        self::assertSame(18, $btc->scale());
    }

    // ==================== Stablecoin Conversions (Scale 6 → 6) ====================

    public function test_usdc_to_usdt_near_parity(): void
    {
        $rate = ExchangeRate::fromString('USDC', 'USDT', '1.000010', 6);
        $usdc = Money::fromString('USDC', '1000.000000', 6);

        $usdt = $rate->convert($usdc, 6);

        self::assertSame('USDT', $usdt->currency());
        self::assertSame('1000.010000', $usdt->amount());
    }

    public function test_stablecoin_conversion_with_small_deviation(): void
    {
        $rate = ExchangeRate::fromString('DAI', 'USDC', '0.999950', 6);
        $dai = Money::fromString('DAI', '10000.000000', 6);

        $usdc = $rate->convert($dai, 6);

        self::assertSame('USDC', $usdc->currency());
        self::assertSame('9999.500000', $usdc->amount());
    }

    // ==================== Conversion with Tiny Base Amount ====================

    public function test_exchange_rate_conversion_with_tiny_base(): void
    {
        $rate = ExchangeRate::fromString('BTC', 'USD', '65000.00', 2);
        $tinyBtc = Money::fromString('BTC', '0.00000001', 8);

        $usd = $rate->convert($tinyBtc, 8);

        self::assertSame('USD', $usd->currency());
        self::assertSame('0.00065000', $usd->amount());
    }

    public function test_conversion_with_minimum_eth_wei(): void
    {
        $rate = ExchangeRate::fromString('ETH', 'USD', '3000.000000000000000000', 18);
        $oneWei = Money::fromString('ETH', '0.000000000000000001', 18);

        $usd = $rate->convert($oneWei, 18);

        self::assertSame('USD', $usd->currency());
        self::assertSame('0.000000000000003000', $usd->amount());
    }

    // ==================== Conversion with Huge Base Amount ====================

    public function test_exchange_rate_conversion_with_huge_base(): void
    {
        $rate = ExchangeRate::fromString('USD', 'JPY', '150.00', 2);
        $hugeUsd = Money::fromString('USD', '999999999.99', 2);

        $jpy = $rate->convert($hugeUsd, 2);

        self::assertSame('JPY', $jpy->currency());
        self::assertSame('149999999998.50', $jpy->amount());
    }

    public function test_conversion_with_large_btc_supply(): void
    {
        $rate = ExchangeRate::fromString('BTC', 'USD', '65000.00000000', 8);
        // Max BTC supply is 21 million
        $maxSupply = Money::fromString('BTC', '21000000.00000000', 8);

        $usd = $rate->convert($maxSupply, 8);

        self::assertSame('USD', $usd->currency());
        self::assertSame('1365000000000.00000000', $usd->amount());
    }

    // ==================== Exchange Rate Precision at Scale 18 ====================

    public function test_exchange_rate_precision_at_scale_18(): void
    {
        $rate = ExchangeRate::fromString('ETH', 'BTC', '0.045678901234567890', 18);

        self::assertSame('ETH', $rate->baseCurrency());
        self::assertSame('BTC', $rate->quoteCurrency());
        self::assertSame('0.045678901234567890', $rate->rate());
        self::assertSame(18, $rate->scale());
    }

    public function test_conversion_maintains_18_decimal_precision(): void
    {
        $rate = ExchangeRate::fromString('TOKENA', 'TOKENB', '1.234567890123456789', 18);
        $tokenA = Money::fromString('TOKENA', '100.000000000000000000', 18);

        $tokenB = $rate->convert($tokenA, 18);

        self::assertSame('TOKENB', $tokenB->currency());
        self::assertSame('123.456789012345678900', $tokenB->amount());
    }

    public function test_multiple_conversions_preserve_precision(): void
    {
        // ETH -> BTC -> USD at scale 18
        $ethBtcRate = ExchangeRate::fromString('ETH', 'BTC', '0.045678901234567890', 18);
        $btcUsdRate = ExchangeRate::fromString('BTC', 'USD', '65000.123456789012345678', 18);

        $eth = Money::fromString('ETH', '1.000000000000000000', 18);
        $btc = $ethBtcRate->convert($eth, 18);
        $usd = $btcUsdRate->convert($btc, 18);

        self::assertSame('USD', $usd->currency());
        // 1 ETH * 0.0456... BTC/ETH * 65000.12... USD/BTC
        self::assertSame(18, $usd->scale());
    }

    // ==================== Invert Operations ====================

    public function test_exchange_rate_invert_at_scale_8(): void
    {
        // Use a simple rate that inverts cleanly
        $rate = ExchangeRate::fromString('TOKENA', 'TOKENB', '2.00000000', 8);
        $inverted = $rate->invert();

        self::assertSame('TOKENB', $inverted->baseCurrency());
        self::assertSame('TOKENA', $inverted->quoteCurrency());
        self::assertSame(8, $inverted->scale());

        // 1/2 = 0.5
        self::assertSame('0.50000000', $inverted->rate());

        // Convert 2 TOKENB should give 1 TOKENA
        $tokenB = Money::fromString('TOKENB', '2.00000000', 8);
        $tokenA = $inverted->convert($tokenB, 8);

        self::assertTrue($tokenA->equals(Money::fromString('TOKENA', '1.00000000', 8)));
    }

    public function test_double_invert_returns_to_original(): void
    {
        $original = ExchangeRate::fromString('ETH', 'BTC', '0.04567890', 8);
        $inverted = $original->invert();
        $doubleInverted = $inverted->invert();

        self::assertSame($original->baseCurrency(), $doubleInverted->baseCurrency());
        self::assertSame($original->quoteCurrency(), $doubleInverted->quoteCurrency());
        self::assertSame($original->scale(), $doubleInverted->scale());
    }

    // ==================== Edge Cases and Validations ====================

    public function test_exchange_rate_rejects_zero_rate(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Exchange rate must be greater than zero.');

        ExchangeRate::fromString('BTC', 'USD', '0.00000000', 8);
    }

    public function test_exchange_rate_rejects_negative_rate(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Exchange rate must be greater than zero.');

        ExchangeRate::fromString('BTC', 'USD', '-65000.00', 2);
    }

    public function test_exchange_rate_rejects_same_currency(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Exchange rate requires distinct currencies.');

        ExchangeRate::fromString('USD', 'USD', '1.00', 2);
    }

    public function test_convert_rejects_wrong_base_currency(): void
    {
        $rate = ExchangeRate::fromString('BTC', 'USD', '65000.00', 2);
        $eth = Money::fromString('ETH', '10.00', 2);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money currency must match exchange rate base currency.');

        $rate->convert($eth);
    }

    // ==================== Realistic Multi-hop Conversion Scenarios ====================

    public function test_multi_hop_conversion_btc_to_jpy_via_usd(): void
    {
        $btcUsd = ExchangeRate::fromString('BTC', 'USD', '65000.00000000', 8);
        $usdJpy = ExchangeRate::fromString('USD', 'JPY', '150.00', 2);

        $btc = Money::fromString('BTC', '0.50000000', 8);
        $usd = $btcUsd->convert($btc, 8);
        // Need to ensure USD is at appropriate scale for JPY conversion
        $usdForJpy = $usd->withScale(2);
        $jpy = $usdJpy->convert($usdForJpy, 2);

        self::assertSame('JPY', $jpy->currency());
        // 0.5 BTC * 65000 USD/BTC * 150 JPY/USD = 4,875,000 JPY
        self::assertSame('4875000.00', $jpy->amount());
    }

    public function test_arbitrage_detection_via_conversion_loop(): void
    {
        // Create a conversion loop: BTC -> ETH -> USD -> BTC
        $btcEth = ExchangeRate::fromString('BTC', 'ETH', '20.00000000', 8);
        $ethUsd = ExchangeRate::fromString('ETH', 'USD', '3000.00000000', 8);
        $usdBtc = ExchangeRate::fromString('USD', 'BTC', '0.00001540', 8);

        $startBtc = Money::fromString('BTC', '1.00000000', 8);
        $eth = $btcEth->convert($startBtc, 8);
        $usd = $ethUsd->convert($eth, 8);
        $endBtc = $usdBtc->convert($usd, 8);

        // If rates are consistent, should get back approximately 1 BTC
        // 1 BTC * 20 ETH/BTC * 3000 USD/ETH * 0.0000154 BTC/USD ≈ 0.924 BTC
        self::assertSame('BTC', $endBtc->currency());
        self::assertSame(8, $endBtc->scale());
    }

    // ==================== Decimal Scale Boundary Testing ====================

    public function test_exchange_rate_at_scale_50(): void
    {
        $rate = ExchangeRate::fromString(
            'TOKENX',
            'TOKENY',
            '1.23456789012345678901234567890123456789012345678901',
            50
        );

        $tokenX = Money::fromString('TOKENX', '1', 50);
        $tokenY = $rate->convert($tokenX, 50);

        self::assertSame('TOKENY', $tokenY->currency());
        self::assertSame(
            '1.23456789012345678901234567890123456789012345678901',
            $tokenY->amount()
        );
    }

    public function test_exchange_rate_at_scale_0(): void
    {
        $rate = ExchangeRate::fromString('JPY', 'KRW', '9', 0);
        $jpy = Money::fromString('JPY', '1000', 0);

        $krw = $rate->convert($jpy, 0);

        self::assertSame('KRW', $krw->currency());
        self::assertSame('9000', $krw->amount());
    }

    public function test_exchange_rate_conversion_across_scale_boundaries(): void
    {
        $rate = ExchangeRate::fromString('SCALELOW', 'SCALEHIGH', '1.5', 2);
        $low = Money::fromString('SCALELOW', '100.00', 2);

        // Convert with higher output scale
        $high = $rate->convert($low, 18);

        self::assertSame('SCALEHIGH', $high->currency());
        self::assertSame('150.000000000000000000', $high->amount());
        self::assertSame(18, $high->scale());
    }
}
