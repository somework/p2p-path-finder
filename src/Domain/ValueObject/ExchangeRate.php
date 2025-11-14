<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use SomeWork\P2PPathFinder\Domain\Math\DecimalMathInterface;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

/**
 * Value object encapsulating an exchange rate between two assets.
 */
final class ExchangeRate
{
    /**
     * @param non-empty-string $baseCurrency
     * @param non-empty-string $quoteCurrency
     * @param numeric-string   $rate
     */
    private function __construct(
        private readonly string $baseCurrency,
        private readonly string $quoteCurrency,
        private readonly string $rate,
        private readonly int $scale,
        private readonly DecimalMathInterface $math,
    ) {
    }

    /**
     * Builds an exchange rate for the provided currency pair and numeric rate.
     *
     * @param non-empty-string $baseCurrency
     * @param non-empty-string $quoteCurrency
     * @param numeric-string   $rate
     *
     * @throws InvalidInput|PrecisionViolation when the provided currencies or rate are invalid
     */
    public static function fromString(string $baseCurrency, string $quoteCurrency, string $rate, int $scale = 8, ?DecimalMathInterface $math = null): self
    {
        $math = MathAdapterFactory::resolve($math);
        Money::fromString($baseCurrency, '0', $scale, $math); // Validates the currency format.
        Money::fromString($quoteCurrency, '0', $scale, $math);

        if (0 === strcasecmp($baseCurrency, $quoteCurrency)) {
            throw new InvalidInput('Exchange rate requires distinct currencies.');
        }

        $normalizedRate = $math->normalize($rate, $scale);
        if (1 !== $math->comp($normalizedRate, '0', $scale)) {
            throw new InvalidInput('Exchange rate must be greater than zero.');
        }

        return new self(strtoupper($baseCurrency), strtoupper($quoteCurrency), $normalizedRate, $scale, $math);
    }

    /**
     * Converts a base currency amount into its quote currency representation.
     *
     * @throws InvalidInput|PrecisionViolation when the provided money cannot be converted using the rate
     */
    public function convert(Money $money, ?int $scale = null): Money
    {
        if ($money->currency() !== $this->baseCurrency) {
            throw new InvalidInput('Money currency must match exchange rate base currency.');
        }

        $scale ??= max($this->scale, $money->scale());
        $raw = $this->math->mul($money->amount(), $this->rate, $scale + $this->scale);
        $normalized = $this->math->normalize($raw, $scale);

        return Money::fromString($this->quoteCurrency, $normalized, $scale, $this->math);
    }

    /**
     * Returns the inverted exchange rate (quote becomes base and vice versa).
     *
     * @throws PrecisionViolation when the BCMath extension is unavailable
     */
    public function invert(): self
    {
        $inverseRaw = $this->math->div('1', $this->rate, $this->scale + 1);
        $inverse = $this->math->normalize($inverseRaw, $this->scale);

        return new self($this->quoteCurrency, $this->baseCurrency, $inverse, $this->scale, $this->math);
    }

    public function math(): DecimalMathInterface
    {
        return $this->math;
    }

    /**
     * Returns the base currency symbol used by the rate.
     */
    /**
     * @return non-empty-string
     */
    public function baseCurrency(): string
    {
        return $this->baseCurrency;
    }

    /**
     * Returns the quote currency symbol used by the rate.
     */
    /**
     * @return non-empty-string
     */
    public function quoteCurrency(): string
    {
        return $this->quoteCurrency;
    }

    /**
     * Returns the normalized numeric representation of the rate.
     */
    /**
     * @return numeric-string
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
