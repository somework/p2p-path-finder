<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathLeg;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathLegCollection;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResult;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathSearch\Support\PathResultFormatter;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;

use const PHP_EOL;

#[CoversClass(PathResultFormatter::class)]
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

        $collection = PathResultSet::fromPaths(
            new CostHopsSignatureOrderingStrategy(18),
            [$result],
            static fn (PathResult $path, int $index): PathOrderKey => new PathOrderKey(new PathCost('0.1'), 1, RouteSignature::fromNodes(['USD', 'EUR']), $index),
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

    public function test_format_machine_collection_with_empty_set(): void
    {
        $formatter = new PathResultFormatter();
        $emptySet = PathResultSet::empty();

        $this->assertSame([], $formatter->formatMachineCollection($emptySet));
    }

    public function test_format_human_collection_with_empty_array(): void
    {
        $formatter = new PathResultFormatter();

        $this->assertSame('No paths available.', $formatter->formatHumanCollection([]));
    }

    public function test_format_human_collection_with_array_input(): void
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

        $expected = 'Path 1:'.PHP_EOL.
            'Total spent: USD 100.00; total received: EUR 95.00; total fees: USD 1.50; residual tolerance: 2.50%.'.PHP_EOL.
            'Legs:'.PHP_EOL.
            '  1. USD -> EUR | Spent USD 100.00 | Received EUR 95.00 | Fees USD 1.50';

        $this->assertSame($expected, $formatter->formatHumanCollection([$result]));
    }

    public function test_formatting_with_multiple_legs(): void
    {
        $firstLeg = new PathLeg(
            'usd',
            'btc',
            Money::fromString('USD', '100', 2),
            Money::fromString('BTC', '0.01', 8),
            MoneyMap::fromList([Money::fromString('USD', '1.00', 2)], true),
        );

        $secondLeg = new PathLeg(
            'btc',
            'eur',
            Money::fromString('BTC', '0.01', 8),
            Money::fromString('EUR', '95', 2),
            MoneyMap::fromList([Money::fromString('EUR', '0.50', 2)], true),
        );

        $result = new PathResult(
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '95', 2),
            DecimalTolerance::fromNumericString('0.025'),
            PathLegCollection::fromList([$firstLeg, $secondLeg]),
            MoneyMap::fromAssociative([
                'USD' => Money::fromString('USD', '1.00', 2),
                'EUR' => Money::fromString('EUR', '0.50', 2),
            ]),
        );

        $formatter = new PathResultFormatter();

        $expectedHuman = 'Total spent: USD 100.00; total received: EUR 95.00; total fees: EUR 0.50, USD 1.00; residual tolerance: 2.50%.'.PHP_EOL.
            'Legs:'.PHP_EOL.
            '  1. USD -> BTC | Spent USD 100.00 | Received BTC 0.01000000 | Fees USD 1.00'.PHP_EOL.
            '  2. BTC -> EUR | Spent BTC 0.01000000 | Received EUR 95.00 | Fees EUR 0.50';

        $this->assertSame($expectedHuman, $formatter->formatHuman($result));
    }

    public function test_formatting_with_empty_legs(): void
    {
        $result = new PathResult(
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '95', 2),
            DecimalTolerance::fromNumericString('0.025'),
            null, // Empty legs
            MoneyMap::fromAssociative(['USD' => Money::fromString('USD', '1.50', 2)]),
        );

        $formatter = new PathResultFormatter();

        $expectedHuman = 'Total spent: USD 100.00; total received: EUR 95.00; total fees: USD 1.50; residual tolerance: 2.50%.'.PHP_EOL.
            'Legs:';

        $this->assertSame($expectedHuman, $formatter->formatHuman($result));
    }

    public function test_formatting_with_zero_amounts(): void
    {
        $leg = new PathLeg(
            'usd',
            'eur',
            Money::fromString('USD', '0', 2),
            Money::fromString('EUR', '0', 2),
        );

        $result = new PathResult(
            Money::fromString('USD', '0', 2),
            Money::fromString('EUR', '0', 2),
            DecimalTolerance::fromNumericString('0.0'),
            PathLegCollection::fromList([$leg]),
            MoneyMap::empty(),
        );

        $formatter = new PathResultFormatter();

        $expectedHuman = 'Total spent: USD 0.00; total received: EUR 0.00; total fees: none; residual tolerance: 0.00%.'.PHP_EOL.
            'Legs:'.PHP_EOL.
            '  1. USD -> EUR | Spent USD 0.00 | Received EUR 0.00 | Fees none';

        $this->assertSame($expectedHuman, $formatter->formatHuman($result));
    }

    public function test_formatting_with_large_amounts(): void
    {
        $leg = new PathLeg(
            'btc',
            'usd',
            Money::fromString('BTC', '1000.12345678', 8),
            Money::fromString('USD', '50000000.99', 2),
            MoneyMap::fromList([Money::fromString('BTC', '0.001', 8)], true),
        );

        $result = new PathResult(
            Money::fromString('BTC', '1000.12345678', 8),
            Money::fromString('USD', '50000000.99', 2),
            DecimalTolerance::fromNumericString('0.001'),
            PathLegCollection::fromList([$leg]),
            MoneyMap::fromAssociative(['BTC' => Money::fromString('BTC', '0.001', 8)]),
        );

        $formatter = new PathResultFormatter();

        $expectedHuman = 'Total spent: BTC 1000.12345678; total received: USD 50000000.99; total fees: BTC 0.00100000; residual tolerance: 0.10%.'.PHP_EOL.
            'Legs:'.PHP_EOL.
            '  1. BTC -> USD | Spent BTC 1000.12345678 | Received USD 50000000.99 | Fees BTC 0.00100000';

        $this->assertSame($expectedHuman, $formatter->formatHuman($result));
    }

    public function test_formatting_with_very_small_amounts(): void
    {
        $leg = new PathLeg(
            'btc',
            'sat',
            Money::fromString('BTC', '0.00000001', 8),
            Money::fromString('SAT', '1000', 0),
        );

        $result = new PathResult(
            Money::fromString('BTC', '0.00000001', 8),
            Money::fromString('SAT', '1000', 0),
            DecimalTolerance::fromNumericString('0.0001'),
            PathLegCollection::fromList([$leg]),
            MoneyMap::empty(),
        );

        $formatter = new PathResultFormatter();

        $expectedHuman = 'Total spent: BTC 0.00000001; total received: SAT 1000; total fees: none; residual tolerance: 0.01%.'.PHP_EOL.
            'Legs:'.PHP_EOL.
            '  1. BTC -> SAT | Spent BTC 0.00000001 | Received SAT 1000 | Fees none';

        $this->assertSame($expectedHuman, $formatter->formatHuman($result));
    }

    public function test_formatting_with_multiple_fees_per_leg(): void
    {
        $leg = new PathLeg(
            'usd',
            'eur',
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '95', 2),
            MoneyMap::fromList([
                Money::fromString('USD', '1.00', 2),
                Money::fromString('EUR', '0.50', 2),
                Money::fromString('BTC', '0.0001', 8),
            ], true),
        );

        $result = new PathResult(
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '95', 2),
            DecimalTolerance::fromNumericString('0.025'),
            PathLegCollection::fromList([$leg]),
            MoneyMap::fromAssociative([
                'USD' => Money::fromString('USD', '1.00', 2),
                'EUR' => Money::fromString('EUR', '0.50', 2),
                'BTC' => Money::fromString('BTC', '0.0001', 8),
            ]),
        );

        $formatter = new PathResultFormatter();

        $expectedHuman = 'Total spent: USD 100.00; total received: EUR 95.00; total fees: BTC 0.00010000, EUR 0.50, USD 1.00; residual tolerance: 2.50%.'.PHP_EOL.
            'Legs:'.PHP_EOL.
            '  1. USD -> EUR | Spent USD 100.00 | Received EUR 95.00 | Fees BTC 0.00010000, EUR 0.50, USD 1.00';

        $this->assertSame($expectedHuman, $formatter->formatHuman($result));
    }

    public function test_format_human_collection_with_multiple_paths(): void
    {
        $leg1 = new PathLeg(
            'usd',
            'eur',
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '95', 2),
        );

        $result1 = new PathResult(
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '95', 2),
            DecimalTolerance::fromNumericString('0.025'),
            PathLegCollection::fromList([$leg1]),
            MoneyMap::empty(),
        );

        $leg2 = new PathLeg(
            'usd',
            'btc',
            Money::fromString('USD', '100', 2),
            Money::fromString('BTC', '0.01', 8),
        );

        $result2 = new PathResult(
            Money::fromString('USD', '100', 2),
            Money::fromString('BTC', '0.01', 8),
            DecimalTolerance::fromNumericString('0.01'),
            PathLegCollection::fromList([$leg2]),
            MoneyMap::empty(),
        );

        $collection = PathResultSet::fromPaths(
            new CostHopsSignatureOrderingStrategy(18),
            [$result1, $result2],
            static fn (PathResult $path, int $index): PathOrderKey => new PathOrderKey(
                new PathCost('0.1'),
                1,
                RouteSignature::fromNodes(['USD', 'DST'.$index]),
                $index
            ),
        );

        $formatter = new PathResultFormatter();

        $expected = 'Path 1:'.PHP_EOL.
            'Total spent: USD 100.00; total received: EUR 95.00; total fees: none; residual tolerance: 2.50%.'.PHP_EOL.
            'Legs:'.PHP_EOL.
            '  1. USD -> EUR | Spent USD 100.00 | Received EUR 95.00 | Fees none'.PHP_EOL.PHP_EOL.
            'Path 2:'.PHP_EOL.
            'Total spent: USD 100.00; total received: BTC 0.01000000; total fees: none; residual tolerance: 1.00%.'.PHP_EOL.
            'Legs:'.PHP_EOL.
            '  1. USD -> BTC | Spent USD 100.00 | Received BTC 0.01000000 | Fees none';

        $this->assertSame($expected, $formatter->formatHumanCollection($collection));
    }
}
