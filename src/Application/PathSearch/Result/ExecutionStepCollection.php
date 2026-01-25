<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Result;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SplFixedArray;
use Traversable;

use function array_is_list;
use function count;
use function usort;

/**
 * Immutable ordered collection of {@see ExecutionStep} instances.
 *
 * Steps are stored sorted by sequence number to ensure deterministic
 * iteration order for execution plan processing.
 *
 * @implements IteratorAggregate<int, ExecutionStep>
 *
 * @api
 */
final class ExecutionStepCollection implements Countable, IteratorAggregate
{
    /**
     * @var SplFixedArray<ExecutionStep>
     */
    private SplFixedArray $steps;

    /**
     * @param SplFixedArray<ExecutionStep> $steps
     */
    private function __construct(SplFixedArray $steps)
    {
        $this->steps = $steps;
    }

    public static function empty(): self
    {
        /** @var SplFixedArray<ExecutionStep> $empty */
        $empty = new SplFixedArray(0);

        return new self($empty);
    }

    /**
     * @param array<array-key, ExecutionStep> $steps
     *
     * @throws InvalidInput when steps array is not a list or contains invalid elements
     */
    public static function fromList(array $steps): self
    {
        if ([] === $steps) {
            /** @var SplFixedArray<ExecutionStep> $empty */
            $empty = new SplFixedArray(0);

            return new self($empty);
        }

        if (!array_is_list($steps)) {
            throw new InvalidInput('Execution steps must be provided as a list.');
        }

        /** @var list<ExecutionStep> $normalized */
        $normalized = [];
        foreach ($steps as $step) {
            /* @phpstan-ignore-next-line instanceof.alwaysTrue */
            if (!$step instanceof ExecutionStep) {
                throw new InvalidInput('Every execution step must be an instance of ExecutionStep.');
            }

            $normalized[] = $step;
        }

        return new self(self::toFixedArray(self::sortBySequence($normalized)));
    }

    public function count(): int
    {
        return $this->steps->count();
    }

    /**
     * @return Traversable<int, ExecutionStep>
     */
    public function getIterator(): Traversable
    {
        /** @var list<ExecutionStep> $steps */
        $steps = $this->steps->toArray();

        return new ArrayIterator($steps);
    }

    /**
     * @return list<ExecutionStep>
     */
    public function all(): array
    {
        /** @var list<ExecutionStep> $steps */
        $steps = $this->steps->toArray();

        return $steps;
    }

    /**
     * @return list<array{from: string, to: string, spent: string, received: string, fees: array<string, string>, sequence: int}>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->all() as $step) {
            $result[] = $step->toArray();
        }

        return $result;
    }

    public function isEmpty(): bool
    {
        return 0 === $this->steps->count();
    }

    /**
     * @throws InvalidInput when index does not reference an existing position
     */
    public function at(int $index): ExecutionStep
    {
        $count = $this->steps->count();
        if (0 > $index || $index >= $count) {
            throw new InvalidInput('Execution step index must reference an existing position.');
        }

        /** @var ExecutionStep $step */
        $step = $this->steps[$index];

        return $step;
    }

    public function first(): ?ExecutionStep
    {
        if ($this->isEmpty()) {
            return null;
        }

        return $this->steps[0];
    }

    public function last(): ?ExecutionStep
    {
        if ($this->isEmpty()) {
            return null;
        }

        $lastIndex = $this->steps->count() - 1;

        /** @var ExecutionStep $step */
        $step = $this->steps[$lastIndex];

        return $step;
    }

    /**
     * @param list<ExecutionStep> $steps
     *
     * @return list<ExecutionStep>
     */
    private static function sortBySequence(array $steps): array
    {
        usort($steps, static fn (ExecutionStep $a, ExecutionStep $b): int => $a->sequenceNumber() <=> $b->sequenceNumber());

        return $steps;
    }

    /**
     * @param list<ExecutionStep> $steps
     *
     * @return SplFixedArray<ExecutionStep>
     */
    private static function toFixedArray(array $steps): SplFixedArray
    {
        /** @var SplFixedArray<ExecutionStep> $fixed */
        $fixed = new SplFixedArray(count($steps));

        foreach ($steps as $index => $step) {
            $fixed[$index] = $step;
        }

        return $fixed;
    }
}
