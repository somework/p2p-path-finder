<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function max;
use function sprintf;
use function strcasecmp;
use function strtoupper;

/**
 * Value object encapsulating an exchange rate between two assets.
 */
final class ExchangeRate
{
    /**
     * @param non-empty-string $baseCurrency
     * @param non-empty-string $quoteCurrency
     */
    private function __construct(
        private readonly string $baseCurrency,
        private readonly string $quoteCurrency,
        private readonly BigDecimal $decimal,
        private readonly int $scale,
    ) {
    }

    /**
     * Builds an exchange rate for the provided currency pair and numeric rate.
     *
     * @param non-empty-string $baseCurrency
     * @param non-empty-string $quoteCurrency
     * @param numeric-string   $rate
     *
     * @throws InvalidInput when the provided currencies or rate are invalid
     */
    public static function fromString(string $baseCurrency, string $quoteCurrency, string $rate, int $scale = 8): self
    {
        Money::fromString($baseCurrency, '0', $scale); // Validates the currency format.
        Money::fromString($quoteCurrency, '0', $scale);

        if (0 === strcasecmp($baseCurrency, $quoteCurrency)) {
            throw new InvalidInput('Exchange rate requires distinct currencies.');
        }

        $normalizedRate = self::scaleDecimal(self::decimalFromString($rate), $scale);

        if ($normalizedRate->compareTo(BigDecimal::zero()) <= 0) {
            throw new InvalidInput('Exchange rate must be greater than zero.');
        }

        return new self(strtoupper($baseCurrency), strtoupper($quoteCurrency), $normalizedRate, $scale);
    }

    /**
     * Converts a base currency amount into its quote currency representation.
     *
     * @throws InvalidInput when the provided money cannot be converted using the rate
     */
    public function convert(Money $money, ?int $scale = null): Money
    {
        if ($money->currency() !== $this->baseCurrency) {
            throw new InvalidInput('Money currency must match exchange rate base currency.');
        }

        $scale ??= max($this->scale, $money->scale());
        $product = $money->decimal()->multipliedBy($this->decimal);
        $normalized = self::decimalToString($product, $scale);

        return Money::fromString($this->quoteCurrency, $normalized, $scale);
    }

    /**
     * Returns the inverted exchange rate (quote becomes base and vice versa).
     *
     * @throws InvalidInput when arbitrary precision operations are unavailable
     */
    public function invert(): self
    {
        $inverseRaw = BigDecimal::one()->dividedBy($this->decimal, $this->scale + 1, RoundingMode::HALF_UP);
        $inverse = self::scaleDecimal($inverseRaw, $this->scale);

        return new self($this->quoteCurrency, $this->baseCurrency, $inverse, $this->scale);
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
        return self::decimalToString($this->decimal, $this->scale);
    }

    /**
     * Returns the scale used by the rate for arbitrary precision operations.
     */
    public function scale(): int
    {
        return $this->scale;
    }

    /**
     * Returns the BigDecimal representation of the rate.
     */
    public function decimal(): BigDecimal
    {
        return $this->decimal;
    }

    private static function assertScale(int $scale): void
    {
        if ($scale < 0) {
            throw new InvalidInput('Scale cannot be negative.');
        }
    }

    private static function decimalFromString(string $value): BigDecimal
    {
        try {
            return BigDecimal::of($value);
        } catch (MathException $exception) {
            throw new InvalidInput(sprintf('Value "%s" is not numeric.', $value), 0, $exception);
        }
    }

    private static function scaleDecimal(BigDecimal $decimal, int $scale): BigDecimal
    {
        self::assertScale($scale);

        return $decimal->toScale($scale, RoundingMode::HALF_UP);
    }

    /**
     * @return numeric-string
     */
    private static function decimalToString(BigDecimal $decimal, int $scale): string
    {
        /** @var numeric-string $result */
        $result = self::scaleDecimal($decimal, $scale)->__toString();

        return $result;
    }
}
