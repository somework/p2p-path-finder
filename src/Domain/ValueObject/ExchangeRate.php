<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Value object encapsulating an exchange rate between two assets.
 */
final class ExchangeRate
{
    private function __construct(
        private readonly string $baseCurrency,
        private readonly string $quoteCurrency,
        private readonly string $rate,
        private readonly int $scale,
    ) {
    }

    /**
     * Builds an exchange rate for the provided currency pair and numeric rate.
     */
    public static function fromString(string $baseCurrency, string $quoteCurrency, string $rate, int $scale = 8): self
    {
        Money::fromString($baseCurrency, '0', $scale); // Validates the currency format.
        Money::fromString($quoteCurrency, '0', $scale);

        if (0 === strcasecmp($baseCurrency, $quoteCurrency)) {
            throw new InvalidArgumentException('Exchange rate requires distinct currencies.');
        }

        $normalizedRate = BcMath::normalize($rate, $scale);
        if (1 !== BcMath::comp($normalizedRate, '0', $scale)) {
            throw new InvalidArgumentException('Exchange rate must be greater than zero.');
        }

        return new self(strtoupper($baseCurrency), strtoupper($quoteCurrency), $normalizedRate, $scale);
    }

    /**
     * Converts a base currency amount into its quote currency representation.
     */
    public function convert(Money $money, ?int $scale = null): Money
    {
        if ($money->currency() !== $this->baseCurrency) {
            throw new InvalidArgumentException('Money currency must match exchange rate base currency.');
        }

        $scale ??= max($this->scale, $money->scale());
        $raw = BcMath::mul($money->amount(), $this->rate, $scale + $this->scale);
        $normalized = BcMath::normalize($raw, $scale);

        return Money::fromString($this->quoteCurrency, $normalized, $scale);
    }

    /**
     * Returns the inverted exchange rate (quote becomes base and vice versa).
     */
    public function invert(): self
    {
        $inverseRaw = BcMath::div('1', $this->rate, $this->scale + 1);
        $inverse = BcMath::normalize($inverseRaw, $this->scale);

        return new self($this->quoteCurrency, $this->baseCurrency, $inverse, $this->scale);
    }

    /**
     * Returns the base currency symbol used by the rate.
     */
    public function baseCurrency(): string
    {
        return $this->baseCurrency;
    }

    /**
     * Returns the quote currency symbol used by the rate.
     */
    public function quoteCurrency(): string
    {
        return $this->quoteCurrency;
    }

    /**
     * Returns the normalized numeric representation of the rate.
     */
    public function rate(): string
    {
        return $this->rate;
    }

    /**
     * Returns the scale used by the rate for BCMath operations.
     */
    public function scale(): int
    {
        return $this->scale;
    }
}
