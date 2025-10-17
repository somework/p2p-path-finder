<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use InvalidArgumentException;

use function sprintf;

/**
 * Immutable representation of a monetary amount backed by arbitrary precision arithmetic.
 *
 * Money instances always carry their currency code, normalized amount representation and
 * the scale used when interacting with BCMath operations. Instances are created through
 * named constructors to guarantee validation and normalization of their internal state.
 */
final class Money
{
    /**
     * @param numeric-string $amount
     */
    private function __construct(
        private readonly string $currency,
        private readonly string $amount,
        private readonly int $scale,
    ) {
    }

    /**
     * Creates a new money instance from raw string components.
     *
     * @param string         $currency ISO-like currency symbol comprised of 3-12 alphabetic characters
     * @param numeric-string $amount   numeric string compatible with BCMath functions
     * @param int            $scale    number of decimal digits to retain after normalization
     */
    public static function fromString(string $currency, string $amount, int $scale = 2): self
    {
        self::assertCurrency($currency);
        $normalizedCurrency = strtoupper($currency);

        $normalizedAmount = BcMath::normalize($amount, $scale);

        return new self($normalizedCurrency, $normalizedAmount, $scale);
    }

    /**
     * Creates a zero-value amount for the provided currency and scale.
     */
    public static function zero(string $currency, int $scale = 2): self
    {
        return self::fromString($currency, '0', $scale);
    }

    /**
     * Returns a copy of the money instance rounded to the provided scale.
     */
    public function withScale(int $scale): self
    {
        if ($scale === $this->scale) {
            return $this;
        }

        $normalized = BcMath::normalize($this->amount, $scale);

        return new self($this->currency, $normalized, $scale);
    }

    /**
     * Retrieves the ISO-like currency code of the amount.
     */
    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * Returns the normalized numeric string representation of the amount.
     */
    /**
     * @return numeric-string
     */
    public function amount(): string
    {
        return $this->amount;
    }

    /**
     * Returns the scale (number of fractional digits) used for the amount.
     */
    public function scale(): int
    {
        return $this->scale;
    }

    /**
     * Adds another money value using a common scale.
     *
     * @param self     $other money value expressed in the same currency
     * @param int|null $scale optional explicit scale override
     */
    public function add(self $other, ?int $scale = null): self
    {
        $this->assertSameCurrency($other);
        $scale ??= max($this->scale, $other->scale);

        $result = BcMath::add($this->amount, $other->amount, $scale);

        return new self($this->currency, $result, $scale);
    }

    /**
     * Subtracts another money value using a common scale.
     *
     * @param self     $other money value expressed in the same currency
     * @param int|null $scale optional explicit scale override
     */
    public function subtract(self $other, ?int $scale = null): self
    {
        $this->assertSameCurrency($other);
        $scale ??= max($this->scale, $other->scale);

        $result = BcMath::sub($this->amount, $other->amount, $scale);

        return new self($this->currency, $result, $scale);
    }

    /**
     * Multiplies the amount by a scalar numeric multiplier.
     *
     * @param numeric-string $multiplier numeric multiplier compatible with BCMath
     * @param int|null       $scale      optional explicit scale override
     */
    public function multiply(string $multiplier, ?int $scale = null): self
    {
        BcMath::ensureNumeric($multiplier);
        $scale ??= $this->scale;

        $result = BcMath::mul($this->amount, $multiplier, $scale);

        return new self($this->currency, BcMath::normalize($result, $scale), $scale);
    }

    /**
     * Divides the amount by a scalar numeric divisor.
     *
     * @param numeric-string $divisor numeric divisor compatible with BCMath
     * @param int|null       $scale   optional explicit scale override
     */
    public function divide(string $divisor, ?int $scale = null): self
    {
        BcMath::ensureNumeric($divisor);
        $scale ??= $this->scale;
        $result = BcMath::div($this->amount, $divisor, $scale);

        return new self($this->currency, BcMath::normalize($result, $scale), $scale);
    }

    /**
     * Compares two money values using the provided or derived scale.
     *
     * @param self     $other money value expressed in the same currency
     * @param int|null $scale optional explicit scale override
     *
     * @return int -1, 0 or 1 depending on the comparison result
     */
    public function compare(self $other, ?int $scale = null): int
    {
        $this->assertSameCurrency($other);
        $scale ??= BcMath::scaleForComparison($this->amount, $other->amount, max($this->scale, $other->scale));

        return BcMath::comp($this->amount, $other->amount, $scale);
    }

    /**
     * Determines whether two money values are equal.
     */
    public function equals(self $other): bool
    {
        return 0 === $this->compare($other);
    }

    /**
     * Checks if the current amount is greater than the provided amount.
     */
    public function greaterThan(self $other): bool
    {
        return 1 === $this->compare($other);
    }

    /**
     * Checks if the current amount is lower than the provided amount.
     */
    public function lessThan(self $other): bool
    {
        return -1 === $this->compare($other);
    }

    /**
     * Indicates whether the amount equals zero at the stored scale.
     */
    public function isZero(): bool
    {
        return 0 === BcMath::comp($this->amount, '0', $this->scale);
    }

    /**
     * @phpstan-assert non-empty-string $currency
     *
     * @psalm-assert non-empty-string $currency
     */
    private static function assertCurrency(string $currency): void
    {
        if ('' === $currency) {
            throw new InvalidArgumentException('Currency cannot be empty.');
        }
        if (!preg_match('/^[A-Z]{3,12}$/i', $currency)) {
            throw new InvalidArgumentException(sprintf('Invalid currency "%s" supplied.', $currency));
        }
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Currency mismatch.');
        }
    }
}
