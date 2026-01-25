<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionStep;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionStepCollection;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

#[CoversClass(ExecutionStepCollection::class)]
final class ExecutionStepCollectionTest extends TestCase
{
    public function test_it_requires_list_of_steps(): void
    {
        $step = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Execution steps must be provided as a list.');

        ExecutionStepCollection::fromList(['first' => $step]);
    }

    public function test_it_requires_execution_step_instances(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Every execution step must be an instance of ExecutionStep.');

        ExecutionStepCollection::fromList([
            new ExecutionStep(
                'USD',
                'BTC',
                Money::fromString('USD', '100.00', 2),
                Money::fromString('BTC', '0.00250000', 8),
                OrderFactory::sell('USD', 'BTC'),
                MoneyMap::empty(),
                1,
            ),
            'invalid-step',
        ]);
    }

    public function test_it_sorts_steps_by_sequence_number(): void
    {
        $step3 = new ExecutionStep(
            'ETH',
            'USDT',
            Money::fromString('ETH', '0.05000000', 8),
            Money::fromString('USDT', '100.00', 2),
            OrderFactory::sell('ETH', 'USDT'),
            MoneyMap::empty(),
            3,
        );

        $step1 = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $step2 = new ExecutionStep(
            'BTC',
            'ETH',
            Money::fromString('BTC', '0.00250000', 8),
            Money::fromString('ETH', '0.05000000', 8),
            OrderFactory::sell('BTC', 'ETH'),
            MoneyMap::empty(),
            2,
        );

        // Provide steps out of order
        $collection = ExecutionStepCollection::fromList([$step3, $step1, $step2]);

        $all = $collection->all();
        self::assertSame(1, $all[0]->sequenceNumber());
        self::assertSame(2, $all[1]->sequenceNumber());
        self::assertSame(3, $all[2]->sequenceNumber());
    }

    public function test_empty_collection(): void
    {
        $collection = ExecutionStepCollection::empty();

        self::assertTrue($collection->isEmpty());
        self::assertSame(0, $collection->count());
        self::assertNull($collection->first());
        self::assertNull($collection->last());
        self::assertSame([], $collection->all());
        self::assertSame([], $collection->toArray());
    }

    public function test_from_list_with_empty_array(): void
    {
        $collection = ExecutionStepCollection::fromList([]);

        self::assertTrue($collection->isEmpty());
        self::assertSame(0, $collection->count());
    }

    public function test_first_and_last_accessors(): void
    {
        $step1 = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $step2 = new ExecutionStep(
            'BTC',
            'ETH',
            Money::fromString('BTC', '0.00250000', 8),
            Money::fromString('ETH', '0.05000000', 8),
            OrderFactory::sell('BTC', 'ETH'),
            MoneyMap::empty(),
            2,
        );

        $collection = ExecutionStepCollection::fromList([$step1, $step2]);

        self::assertSame(1, $collection->first()?->sequenceNumber());
        self::assertSame(2, $collection->last()?->sequenceNumber());
    }

    public function test_at_accessor(): void
    {
        $step1 = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $step2 = new ExecutionStep(
            'BTC',
            'ETH',
            Money::fromString('BTC', '0.00250000', 8),
            Money::fromString('ETH', '0.05000000', 8),
            OrderFactory::sell('BTC', 'ETH'),
            MoneyMap::empty(),
            2,
        );

        $collection = ExecutionStepCollection::fromList([$step1, $step2]);

        self::assertSame(1, $collection->at(0)->sequenceNumber());
        self::assertSame(2, $collection->at(1)->sequenceNumber());
    }

    public function test_at_throws_for_invalid_index(): void
    {
        $step = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $collection = ExecutionStepCollection::fromList([$step]);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Execution step index must reference an existing position.');

        $collection->at(5);
    }

    public function test_at_throws_for_negative_index(): void
    {
        $step = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $collection = ExecutionStepCollection::fromList([$step]);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Execution step index must reference an existing position.');

        $collection->at(-1);
    }

    public function test_count_returns_correct_value(): void
    {
        $step1 = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $step2 = new ExecutionStep(
            'BTC',
            'ETH',
            Money::fromString('BTC', '0.00250000', 8),
            Money::fromString('ETH', '0.05000000', 8),
            OrderFactory::sell('BTC', 'ETH'),
            MoneyMap::empty(),
            2,
        );

        $step3 = new ExecutionStep(
            'ETH',
            'USDT',
            Money::fromString('ETH', '0.05000000', 8),
            Money::fromString('USDT', '100.00', 2),
            OrderFactory::sell('ETH', 'USDT'),
            MoneyMap::empty(),
            3,
        );

        $collection = ExecutionStepCollection::fromList([$step1, $step2, $step3]);

        self::assertSame(3, $collection->count());
        self::assertCount(3, $collection);
    }

    public function test_collection_is_iterable(): void
    {
        $step1 = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $step2 = new ExecutionStep(
            'BTC',
            'ETH',
            Money::fromString('BTC', '0.00250000', 8),
            Money::fromString('ETH', '0.05000000', 8),
            OrderFactory::sell('BTC', 'ETH'),
            MoneyMap::empty(),
            2,
        );

        $collection = ExecutionStepCollection::fromList([$step1, $step2]);

        $sequences = [];
        foreach ($collection as $step) {
            $sequences[] = $step->sequenceNumber();
        }

        self::assertSame([1, 2], $sequences);
    }

    public function test_to_array_serializes_all_steps(): void
    {
        $step1 = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::fromList([Money::fromString('USD', '1.00', 2)]),
            1,
        );

        $step2 = new ExecutionStep(
            'BTC',
            'ETH',
            Money::fromString('BTC', '0.00250000', 8),
            Money::fromString('ETH', '0.05000000', 8),
            OrderFactory::sell('BTC', 'ETH'),
            MoneyMap::empty(),
            2,
        );

        $collection = ExecutionStepCollection::fromList([$step1, $step2]);

        $array = $collection->toArray();

        self::assertCount(2, $array);
        self::assertSame('USD', $array[0]['from']);
        self::assertSame('BTC', $array[0]['to']);
        self::assertSame(1, $array[0]['sequence']);
        self::assertSame('BTC', $array[1]['from']);
        self::assertSame('ETH', $array[1]['to']);
        self::assertSame(2, $array[1]['sequence']);
    }

    public function test_steps_with_same_sequence_maintain_insertion_order(): void
    {
        // Steps with same sequence number (parallel execution)
        $stepA = new ExecutionStep(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00250000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::empty(),
            1,
        );

        $stepB = new ExecutionStep(
            'USD',
            'ETH',
            Money::fromString('USD', '50.00', 2),
            Money::fromString('ETH', '0.02500000', 8),
            OrderFactory::sell('USD', 'ETH'),
            MoneyMap::empty(),
            1,
        );

        $collection = ExecutionStepCollection::fromList([$stepA, $stepB]);

        // Both should be present and sorted by sequence (both have 1)
        self::assertSame(2, $collection->count());
        self::assertSame(1, $collection->at(0)->sequenceNumber());
        self::assertSame(1, $collection->at(1)->sequenceNumber());
    }
}
