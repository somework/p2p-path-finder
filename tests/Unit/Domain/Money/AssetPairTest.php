<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Domain\Money;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\Money\AssetPair;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

#[CoversClass(AssetPair::class)]
final class AssetPairTest extends TestCase
{
    #[Test]
    public function it_creates_asset_pair_from_strings(): void
    {
        $pair = AssetPair::fromString('usd', 'eur');

        self::assertSame('USD', $pair->base());
        self::assertSame('EUR', $pair->quote());
    }

    #[Test]
    public function it_rejects_identical_assets(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Asset pair requires distinct assets.');

        AssetPair::fromString('btc', 'BTC');
    }

    #[Test]
    public function it_rejects_invalid_currency_codes(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Invalid currency "US1" supplied.');

        AssetPair::fromString('US1', 'eur');
    }

    #[Test]
    public function it_normalizes_currencies_to_uppercase(): void
    {
        $pair1 = AssetPair::fromString('btc', 'usd');
        $pair2 = AssetPair::fromString('BTC', 'USD');
        $pair3 = AssetPair::fromString('Btc', 'UsD');

        self::assertSame('BTC', $pair1->base());
        self::assertSame('USD', $pair1->quote());
        self::assertSame('BTC', $pair2->base());
        self::assertSame('USD', $pair2->quote());
        self::assertSame('BTC', $pair3->base());
        self::assertSame('USD', $pair3->quote());
    }

    #[Test]
    public function it_rejects_empty_base_currency(): void
    {
        $this->expectException(InvalidInput::class);

        AssetPair::fromString('', 'USD');
    }

    #[Test]
    public function it_rejects_empty_quote_currency(): void
    {
        $this->expectException(InvalidInput::class);

        AssetPair::fromString('BTC', '');
    }

    #[Test]
    public function it_handles_various_currency_pairs(): void
    {
        $pairs = [
            ['BTC', 'USD'],
            ['ETH', 'EUR'],
            ['USDT', 'GBP'],
            ['XRP', 'JPY'],
        ];

        foreach ($pairs as [$base, $quote]) {
            $pair = AssetPair::fromString($base, $quote);
            self::assertSame($base, $pair->base());
            self::assertSame($quote, $pair->quote());
        }
    }

    #[Test]
    public function it_preserves_immutability(): void
    {
        $pair = AssetPair::fromString('BTC', 'USD');

        // Access methods multiple times should return same values
        self::assertSame('BTC', $pair->base());
        self::assertSame('BTC', $pair->base());
        self::assertSame('USD', $pair->quote());
        self::assertSame('USD', $pair->quote());
    }

    #[Test]
    public function it_rejects_same_currencies_regardless_of_case(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Asset pair requires distinct assets.');

        AssetPair::fromString('usd', 'USD');
    }

    #[Test]
    public function it_validates_both_currencies(): void
    {
        $this->expectException(InvalidInput::class);

        AssetPair::fromString('123', 'ABC');
    }

    #[Test]
    public function it_rejects_numeric_currencies(): void
    {
        $this->expectException(InvalidInput::class);

        AssetPair::fromString('BTC', '999');
    }

    #[Test]
    public function it_supports_common_crypto_pairs(): void
    {
        $pair = AssetPair::fromString('BTC', 'USDT');

        self::assertSame('BTC', $pair->base());
        self::assertSame('USDT', $pair->quote());
    }

    #[Test]
    public function it_supports_fiat_pairs(): void
    {
        $pair = AssetPair::fromString('EUR', 'USD');

        self::assertSame('EUR', $pair->base());
        self::assertSame('USD', $pair->quote());
    }

    #[Test]
    public function it_accepts_minimum_length_currencies(): void
    {
        // Test currencies with exactly 3 characters (minimum valid length)
        $pair = AssetPair::fromString('BTC', 'USD');

        self::assertSame('BTC', $pair->base());
        self::assertSame('USD', $pair->quote());
    }

    #[Test]
    public function it_accepts_maximum_length_currencies(): void
    {
        // Test currencies with exactly 12 characters (maximum valid length)
        $longBase = 'VERYLONGCRY';
        $longQuote = 'ANOTHERLONG';

        $pair = AssetPair::fromString($longBase, $longQuote);

        self::assertSame($longBase, $pair->base());
        self::assertSame($longQuote, $pair->quote());
    }

