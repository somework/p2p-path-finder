<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Result;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Result\PathLeg;
use SomeWork\P2PPathFinder\Application\Result\PathResult;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalTolerance;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

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
            DecimalTolerance::fromNumericString('0.05'),
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
                'residualTolerance' => '0.050000000000000000',
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

    public function test_residual_tolerance_cannot_be_negative(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Residual tolerance must be a value between 0 and 1 inclusive.');

        new PathResult(
            Money::fromString('USD', '10', 2),
            Money::fromString('EUR', '9', 2),
            DecimalTolerance::fromNumericString('-0.01'),
        );
    }

    public function test_residual_tolerance_cannot_exceed_one(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Residual tolerance must be a value between 0 and 1 inclusive.');

        new PathResult(
            Money::fromString('USD', '10', 2),
            Money::fromString('EUR', '9', 2),
            DecimalTolerance::fromNumericString('1.01'),
        );
    }

    public function test_path_legs_must_be_a_list(): void
    {
        $leg = new PathLeg(
            'usd',
            'btc',
            Money::fromString('USD', '10', 2),
            Money::fromString('BTC', '0.001', 6),
            [],
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path legs must be provided as a list.');

        new PathResult(
            Money::fromString('USD', '10', 2),
            Money::fromString('BTC', '0.001', 6),
            DecimalTolerance::fromNumericString('0.1'),
            ['first' => $leg],
        );
    }

    public function test_fee_breakdown_merges_duplicate_currencies(): void
    {
        $leg = new PathLeg(
            'usd',
            'btc',
            Money::fromString('USD', '10', 2),
            Money::fromString('BTC', '0.001', 6),
            [],
        );

        $result = new PathResult(
            Money::fromString('USD', '10', 2),
            Money::fromString('BTC', '0.001', 6),
            DecimalTolerance::fromNumericString('0.1'),
            [$leg],
            [
                Money::fromString('USD', '0.30', 2),
                Money::fromString('USD', '0.200', 3),
                Money::fromString('EUR', '1', 2),
            ],
        );

        $fees = $result->feeBreakdown();

        $this->assertCount(2, $fees);
        $this->assertSame('0.500', $fees['USD']->amount());
        $this->assertSame(3, $fees['USD']->scale());
        $this->assertSame('1.00', $fees['EUR']->amount());
        $this->assertSame(2, $fees['EUR']->scale());

        $this->assertSame([
            'feeBreakdown' => [
                'EUR' => ['currency' => 'EUR', 'amount' => '1.00', 'scale' => 2],
                'USD' => ['currency' => 'USD', 'amount' => '0.500', 'scale' => 3],
            ],
        ], array_intersect_key($result->jsonSerialize(), ['feeBreakdown' => true]));
    }

    public function test_empty_legs_and_fees_are_preserved(): void
    {
        $result = new PathResult(
            Money::fromString('USD', '0', 2),
            Money::fromString('EUR', '0', 2),
            DecimalTolerance::fromNumericString('0.0'),
        );

        $this->assertSame([], $result->legs());
        $this->assertSame([], $result->feeBreakdown());

        self::assertSame('0.00', $result->residualTolerancePercentage());
        self::assertSame('0.0000', $result->residualTolerancePercentage(4));

        $this->assertSame([
            'totalSpent' => ['currency' => 'USD', 'amount' => '0.00', 'scale' => 2],
            'totalReceived' => ['currency' => 'EUR', 'amount' => '0.00', 'scale' => 2],
            'residualTolerance' => '0.000000000000000000',
            'feeBreakdown' => [],
            'legs' => [],
        ], $result->jsonSerialize());
    }
}
