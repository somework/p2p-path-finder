<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use InvalidArgumentException;

final class AssetPair
{
    private function __construct(
        private readonly string $base,
        private readonly string $quote,
    ) {
    }

    public static function fromString(string $base, string $quote): self
    {
        $normalizedBase = self::assertCurrency($base);
        $normalizedQuote = self::assertCurrency($quote);

        if ($normalizedBase === $normalizedQuote) {
            throw new InvalidArgumentException('Asset pair requires distinct assets.');
        }

        return new self($normalizedBase, $normalizedQuote);
    }

    public function base(): string
    {
        return $this->base;
    }

    public function quote(): string
    {
        return $this->quote;
    }

    private static function assertCurrency(string $currency): string
    {
        $money = Money::fromString($currency, '0');

        return $money->currency();
    }
}
