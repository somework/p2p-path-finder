<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Result;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Result\PathLeg;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

final class PathLegTest extends TestCase
{
    public function test_json_serialization(): void
    {
        $leg = new PathLeg(
            'usd',
            'eur',
            Money::fromString('USD', '50', 2),
            Money::fromString('EUR', '45', 2),
            Money::fromString('USD', '0.50', 2),
        );

        $this->assertSame(
            [
                'from' => 'USD',
                'to' => 'EUR',
                'spent' => ['currency' => 'USD', 'amount' => '50.00', 'scale' => 2],
                'received' => ['currency' => 'EUR', 'amount' => '45.00', 'scale' => 2],
                'fee' => ['currency' => 'USD', 'amount' => '0.50', 'scale' => 2],
            ],
            $leg->jsonSerialize(),
        );
    }
}
