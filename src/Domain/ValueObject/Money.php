<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function max;
use function sprintf;

/**
 * Immutable representation of a monetary amount backed by arbitrary precision arithmetic.
 *
 * Money instances always carry their currency code, normalized amount representation and
 * the scale used when interacting with arbitrary precision operations. Instances are created
 * through named constructors to guarantee validation and normalization of their internal state.
 *
 * ## Invariants
 *
 * - **Non-negative amounts**: Money amounts must be >= 0. Negative amounts have no semantic
 *   meaning in the path-finding domain (orders, spends, receives, fees are all naturally
 *   non-negative). Construction with negative amounts throws InvalidInput.
 * - **Valid currency**: Currency code must be 3-12 uppercase letters matching /^[A-Z]{3,12}$/
 * - **Scale bounds**: Scale must be 0 <= scale <= 50 to prevent memory/performance issues
 * - **Precision preservation**: Amounts are stored as BigDecimal and normalized to the
 *   specified scale using HALF_UP rounding
 *
 * ## Scale Derivation Rules
 *
 * Arithmetic operations follow deterministic scale derivation rules to ensure predictable
 * precision handling:
 *
 * - **Addition/Subtraction**: Result scale = max(left.scale, right.scale) unless explicitly
 *   overridden. This ensures no precision loss from either operand.
 * - **Multiplication/Division**: Result scale = left.scale (the Money instance's scale) unless
 *   explicitly overridden. Scalar operands do not influence the result scale.
 * - **Explicit Override**: All arithmetic operations accept an optional scale parameter that
 *   takes precedence over default derivation rules.
 * - **Comparison**: Uses max(left.scale, right.scale, explicitScale) to ensure accurate
 *   comparison at the highest precision available.
 *
 * @invariant amount >= 0
 * @invariant scale >= 0 && scale <= 50
 * @invariant currency matches /^[A-Z]{3,12}$/
 * @invariant add/subtract result scale = max(left.scale, right.scale) OR explicit scale
 * @invariant multiply/divide result scale = left.scale OR explicit scale
 *
 * @api
 */
final class Money
{
    private function __construct(
        private readonly string $currency,
        private readonly BigDecimal $decimal,
        private readonly int $scale,
    ) {
    }

    /**
     * Creates a new money instance from raw string components.
     *
     * @param string         $currency ISO-like currency symbol comprised of 3-12 alphabetic characters
     * @param numeric-string $amount   numeric string convertible to an arbitrary precision decimal
     * @param int            $scale    number of decimal digits to retain after normalization
     *
     * @throws InvalidInput|PrecisionViolation when the currency or amount fail validation
     */
    public static function fromString(string $currency, string $amount, int $scale = 2): self
    {
        self::assertCurrency($currency);
        $normalizedCurrency = strtoupper($currency);

        $decimal = self::decimalFromString($amount);
        
        // Enforce non-negative amounts: path finding domain has no semantic meaning for negative money
        if ($decimal->isNegative()) {
            throw new InvalidInput(
                sprintf('Money amount cannot be negative. Got: %s %s', $normalizedCurrency, $amount)
            );
        }

        $normalizedDecimal = self::scaleDecimal($decimal, $scale);

        return new self($normalizedCurrency, $normalizedDecimal, $scale);
    }

    /**
     * Creates a zero-value amount for the provided currency and scale.
     *
     * @throws InvalidInput|PrecisionViolation when the currency or scale are invalid
     */
    public static function zero(string $currency, int $scale = 2): self
    {
        return self::fromString($currency, '0', $scale);
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

        $normalized = self::scaleDecimal($this->decimal, $scale);

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
     *
     * @psalm-mutation-free
     *
     * @return numeric-string
     */
    public function amount(): string
    {
        return self::decimalToString($this->decimal, $this->scale);
    }

    /**
     * Returns the scale (number of fractional digits) used for the amount.
     */
    public function scale(): int
    {
        return $this->scale;
    }

    /**
     * Returns the BigDecimal representation of the amount.
     */
    public function decimal(): BigDecimal
    {
        return $this->decimal;
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

        $result = self::scaleDecimal($this->decimal->plus($other->decimal), $scale);

        return new self($this->currency, $result, $scale);
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

        $result = self::scaleDecimal($this->decimal->minus($other->decimal), $scale);

        return new self($this->currency, $result, $scale);
    }

    /**
     * Multiplies the amount by a scalar numeric multiplier.
     *
     * @param numeric-string $multiplier numeric multiplier convertible to an arbitrary precision decimal
     * @param int|null       $scale      optional explicit scale override
     *
     * @throws InvalidInput|PrecisionViolation when the multiplier or scale are invalid
     */
    public function multiply(string $multiplier, ?int $scale = null): self
    {
        $scale ??= $this->scale;

        $result = self::scaleDecimal(
            $this->decimal->multipliedBy(self::decimalFromString($multiplier)),
            $scale,
        );

        return new self($this->currency, $result, $scale);
    }

    /**
     * Divides the amount by a scalar numeric divisor.
     *
     * @param numeric-string $divisor numeric divisor convertible to an arbitrary precision decimal
     * @param int|null       $scale   optional explicit scale override
     *
     * @throws InvalidInput|PrecisionViolation when the divisor or scale are invalid
     */
    public function divide(string $divisor, ?int $scale = null): self
    {
        $scale ??= $this->scale;
        self::assertScale($scale);

        $divisorDecimal = self::decimalFromString($divisor);
        if ($divisorDecimal->isZero()) {
            throw new InvalidInput('Division by zero.');
        }

        $result = self::scaleDecimal($this->decimal->dividedBy($divisorDecimal, $scale, RoundingMode::HALF_UP), $scale);

        return new self($this->currency, $result, $scale);
    }

    /**
     * Compares two money values using the provided or derived scale.
     *
     * @param self     $other money value expressed in the same currency
     * @param int|null $scale optional explicit scale override
     *
     * @throws InvalidInput|PrecisionViolation when the currencies differ or comparison cannot be performed
     *
     * @return int -1, 0 or 1 depending on the comparison result
     */
    public function compare(self $other, ?int $scale = null): int
    {
        $this->assertSameCurrency($other);
        $comparisonScale = max($scale ?? max($this->scale, $other->scale), $this->scale, $other->scale);

        $left = self::scaleDecimal($this->decimal, $comparisonScale);
        $right = self::scaleDecimal($other->decimal, $comparisonScale);

        return $left->compareTo($right);
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
     */
    public function isZero(): bool
    {
        return $this->decimal->isZero();
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

    /**
     * Maximum allowed scale to prevent memory exhaustion and performance degradation.
     */
    private const MAX_SCALE = 50;

    /**
     * @psalm-mutation-free
     */
    private static function assertScale(int $scale): void
    {
        if ($scale < 0) {
            throw new InvalidInput('Scale cannot be negative.');
        }
        if ($scale > self::MAX_SCALE) {
            throw new InvalidInput(sprintf('Scale cannot exceed %d decimal places.', self::MAX_SCALE));
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

    /**
     * @psalm-mutation-free
     */
    private static function scaleDecimal(BigDecimal $decimal, int $scale): BigDecimal
    {
        self::assertScale($scale);

        return $decimal->toScale($scale, RoundingMode::HALF_UP);
    }

    /**
     * @psalm-mutation-free
     *
     * @return numeric-string
     */
    private static function decimalToString(BigDecimal $decimal, int $scale): string
    {
        /** @var numeric-string $result */
        $result = self::scaleDecimal($decimal, $scale)->__toString();

        return $result;
    }
}
