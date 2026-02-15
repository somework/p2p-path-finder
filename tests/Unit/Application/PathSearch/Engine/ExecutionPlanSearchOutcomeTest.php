<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\ExecutionPlanSearchOutcome;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

#[CoversClass(ExecutionPlanSearchOutcome::class)]
final class ExecutionPlanSearchOutcomeTest extends TestCase
{
    #[TestDox('empty() creates an outcome with no fills')]
    public function test_empty_creates_outcome_with_no_fills(): void
    {
        $guardReport = SearchGuardReport::none();

        $outcome = ExecutionPlanSearchOutcome::empty($guardReport);

        self::assertTrue($outcome->isEmpty());
        self::assertFalse($outcome->isComplete());
        self::assertFalse($outcome->isPartial());
        self::assertFalse($outcome->hasRawFills());
        self::assertNull($outcome->rawFills());
        self::assertSame('', $outcome->sourceCurrency());
        self::assertSame('', $outcome->targetCurrency());
    }

    #[TestDox('complete() creates an outcome marked as complete')]
    public function test_complete_creates_outcome_marked_complete(): void
    {
        $guardReport = SearchGuardReport::none();
        $order = OrderFactory::buy('BTC', 'USD', '0.100', '1.000', '30000.00', 3, 2);
        $spend = Money::fromString('BTC', '0.500', 3);
        $rawFills = [['order' => $order, 'spend' => $spend, 'sequence' => 0]];

        $outcome = ExecutionPlanSearchOutcome::complete($rawFills, $guardReport, 'BTC', 'USD');

        self::assertTrue($outcome->isComplete());
        self::assertFalse($outcome->isPartial());
        self::assertFalse($outcome->isEmpty());
        self::assertTrue($outcome->hasRawFills());
        self::assertSame('BTC', $outcome->sourceCurrency());
        self::assertSame('USD', $outcome->targetCurrency());
    }

    #[TestDox('complete() rejects empty fills array')]
    public function test_complete_rejects_empty_fills(): void
    {
        self::expectException(InvalidInput::class);
        self::expectExceptionMessage('Complete outcome requires at least one raw fill.');

        ExecutionPlanSearchOutcome::complete([], SearchGuardReport::none(), 'BTC', 'USD');
    }

    #[TestDox('partial() creates an outcome that is not complete but has fills')]
    public function test_partial_creates_outcome_with_fills_but_not_complete(): void
    {
        $guardReport = SearchGuardReport::none();
        $order = OrderFactory::sell('ETH', 'BTC', '1.000', '10.000', '0.05000000', 3, 8);
        $spend = Money::fromString('ETH', '5.000', 3);
        $rawFills = [['order' => $order, 'spend' => $spend, 'sequence' => 0]];

        $outcome = ExecutionPlanSearchOutcome::partial($rawFills, $guardReport, 'ETH', 'BTC');

        self::assertFalse($outcome->isComplete());
        self::assertTrue($outcome->isPartial());
        self::assertFalse($outcome->isEmpty());
        self::assertTrue($outcome->hasRawFills());
    }

    #[TestDox('partial() rejects empty fills array')]
    public function test_partial_rejects_empty_fills(): void
    {
        self::expectException(InvalidInput::class);
        self::expectExceptionMessage('Partial outcome requires at least one raw fill.');

        ExecutionPlanSearchOutcome::partial([], SearchGuardReport::none(), 'BTC', 'USD');
    }

    #[TestDox('rawFills() returns the fills array for complete outcomes')]
    public function test_raw_fills_returns_fills_for_complete_outcome(): void
    {
        $order = OrderFactory::buy('AAA', 'BBB', '1.000', '5.000', '2.500', 3, 3);
        $spend = Money::fromString('AAA', '3.000', 3);
        $rawFills = [
            ['order' => $order, 'spend' => $spend, 'sequence' => 0],
        ];

        $outcome = ExecutionPlanSearchOutcome::complete($rawFills, SearchGuardReport::none(), 'AAA', 'BBB');
        $result = $outcome->rawFills();

        self::assertNotNull($result);
        self::assertCount(1, $result);
        self::assertSame(0, $result[0]['sequence']);
        self::assertSame('3.000', $result[0]['spend']->amount());
    }

    #[TestDox('guardReport() returns the provided guard report')]
    public function test_guard_report_returns_provided_report(): void
    {
        $guardReport = SearchGuardReport::fromMetrics(
            expansions: 100,
            visitedStates: 50,
            elapsedMilliseconds: 42.5,
            expansionLimit: 1000,
            visitedStateLimit: 500,
            timeBudgetLimit: null,
        );

        $outcome = ExecutionPlanSearchOutcome::empty($guardReport);

        self::assertSame(100, $outcome->guardReport()->expansions());
        self::assertSame(50, $outcome->guardReport()->visitedStates());
    }

    #[TestDox('complete() with multiple fills preserves all entries')]
    public function test_complete_with_multiple_fills(): void
    {
        $order1 = OrderFactory::buy('BTC', 'USD', '0.100', '1.000', '30000.00', 3, 2);
        $order2 = OrderFactory::buy('BTC', 'USD', '0.100', '2.000', '29500.00', 3, 2);
        $rawFills = [
            ['order' => $order1, 'spend' => Money::fromString('BTC', '0.500', 3), 'sequence' => 0],
            ['order' => $order2, 'spend' => Money::fromString('BTC', '1.500', 3), 'sequence' => 1],
        ];

        $outcome = ExecutionPlanSearchOutcome::complete($rawFills, SearchGuardReport::none(), 'BTC', 'USD');
        $fills = $outcome->rawFills();

        self::assertNotNull($fills);
        self::assertCount(2, $fills);
        self::assertSame(0, $fills[0]['sequence']);
        self::assertSame(1, $fills[1]['sequence']);
    }
}
