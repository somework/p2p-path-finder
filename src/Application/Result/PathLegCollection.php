<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Result;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use Traversable;

use function array_diff_key;
use function array_is_list;
use function array_key_first;
use function count;

/**
 * Immutable ordered collection of {@see PathLeg} instances.
 *
 * @implements IteratorAggregate<int, PathLeg>
 */
final class PathLegCollection implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @var list<PathLeg>
     */
    private array $legs;

    /**
     * @param list<PathLeg> $legs
     */
    private function __construct(array $legs)
    {
        $this->legs = $legs;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<array-key, PathLeg> $legs
     *
     * @throws InvalidInput when legs array is not a list, contains invalid elements, or cannot form a monotonic sequence
     */
    public static function fromList(array $legs): self
    {
        if ([] === $legs) {
            return new self([]);
        }

        if (!array_is_list($legs)) {
            throw new InvalidInput('Path legs must be provided as a list.');
        }

        /** @var list<PathLeg> $normalized */
        $normalized = [];
        foreach ($legs as $leg) {
            if (!$leg instanceof PathLeg) {
                throw new InvalidInput('Every path leg must be an instance of PathLeg.');
            }

            $normalized[] = $leg;
        }

        return new self(self::sortMonotonically($normalized));
    }

    public function count(): int
    {
        return count($this->legs);
    }

    /**
     * @return Traversable<int, PathLeg>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->legs);
    }

    /**
     * @return list<PathLeg>
     */
    public function all(): array
    {
        return $this->legs;
    }

    /**
     * @return list<PathLeg>
     */
    public function toArray(): array
    {
        return $this->all();
    }

    public function isEmpty(): bool
    {
        return [] === $this->legs;
    }

    /**
     * @return list<array{
     *     from: string,
     *     to: string,
     *     spent: array{currency: string, amount: numeric-string, scale: int},
     *     received: array{currency: string, amount: numeric-string, scale: int},
     *     fees: array<string, array{currency: string, amount: numeric-string, scale: int}>,
     * }>
     */
    public function jsonSerialize(): array
    {
        $serialized = [];

        foreach ($this->legs as $leg) {
            $serialized[] = $leg->jsonSerialize();
        }

        return $serialized;
    }

    /**
     * @throws InvalidInput when index does not reference an existing position
     */
    public function at(int $index): PathLeg
    {
        if (!isset($this->legs[$index])) {
            throw new InvalidInput('Path leg index must reference an existing position.');
        }

        return $this->legs[$index];
    }

    public function first(): ?PathLeg
    {
        return $this->legs[0] ?? null;
    }

    /**
     * @param list<PathLeg> $legs
     *
     * @return list<PathLeg>
     */
    private static function sortMonotonically(array $legs): array
    {
        if ([] === $legs) {
            return $legs;
        }

        /** @var array<string, PathLeg> $byOrigin */
        $byOrigin = [];
        /** @var array<string, true> $destinations */
        $destinations = [];

        foreach ($legs as $leg) {
            $from = $leg->from();
            $to = $leg->to();

            if (isset($byOrigin[$from])) {
                throw new InvalidInput('Path legs must be unique.');
            }

            $byOrigin[$from] = $leg;
            $destinations[$to] = true;
        }

        $startCandidates = array_diff_key($byOrigin, $destinations);
        if (1 !== count($startCandidates)) {
            throw new InvalidInput('Path legs must form a monotonic sequence.');
        }

        /** @var string|null $currentAsset */
        $currentAsset = array_key_first($startCandidates);
        if (null === $currentAsset) {
            throw new InvalidInput('Path legs must form a monotonic sequence.');
        }
        $sorted = [];
        /** @var array<string, true> $visitedDestinations */
        $visitedDestinations = [];

        while (isset($byOrigin[$currentAsset])) {
            $leg = $byOrigin[$currentAsset];
            unset($byOrigin[$currentAsset]);

            $destination = $leg->to();
            if (isset($visitedDestinations[$destination])) {
                throw new InvalidInput('Path legs must form a monotonic sequence.');
            }

            $sorted[] = $leg;
            $visitedDestinations[$destination] = true;
            $currentAsset = $destination;
        }

        if ([] !== $byOrigin) {
            throw new InvalidInput('Path legs must form a monotonic sequence.');
        }

        return $sorted;
    }
}
