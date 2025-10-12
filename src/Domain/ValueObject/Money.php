<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use InvalidArgumentException;

use function sprintf;

final class Money
{
    private function __construct(
        private readonly string $currency,
        private readonly string $amount,
        private readonly int $scale,
    ) {
    }

    public static function fromString(string $currency, string $amount, int $scale = 2): self
    {
        self::assertCurrency($currency);
        $normalizedCurrency = strtoupper($currency);

        $normalizedAmount = BcMath::normalize($amount, $scale);

        return new self($normalizedCurrency, $normalizedAmount, $scale);
    }

    public static function zero(string $currency, int $scale = 2): self
    {
        return self::fromString($currency, '0', $scale);
    }

    public function withScale(int $scale): self
    {
        if ($scale === $this->scale) {
            return $this;
        }

        $normalized = BcMath::normalize($this->amount, $scale);

        return new self($this->currency, $normalized, $scale);
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function scale(): int
    {
        return $this->scale;
    }

    public function add(self $other, ?int $scale = null): self
    {
        $this->assertSameCurrency($other);
        $scale ??= max($this->scale, $other->scale);

        $result = BcMath::add($this->amount, $other->amount, $scale);

        return new self($this->currency, $result, $scale);
    }

    public function subtract(self $other, ?int $scale = null): self
    {
        $this->assertSameCurrency($other);
        $scale ??= max($this->scale, $other->scale);

        $result = BcMath::sub($this->amount, $other->amount, $scale);

        return new self($this->currency, $result, $scale);
    }

    public function multiply(string $multiplier, ?int $scale = null): self
    {
        BcMath::ensureNumeric($multiplier);
        $scale ??= $this->scale;

        $result = BcMath::mul($this->amount, $multiplier, $scale);

        return new self($this->currency, BcMath::normalize($result, $scale), $scale);
    }

    public function divide(string $divisor, ?int $scale = null): self
    {
        $scale ??= $this->scale;
        $result = BcMath::div($this->amount, $divisor, $scale);

        return new self($this->currency, BcMath::normalize($result, $scale), $scale);
    }

    public function compare(self $other, ?int $scale = null): int
    {
        $this->assertSameCurrency($other);
        $scale ??= BcMath::scaleForComparison($this->amount, $other->amount, max($this->scale, $other->scale));

        return BcMath::comp($this->amount, $other->amount, $scale);
    }

    public function equals(self $other): bool
    {
        return 0 === $this->compare($other);
    }

    public function greaterThan(self $other): bool
    {
        return 1 === $this->compare($other);
    }

    public function lessThan(self $other): bool
    {
        return -1 === $this->compare($other);
    }

    public function isZero(): bool
    {
        return 0 === BcMath::comp($this->amount, '0', $this->scale);
    }

    private static function assertCurrency(string $currency): void
    {
        if ('' === $currency) {
            throw new InvalidArgumentException('Currency cannot be empty.');
        }
        if (!preg_match('/^[A-Z]{3}$/i', $currency)) {
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
