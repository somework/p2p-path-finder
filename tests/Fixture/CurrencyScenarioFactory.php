<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Fixture;

use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

final class CurrencyScenarioFactory
{
    /**
     * @param non-empty-string $currency
     * @param numeric-string   $amount
     */
    public static function money(string $currency, string $amount, int $scale = 3): Money
    {
        return Money::fromString($currency, $amount, $scale);
    }

    /**
     * @param non-empty-string $base
     * @param non-empty-string $quote
     * @param numeric-string   $rate
     */
    public static function exchangeRate(string $base, string $quote, string $rate, int $scale = 2): ExchangeRate
    {
        return ExchangeRate::fromString($base, $quote, $rate, $scale);
    }

    /**
     * @param non-empty-string $base
     * @param non-empty-string $quote
     */
    public static function assetPair(string $base, string $quote): AssetPair
    {
        return AssetPair::fromString($base, $quote);
    }
}
