<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\Money;

use SomeWork\P2PPathFinder\Exception\InvalidInput;

/**
 * Value object describing a directed asset pair (base -> quote).
 *
 * ## Invariants
 *
 * - **Valid currencies**: Both must match /^[A-Z]{3,12}$/
 * - **Transfer pairs**: Base and quote may be identical for transfer orders (cross-exchange movements)
 *
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
     * Creates an asset pair from the provided currency codes.
     *
     * Allows both conversion pairs (base != quote) and transfer pairs (base == quote).
     * Transfer pairs represent cross-exchange movements of the same currency.
     *
     * @throws InvalidInput when either currency code is invalid
     */
    public static function fromString(string $base, string $quote): self
    {
        $normalizedBase = self::assertCurrency($base);
        $normalizedQuote = self::assertCurrency($quote);

        return new self($normalizedBase, $normalizedQuote);
    }

    /**
     * Creates an asset pair ensuring the currencies are distinct.
     *
     * Use this when you specifically need a conversion pair, not a transfer.
     *
     * @throws InvalidInput when either currency code is invalid or both represent the same asset
     */
    public static function conversion(string $base, string $quote): self
    {
        $normalizedBase = self::assertCurrency($base);
        $normalizedQuote = self::assertCurrency($quote);

        if ($normalizedBase === $normalizedQuote) {
            throw new InvalidInput('Conversion pair requires distinct assets.');
        }

        return new self($normalizedBase, $normalizedQuote);
    }

    /**
     * Creates a transfer pair for same-currency cross-exchange movements.
     *
     * @throws InvalidInput when the currency code is invalid
     */
    public static function transfer(string $currency): self
    {
        $normalizedCurrency = self::assertCurrency($currency);

        return new self($normalizedCurrency, $normalizedCurrency);
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
     * Returns whether this asset pair represents a same-currency transfer.
     *
     * Transfer pairs have identical base and quote currencies and represent
     * cross-exchange movements rather than currency conversions.
     */
    public function isTransfer(): bool
    {
        return $this->base === $this->quote;
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