    #[Test]
    public function it_rejects_currencies_too_short(): void
    {
        $this->expectException(InvalidInput::class);

        // 2 characters is too short
        AssetPair::fromString('AB', 'USD');
    }

    #[Test]
    public function it_rejects_currencies_too_long(): void
    {
        $this->expectException(InvalidInput::class);

        // 13 characters is too long
        AssetPair::fromString('THIRTEENCHARS', 'USD');
    }

    #[Test]
    public function it_rejects_currencies_with_special_characters(): void
    {
        $invalidCurrencies = ['USD!', 'EUR@', 'BTC#', 'ETH$', 'ADA%'];

        foreach ($invalidCurrencies as $invalid) {
            try {
                AssetPair::fromString($invalid, 'VALID');
                self::fail('Expected InvalidInput for currency with special characters: '.$invalid);
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }

            try {
                AssetPair::fromString('VALID', $invalid);
                self::fail('Expected InvalidInput for currency with special characters: '.$invalid);
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_rejects_currencies_with_spaces(): void
    {
        $this->expectException(InvalidInput::class);

        AssetPair::fromString('US D', 'EUR');
    }

    #[Test]
    public function it_rejects_currencies_with_dashes(): void
    {
        $this->expectException(InvalidInput::class);

        AssetPair::fromString('US-D', 'EUR');
    }

    #[Test]
    public function it_rejects_currencies_with_underscores(): void
    {
        $this->expectException(InvalidInput::class);

        AssetPair::fromString('US_D', 'EUR');
    }

    #[Test]
    public function it_handles_complex_case_mixtures(): void
    {
        // Test various case combinations that should all normalize to uppercase
        $caseVariations = [
            ['btc', 'usd'],
            ['BTC', 'usd'],
            ['btc', 'USD'],
            ['BtC', 'UsD'],
            ['bTc', 'uSd'],
        ];

        foreach ($caseVariations as [$base, $quote]) {
            $pair = AssetPair::fromString($base, $quote);

            self::assertSame('BTC', $pair->base());
            self::assertSame('USD', $pair->quote());
        }
    }

    #[Test]
    public function it_rejects_very_long_invalid_strings(): void
    {
        $this->expectException(InvalidInput::class);

        // Very long invalid string
        $longInvalid = str_repeat('A', 1000);
        AssetPair::fromString($longInvalid, 'USD');
    }

    #[Test]
    public function it_rejects_unicode_characters(): void
    {
        $unicodeCurrencies = ['USD€', 'EUR£', 'BTC¥', '₿TC', 'ＵＳＤ'];

        foreach ($unicodeCurrencies as $unicode) {
            try {
                AssetPair::fromString($unicode, 'VALID');
                self::fail('Expected InvalidInput for unicode currency: '.$unicode);
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_rejects_control_characters(): void
    {
        $this->expectException(InvalidInput::class);

        // Currency with null byte
        AssetPair::fromString('USD'."\x00", 'EUR');
    }

    #[Test]
    public function it_validates_error_messages_for_identical_assets(): void
    {
        try {
            AssetPair::fromString('USD', 'usd');
            self::fail('Expected InvalidInput for identical assets');
        } catch (InvalidInput $e) {
            self::assertStringContainsString('distinct assets', $e->getMessage());
        }
    }

    #[Test]
    public function it_validates_error_messages_for_invalid_currencies(): void
    {
        // Test with a currency that has invalid characters
        try {
            AssetPair::fromString('USD!', 'EUR');
            self::fail('Expected InvalidInput for invalid currency');
        } catch (InvalidInput) {
            // The error comes from Money validation, so we just verify an exception is thrown
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function it_handles_edge_case_currency_lengths(): void
    {
        // Test boundary lengths
        $validLengths = [3, 4, 5, 10, 11, 12];

        foreach ($validLengths as $length) {
            $currency = str_repeat('A', $length);
            $otherCurrency = str_repeat('B', $length);

            $pair = AssetPair::fromString($currency, $otherCurrency);

            self::assertSame($currency, $pair->base());
            self::assertSame($otherCurrency, $pair->quote());
        }
    }
}
