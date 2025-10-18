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
}
