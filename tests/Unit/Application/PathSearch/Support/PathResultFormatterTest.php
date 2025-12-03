<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\Path;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHop;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHopCollection;
use SomeWork\P2PPathFinder\Application\PathSearch\Support\PathResultFormatter;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

#[CoversClass(PathResultFormatter::class)]
final class PathResultFormatterTest extends TestCase
{
    public function test_it_formats_single_path(): void
    {
        $hop = new PathHop(
            'USD',
            'EUR',
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '90', 2),
            OrderFactory::sell('USD', 'EUR'),
        );

        $path = new Path(PathHopCollection::fromList([$hop]), DecimalTolerance::fromNumericString('0.1'));
        $formatter = new PathResultFormatter();

        $formatted = $formatter->formatHuman($path);

        self::assertStringContainsString('Total spent: USD 100.00', $formatted);
        self::assertStringContainsString('Hops:', $formatted);
        self::assertStringContainsString('1. USD -> EUR', $formatted);
    }

    public function test_it_formats_collection(): void
    {
        $formatter = new PathResultFormatter();
        $order = OrderFactory::sell('USD', 'EUR');

        $first = new Path(
            PathHopCollection::fromList([
                new PathHop(
                    'USD',
                    'EUR',
                    Money::fromString('USD', '10', 2),
                    Money::fromString('EUR', '9', 2),
                    $order,
                ),
            ]),
            DecimalTolerance::fromNumericString('0.0'),
        );

        $second = new Path(
            PathHopCollection::fromList([
                new PathHop(
                    'USD',
                    'GBP',
                    Money::fromString('USD', '20', 2),
                    Money::fromString('GBP', '15', 2),
                    $order,
                ),
            ]),
            DecimalTolerance::fromNumericString('0.0'),
        );

        $formatted = $formatter->formatHumanCollection([$first, $second]);

        self::assertStringContainsString('Path 1:', $formatted);
        self::assertStringContainsString('Path 2:', $formatted);
    }
}
