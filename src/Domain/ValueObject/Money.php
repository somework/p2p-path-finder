<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use SomeWork\P2PPathFinder\Domain\Math\DecimalMathInterface;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

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
        private readonly DecimalMathInterface $math,
    ) {
    }

    /**
     * Creates a new money instance from raw string components.
     *
     * @param string                    $currency ISO-like currency symbol comprised of 3-12 alphabetic characters
     * @param numeric-string            $amount   numeric string compatible with BCMath functions
     * @param int                       $scale    number of decimal digits to retain after normalization
     * @param DecimalMathInterface|null $math     decimal math adapter used for normalization and arithmetic
     *
     * @throws InvalidInput|PrecisionViolation when the currency or amount fail validation
     */
    public static function fromString(string $currency, string $amount, int $scale = 2, ?DecimalMathInterface $math = null): self
    {
        self::assertCurrency($currency);
        $normalizedCurrency = strtoupper($currency);

        $math = MathAdapterFactory::resolve($math);
        $normalizedAmount = $math->normalize($amount, $scale);

        return new self($normalizedCurrency, $normalizedAmount, $scale, $math);
    }

    /**
     * Creates a zero-value amount for the provided currency and scale.
     *
     * @param DecimalMathInterface|null $math decimal math adapter used for normalization and arithmetic
     *
     * @throws InvalidInput|PrecisionViolation when the currency or scale are invalid
     */
    public static function zero(string $currency, int $scale = 2, ?DecimalMathInterface $math = null): self
    {
        return self::fromString($currency, '0', $scale, $math);
    }

    /**
     * Returns a copy of the money instance rounded to the provided scale.
     *
     * @throws InvalidInput|PrecisionViolation when the requested scale is invalid
     */
    public function withScale(int $scale): self
    {
        if ($scale === $this->scale) {
            return $this;
        }

        $normalized = $this->math->normalize($this->amount, $scale);

        return new self($this->currency, $normalized, $scale, $this->math);
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
     *
     * @throws InvalidInput|PrecisionViolation when the currencies differ or the scale is invalid
     */
    public function add(self $other, ?int $scale = null): self
    {
        $this->assertSameCurrency($other);
        $scale ??= max($this->scale, $other->scale);

        $result = $this->math->add($this->amount, $other->amount, $scale);

        return new self($this->currency, $result, $scale, $this->math);
    }

    /**
     * Subtracts another money value using a common scale.
     *
     * @param self     $other money value expressed in the same currency
     * @param int|null $scale optional explicit scale override
     *
     * @throws InvalidInput|PrecisionViolation when the currencies differ or the scale is invalid
     */
    public function subtract(self $other, ?int $scale = null): self
    {
        $this->assertSameCurrency($other);
        $scale ??= max($this->scale, $other->scale);

        $result = $this->math->sub($this->amount, $other->amount, $scale);

        return new self($this->currency, $result, $scale, $this->math);
    }

    /**
     * Multiplies the amount by a scalar numeric multiplier.
     *
     * @param numeric-string $multiplier numeric multiplier compatible with BCMath
     * @param int|null       $scale      optional explicit scale override
     *
     * @throws InvalidInput|PrecisionViolation when the multiplier or scale are invalid
     */
    public function multiply(string $multiplier, ?int $scale = null): self
    {
        $this->math->ensureNumeric($multiplier);
        $scale ??= $this->scale;

        $result = $this->math->mul($this->amount, $multiplier, $scale);

        return new self($this->currency, $this->math->normalize($result, $scale), $scale, $this->math);
    }

    /**
     * Divides the amount by a scalar numeric divisor.
     *
     * @param numeric-string $divisor numeric divisor compatible with BCMath
     * @param int|null       $scale   optional explicit scale override
     *
     * @throws InvalidInput|PrecisionViolation when the divisor or scale are invalid
     */
    public function divide(string $divisor, ?int $scale = null): self
    {
        $this->math->ensureNumeric($divisor);
        $scale ??= $this->scale;
        $result = $this->math->div($this->amount, $divisor, $scale);

        return new self($this->currency, $this->math->normalize($result, $scale), $scale, $this->math);
    }

    /**
     * Compares two money values using the provided or derived scale.
     *
     * @param self     $other money value expressed in the same currency
     * @param int|null $scale optional explicit scale override
     *
     * @throws InvalidInput|PrecisionViolation when the currencies differ or BCMath validation fails
     *
     * @return int -1, 0 or 1 depending on the comparison result
     */
    public function compare(self $other, ?int $scale = null): int
    {
        $this->assertSameCurrency($other);
        $scale ??= $this->math->scaleForComparison($this->amount, $other->amount, max($this->scale, $other->scale));

        return $this->math->comp($this->amount, $other->amount, $scale);
    }

    /**
     * Determines whether two money values are equal.
     *
     * @throws InvalidInput|PrecisionViolation when comparison cannot be performed
     */
    public function equals(self $other): bool
    {
        return 0 === $this->compare($other);
    }

    /**
     * Checks if the current amount is greater than the provided amount.
     *
     * @throws InvalidInput|PrecisionViolation when comparison cannot be performed
     */
    public function greaterThan(self $other): bool
    {
        return 1 === $this->compare($other);
    }

    /**
     * Checks if the current amount is lower than the provided amount.
     *
     * @throws InvalidInput|PrecisionViolation when comparison cannot be performed
     */
    public function lessThan(self $other): bool
    {
        return -1 === $this->compare($other);
    }

    /**
     * Indicates whether the amount equals zero at the stored scale.
     *
     * @throws PrecisionViolation when the BCMath extension is unavailable
     */
    public function isZero(): bool
    {
        return 0 === $this->math->comp($this->amount, '0', $this->scale);
    }

    public function math(): DecimalMathInterface
    {
        return $this->math;
    }

    /**
     * @phpstan-assert non-empty-string $currency
     *
     * @psalm-assert non-empty-string $currency
     *
     * @throws InvalidInput when the currency symbol is empty or malformed
     */
    private static function assertCurrency(string $currency): void
    {
        if ('' === $currency) {
            throw new InvalidInput('Currency cannot be empty.');
        }
        if (!preg_match('/^[A-Z]{3,12}$/i', $currency)) {
            throw new InvalidInput(sprintf('Invalid currency "%s" supplied.', $currency));
        }
    }

    /**
     * @throws InvalidInput when the compared money values do not share the same currency
     */
    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidInput('Currency mismatch.');
        }
    }
}
