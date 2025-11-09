<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Result;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSetEntry;
use SomeWork\P2PPathFinder\Application\Result\MoneyMap;
use SomeWork\P2PPathFinder\Application\Result\PathLeg;
use SomeWork\P2PPathFinder\Application\Result\PathLegCollection;
use SomeWork\P2PPathFinder\Application\Result\PathResult;
use SomeWork\P2PPathFinder\Application\Result\PathResultFormatter;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalTolerance;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use const PHP_EOL;

final class PathResultFormatterTest extends TestCase
{
    public function test_formatting(): void
    {
        $leg = new PathLeg(
            'usd',
            'eur',
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '95', 2),
            MoneyMap::fromList([Money::fromString('USD', '1.50', 2)], true),
        );

        $result = new PathResult(
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '95', 2),
            DecimalTolerance::fromNumericString('0.025'),
            PathLegCollection::fromList([$leg]),
            MoneyMap::fromAssociative(['USD' => Money::fromString('USD', '1.50', 2)]),
        );

        $formatter = new PathResultFormatter();

        $this->assertSame($result->jsonSerialize(), $formatter->formatMachine($result));

        $collection = PathResultSet::fromEntries(
            new CostHopsSignatureOrderingStrategy(18),
            [new PathResultSetEntry($result, new PathOrderKey(new PathCost('0.1'), 1, RouteSignature::fromNodes(['USD', 'EUR']), 0))],
        );

        $this->assertSame([
            $result->jsonSerialize(),
        ], $formatter->formatMachineCollection($collection));

        $expectedHuman = 'Total spent: USD 100.00; total received: EUR 95.00; total fees: USD 1.50; residual tolerance: 2.50%.'.PHP_EOL
            .'Legs:'.PHP_EOL
            .'  1. USD -> EUR | Spent USD 100.00 | Received EUR 95.00 | Fees USD 1.50';

        $this->assertSame($expectedHuman, $formatter->formatHuman($result));
        $this->assertSame('Path 1:'.PHP_EOL.$expectedHuman, $formatter->formatHumanCollection($collection));
        $this->assertSame('No paths available.', $formatter->formatHumanCollection(PathResultSet::empty()));
    }

    public function test_formatting_without_fees(): void
    {
        $leg = new PathLeg(
            'usd',
            'eur',
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '100', 2),
        );

        $result = new PathResult(
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '100', 2),
            DecimalTolerance::fromNumericString('0.015'),
            PathLegCollection::fromList([$leg]),
            MoneyMap::empty(),
        );

        $formatter = new PathResultFormatter();

        $this->assertSame($result->jsonSerialize(), $formatter->formatMachine($result));

        $expectedHuman = 'Total spent: USD 100.00; total received: EUR 100.00; total fees: none; residual tolerance: 1.50%.'
            .PHP_EOL
            .'Legs:'
            .PHP_EOL
            .'  1. USD -> EUR | Spent USD 100.00 | Received EUR 100.00 | Fees none';

        $this->assertSame($expectedHuman, $formatter->formatHuman($result));
    }
}
