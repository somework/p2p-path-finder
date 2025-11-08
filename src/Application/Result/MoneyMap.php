<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Result;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use SomeWork\P2PPathFinder\Application\Support\SerializesMoney;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;
use Traversable;

use function count;
use function ksort;

/**
 * Immutable map keyed by currency codes with {@see Money} entries.
 *
 * @implements IteratorAggregate<string, Money>
 */
final class MoneyMap implements Countable, IteratorAggregate, JsonSerializable
{
    use SerializesMoney;

    /**
     * @var array<string, Money>
     */
    private array $values;

    /**
     * @param array<string, Money> $values
     */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param iterable<Money> $entries
     *
     * @throws InvalidInput|PrecisionViolation when entries contain invalid values or cannot be merged deterministically
     */
    public static function fromList(iterable $entries, bool $skipZeroValues = false): self
    {
        /** @var array<string, Money> $normalized */
        $normalized = [];

        foreach ($entries as $entry) {
            if (!$entry instanceof Money) {
                throw new InvalidInput('Money map entries must be instances of Money.');
            }

            if ($skipZeroValues && $entry->isZero()) {
                continue;
            }

            $currency = $entry->currency();

            if (isset($normalized[$currency])) {
                $normalized[$currency] = $normalized[$currency]->add($entry);

                continue;
            }

            $normalized[$currency] = $entry;
        }

        if ([] !== $normalized) {
            ksort($normalized);
        }

        return new self($normalized);
    }

    /**
     * @param iterable<mixed, Money> $entries
     */
    public static function fromAssociative(iterable $entries, bool $skipZeroValues = false): self
    {
        return self::fromList($entries, $skipZeroValues);
    }

    public function isEmpty(): bool
    {
        return [] === $this->values;
    }

    public function count(): int
    {
        return count($this->values);
    }

    /**
     * @return array<string, Money>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @return Traversable<string, Money>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->values);
    }

    public function get(string $currency): ?Money
    {
        return $this->values[$currency] ?? null;
    }

    /**
     * @throws InvalidInput|PrecisionViolation when the money value cannot be merged with the existing entries
     */
    public function with(Money $money, bool $skipZeroValue = false): self
    {
        if ($skipZeroValue && $money->isZero()) {
            return $this;
        }

        $values = $this->values;
        $currency = $money->currency();

        if (isset($values[$currency])) {
            $values[$currency] = $values[$currency]->add($money);
        } else {
            $values[$currency] = $money;
        }

        ksort($values);

        return new self($values);
    }

    /**
     * @throws InvalidInput|PrecisionViolation when merging fails due to currency mismatches
     */
    public function merge(self $other): self
    {
        if ($other->isEmpty()) {
            return $this;
        }

        $values = $this->values;

        foreach ($other->values as $currency => $money) {
            if (isset($values[$currency])) {
                $values[$currency] = $values[$currency]->add($money);

                continue;
            }

            $values[$currency] = $money;
        }

        ksort($values);

        return new self($values);
    }

    /**
     * @return array<string, array{currency: string, amount: string, scale: int}>
     */
    public function jsonSerialize(): array
    {
        $serialized = [];

        foreach ($this->values as $currency => $money) {
            $serialized[$currency] = self::serializeMoney($money);
        }

        return $serialized;
    }

    public function has(string $currency): bool
    {
        return isset($this->values[$currency]);
    }
}
