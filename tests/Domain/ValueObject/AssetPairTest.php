<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
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
}
