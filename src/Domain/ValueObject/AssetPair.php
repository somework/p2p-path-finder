<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use SomeWork\P2PPathFinder\Exception\InvalidInput;

/**
 * Value object describing a directed asset pair (base -> quote).
 *
 * ## Invariants
 *
 * - **Distinct assets**: Base and quote must be distinct (after normalization)
 * - **Valid currencies**: Both must match /^[A-Z]{3,12}$/
 *
 * @invariant base != quote (after normalization)
 * @invariant base matches /^[A-Z]{3,12}$/
 * @invariant quote matches /^[A-Z]{3,12}$/
 *
 * @api
 */
final class AssetPair
{
    private function __construct(
        private readonly string $base,
        private readonly string $quote,
    ) {
    }

    /**
     * Creates an asset pair ensuring the provided currencies are distinct and valid.
     *
     * @throws InvalidInput when either currency code is invalid or both represent the same asset
     */
    public static function fromString(string $base, string $quote): self
    {
        $normalizedBase = self::assertCurrency($base);
        $normalizedQuote = self::assertCurrency($quote);

        if ($normalizedBase === $normalizedQuote) {
            throw new InvalidInput('Asset pair requires distinct assets.');
        }

        return new self($normalizedBase, $normalizedQuote);
    }

    /**
     * Returns the normalized base asset symbol.
     */
    public function base(): string
    {
        return $this->base;
    }

    /**
     * Returns the normalized quote asset symbol.
     */
    public function quote(): string
    {
        return $this->quote;
    }

    /**
     * @throws InvalidInput when the currency symbol does not conform to the expected format
     */
    private static function assertCurrency(string $currency): string
    {
        $money = Money::fromString($currency, '0');

        return $money->currency();
    }
}
