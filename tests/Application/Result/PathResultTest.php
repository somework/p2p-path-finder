<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Result;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Result\PathLeg;
use SomeWork\P2PPathFinder\Application\Result\PathResult;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

final class PathResultTest extends TestCase
{
    public function test_json_serialization(): void
    {
        $firstLeg = new PathLeg(
            'usd',
            'btc',
            Money::fromString('USD', '60', 2),
            Money::fromString('BTC', '0.002', 6),
            [Money::fromString('USD', '0.30', 2)],
        );

        $secondLeg = new PathLeg(
            'btc',
            'eur',
            Money::fromString('BTC', '0.002', 6),
            Money::fromString('EUR', '55', 2),
            [Money::fromString('EUR', '0.10', 2)],
        );

        $result = new PathResult(
            Money::fromString('USD', '60', 2),
            Money::fromString('EUR', '55', 2),
            0.05,
            [$firstLeg, $secondLeg],
            [
                'USD' => Money::fromString('USD', '0.30', 2),
                'EUR' => Money::fromString('EUR', '0.10', 2),
            ],
        );

        $this->assertSame(
            [
                'totalSpent' => ['currency' => 'USD', 'amount' => '60.00', 'scale' => 2],
                'totalReceived' => ['currency' => 'EUR', 'amount' => '55.00', 'scale' => 2],
                'residualTolerance' => 0.05,
                'feeBreakdown' => [
                    'EUR' => ['currency' => 'EUR', 'amount' => '0.10', 'scale' => 2],
                    'USD' => ['currency' => 'USD', 'amount' => '0.30', 'scale' => 2],
                ],
                'legs' => [
                    [
                        'from' => 'USD',
                        'to' => 'BTC',
                        'spent' => ['currency' => 'USD', 'amount' => '60.00', 'scale' => 2],
                        'received' => ['currency' => 'BTC', 'amount' => '0.002000', 'scale' => 6],
                        'fees' => [
                            'USD' => ['currency' => 'USD', 'amount' => '0.30', 'scale' => 2],
                        ],
                    ],
                    [
                        'from' => 'BTC',
                        'to' => 'EUR',
                        'spent' => ['currency' => 'BTC', 'amount' => '0.002000', 'scale' => 6],
                        'received' => ['currency' => 'EUR', 'amount' => '55.00', 'scale' => 2],
                        'fees' => [
                            'EUR' => ['currency' => 'EUR', 'amount' => '0.10', 'scale' => 2],
                        ],
                    ],
                ],
            ],
            $result->jsonSerialize(),
        );
    }
}
