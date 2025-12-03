<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\MaterializedResult;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\Path;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHop;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHopCollection;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSetEntry;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalFactory;

use function strlen;

#[CoversClass(MaterializedResult::class)]
final class MaterializedResultTest extends TestCase
{
    public function test_it_exposes_path_and_order_key(): void
    {
        $path = $this->createPath('100.00', '90.00');

        $orderKey = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('1.111111111111111111')),
            1,
            RouteSignature::fromNodes(['USD', 'EUR']),
            0,
        );

        $materialized = new MaterializedResult($path, $orderKey);

        self::assertSame($path, $materialized->result());
        self::assertSame($orderKey, $materialized->orderKey());

        $entry = $materialized->toEntry();

        self::assertInstanceOf(PathResultSetEntry::class, $entry);
        self::assertSame($path, $entry->path());
        self::assertSame($orderKey, $entry->orderKey());
    }

    public function test_it_handles_varied_amounts(): void
    {
        $path = $this->createPath('0.00000001', '0.00000100');
        $orderKey = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('0.000000000000000001')),
            1,
            RouteSignature::fromNodes(['BTC', 'ETH']),
            42,
        );

        $materialized = new MaterializedResult($path, $orderKey);

        self::assertSame('0.00000001', $materialized->result()->totalSpent()->amount());
        self::assertSame(42, $materialized->orderKey()->insertionOrder());
    }

    private function createPath(string $spentAmount, string $receivedAmount): Path
    {
        $hop = new PathHop(
            'usd',
            'eur',
            $this->money('USD', $spentAmount),
            $this->money('EUR', $receivedAmount),
            OrderFactory::sell('USD', 'EUR'),
        );

        return new Path(PathHopCollection::fromList([$hop]), DecimalTolerance::fromNumericString('0.050000000000000000'));
    }

    private function money(string $currency, string $amount): Money
    {
        $scale = str_contains($amount, '.') ? strlen($amount) - strpos($amount, '.') - 1 : 0;

        return Money::fromString($currency, $amount, $scale);
    }
}
