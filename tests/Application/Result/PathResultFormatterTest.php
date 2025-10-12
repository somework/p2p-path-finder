<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Result;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Result\PathLeg;
use SomeWork\P2PPathFinder\Application\Result\PathResult;
use SomeWork\P2PPathFinder\Application\Result\PathResultFormatter;
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
            [Money::fromString('USD', '1.50', 2)],
        );

        $result = new PathResult(
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '95', 2),
            0.025,
            [$leg],
            ['USD' => Money::fromString('USD', '1.50', 2)],
        );

        $formatter = new PathResultFormatter();

        $this->assertSame($result->jsonSerialize(), $formatter->formatMachine($result));

        $expectedHuman = 'Total spent: USD 100.00; total received: EUR 95.00; total fees: USD 1.50; residual tolerance: 2.50%.'.PHP_EOL
            .'Legs:'.PHP_EOL
            .'  1. USD -> EUR | Spent USD 100.00 | Received EUR 95.00 | Fees USD 1.50';

        $this->assertSame($expectedHuman, $formatter->formatHuman($result));
    }
}
