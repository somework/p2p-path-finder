<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathLeg;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathLegCollection;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResult;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

#[CoversClass(PathResult::class)]
final class PathResultTest extends TestCase
{
    public function test_json_serialization(): void
    {
        $firstLeg = new PathLeg(
            'usd',
            'btc',
            Money::fromString('USD', '60', 2),
            Money::fromString('BTC', '0.002', 6),
            MoneyMap::fromList([Money::fromString('USD', '0.30', 2)], true),
        );

        $secondLeg = new PathLeg(
            'btc',
            'eur',
            Money::fromString('BTC', '0.002', 6),
            Money::fromString('EUR', '55', 2),
            MoneyMap::fromList([Money::fromString('EUR', '0.10', 2)], true),
        );

        $result = new PathResult(
            Money::fromString('USD', '60', 2),
            Money::fromString('EUR', '55', 2),
            DecimalTolerance::fromNumericString('0.05'),
            PathLegCollection::fromList([$firstLeg, $secondLeg]),
            MoneyMap::fromAssociative([
                'USD' => Money::fromString('USD', '0.30', 2),
                'EUR' => Money::fromString('EUR', '0.10', 2),
            ]),
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
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path legs must be provided as a list.');

        PathLegCollection::fromList(['first' => $leg]);
    }

    public function test_path_legs_must_contain_only_path_leg_instances(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Every path leg must be an instance of PathLeg.');

        PathLegCollection::fromList([
            new PathLeg(
                'usd',
                'btc',
                Money::fromString('USD', '10', 2),
                Money::fromString('BTC', '0.001', 6),
            ),
            'invalid-leg',
        ]);
    }

    public function test_fee_breakdown_merges_duplicate_currencies(): void
    {
        $leg = new PathLeg(
            'usd',
            'btc',
            Money::fromString('USD', '10', 2),
            Money::fromString('BTC', '0.001', 6),
        );

        $result = new PathResult(
            Money::fromString('USD', '10', 2),
            Money::fromString('BTC', '0.001', 6),
            DecimalTolerance::fromNumericString('0.1'),
            PathLegCollection::fromList([$leg]),
            MoneyMap::fromList([
                Money::fromString('USD', '0.30', 2),
                Money::fromString('USD', '0.200', 3),
                Money::fromString('EUR', '1', 2),
            ]),
        );

        $fees = $result->feeBreakdown();

        $this->assertCount(2, $fees);

        $usdFee = $fees->get('USD');
        $eurFee = $fees->get('EUR');

        self::assertNotNull($usdFee);
        self::assertNotNull($eurFee);

        $this->assertSame('0.500', $usdFee->amount());
        $this->assertSame(3, $usdFee->scale());
        $this->assertSame('1.00', $eurFee->amount());
        $this->assertSame(2, $eurFee->scale());

        $this->assertSame([
            'feeBreakdown' => [
                'EUR' => ['currency' => 'EUR', 'amount' => '1.00', 'scale' => 2],
                'USD' => ['currency' => 'USD', 'amount' => '0.500', 'scale' => 3],
            ],
        ], array_intersect_key($result->jsonSerialize(), ['feeBreakdown' => true]));
    }

    public function test_fee_breakdown_requires_money_instances(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money map entries must be instances of Money.');

        MoneyMap::fromList(['invalid-fee']);
    }

    public function test_empty_legs_and_fees_are_preserved(): void
    {
        $result = new PathResult(
            Money::fromString('USD', '0', 2),
            Money::fromString('EUR', '0', 2),
            DecimalTolerance::fromNumericString('0.0'),
        );

        $this->assertSame([], $result->legs()->all());
        $this->assertSame([], $result->feeBreakdown()->toArray());

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

    public function test_basic_construction_and_getters(): void
    {
        $spent = Money::fromString('USD', '100.50', 2);
        $received = Money::fromString('EUR', '85.25', 2);
        $tolerance = DecimalTolerance::fromNumericString('0.05');
        $fees = MoneyMap::fromList([Money::fromString('USD', '2.50', 2)], true);
        $legs = PathLegCollection::fromList([
            new PathLeg('usd', 'eur', $spent, $received, $fees),
        ]);

        $result = new PathResult($spent, $received, $tolerance, $legs, $fees);

        $this->assertSame($spent, $result->totalSpent());
        $this->assertSame($received, $result->totalReceived());
        $this->assertSame($tolerance, $result->residualTolerance());
        $this->assertSame($fees, $result->feeBreakdown());
        $this->assertSame($legs, $result->legs());
    }

    public function test_fee_breakdown_as_array_method(): void
    {
        $fees = MoneyMap::fromList([
            Money::fromString('USD', '10.00', 2),
            Money::fromString('EUR', '8.50', 2),
        ], true);

        $result = new PathResult(
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '85', 2),
            DecimalTolerance::fromNumericString('0.1'),
            null,
            $fees
        );

        $feesArray = $result->feeBreakdownAsArray();

        $this->assertIsArray($feesArray);
        $this->assertCount(2, $feesArray);
        $this->assertArrayHasKey('USD', $feesArray);
        $this->assertArrayHasKey('EUR', $feesArray);

        $this->assertSame('USD', $feesArray['USD']->currency());
        $this->assertSame('10.00', $feesArray['USD']->amount());
        $this->assertSame(2, $feesArray['USD']->scale());

        $this->assertSame('EUR', $feesArray['EUR']->currency());
        $this->assertSame('8.50', $feesArray['EUR']->amount());
        $this->assertSame(2, $feesArray['EUR']->scale());
    }

    public function test_residual_tolerance_percentage_method(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.123456789');

        $result = new PathResult(
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '85', 2),
            $tolerance
        );

        // Test default scale (2)
        $this->assertSame('12.35', $result->residualTolerancePercentage());

        // Test custom scale (4)
        $this->assertSame('12.3457', $result->residualTolerancePercentage(4));

        // Test higher scale (8)
        $this->assertSame('12.34567890', $result->residualTolerancePercentage(8));
    }

    public function test_legs_as_array_method(): void
    {
        $leg1 = new PathLeg(
            'usd',
            'btc',
            Money::fromString('USD', '100', 2),
            Money::fromString('BTC', '0.01', 8)
        );
        $leg2 = new PathLeg(
            'btc',
            'eth',
            Money::fromString('BTC', '0.01', 8),
            Money::fromString('ETH', '15', 2)
        );

        $legs = PathLegCollection::fromList([$leg1, $leg2]);

        $result = new PathResult(
            Money::fromString('USD', '100', 2),
            Money::fromString('ETH', '15', 2),
            DecimalTolerance::fromNumericString('0.1'),
            $legs
        );

        $legsArray = $result->legsAsArray();

        $this->assertIsArray($legsArray);
        $this->assertCount(2, $legsArray);
        $this->assertSame($leg1, $legsArray[0]);
        $this->assertSame($leg2, $legsArray[1]);
    }

    public function test_to_array_method(): void
    {
        $spent = Money::fromString('USD', '100.50', 2);
        $received = Money::fromString('EUR', '85.25', 2);
        $tolerance = DecimalTolerance::fromNumericString('0.05');
        $fees = MoneyMap::fromList([Money::fromString('USD', '2.50', 2)], true);
        $legs = PathLegCollection::fromList([
            new PathLeg('usd', 'eur', $spent, $received, $fees),
        ]);

        $result = new PathResult($spent, $received, $tolerance, $legs, $fees);

        $array = $result->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('totalSpent', $array);
        $this->assertArrayHasKey('totalReceived', $array);
        $this->assertArrayHasKey('residualTolerance', $array);
        $this->assertArrayHasKey('feeBreakdown', $array);
        $this->assertArrayHasKey('legs', $array);

        $this->assertSame($spent, $array['totalSpent']);
        $this->assertSame($received, $array['totalReceived']);
        $this->assertSame($tolerance, $array['residualTolerance']);
        $this->assertSame($fees, $array['feeBreakdown']);
        $this->assertSame($legs, $array['legs']);
    }

    public function test_construction_with_default_null_parameters(): void
    {
        $spent = Money::fromString('USD', '100', 2);
        $received = Money::fromString('EUR', '85', 2);
        $tolerance = DecimalTolerance::fromNumericString('0.1');

        $result = new PathResult($spent, $received, $tolerance);

        $this->assertInstanceOf(PathLegCollection::class, $result->legs());
        $this->assertTrue($result->legs()->isEmpty());
        $this->assertInstanceOf(MoneyMap::class, $result->feeBreakdown());
        $this->assertTrue($result->feeBreakdown()->isEmpty());
    }

    public function test_zero_tolerance_edge_cases(): void
    {
        $result = new PathResult(
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '85', 2),
            DecimalTolerance::fromNumericString('0.0')
        );

        $this->assertSame('0.00', $result->residualTolerancePercentage());
        $this->assertSame('0.0000', $result->residualTolerancePercentage(4));
        $this->assertSame('0.00000000', $result->residualTolerancePercentage(8));
    }

    public function test_large_money_amounts_with_different_scales(): void
    {
        $spent = Money::fromString('BTC', '1000.00000000', 8);
        $received = Money::fromString('ETH', '50000.000', 3);

        $result = new PathResult(
            $spent,
            $received,
            DecimalTolerance::fromNumericString('0.5')
        );

        $this->assertSame(8, $result->totalSpent()->scale());
        $this->assertSame(3, $result->totalReceived()->scale());
        $this->assertSame('1000.00000000', $result->totalSpent()->amount());
        $this->assertSame('50000.000', $result->totalReceived()->amount());
    }

    public function test_very_small_money_amounts(): void
    {
        $spent = Money::fromString('BTC', '0.00000001', 8);
        $received = Money::fromString('ETH', '0.001', 3);

        $result = new PathResult(
            $spent,
            $received,
            DecimalTolerance::fromNumericString('0.001')
        );

        $this->assertSame('0.00000001', $result->totalSpent()->amount());
        $this->assertSame('0.001', $result->totalReceived()->amount());
        $this->assertSame('0.10', $result->residualTolerancePercentage());
    }

    public function test_residual_tolerance_percentage_with_precision_loss(): void
    {
        // Test tolerance that would lose precision when scaled
        $tolerance = DecimalTolerance::fromNumericString('0.123456789123456789');

        $result = new PathResult(
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '85', 2),
            $tolerance
        );

        // Should round appropriately for each scale
        $this->assertSame('12.35', $result->residualTolerancePercentage(2));
        $this->assertSame('12.3457', $result->residualTolerancePercentage(4));
        $this->assertSame('12.34567891', $result->residualTolerancePercentage(8));
    }

    public function test_json_serialization_with_minimal_data(): void
    {
        $result = new PathResult(
            Money::fromString('USD', '100', 2),
            Money::fromString('EUR', '85', 2),
            DecimalTolerance::fromNumericString('0.1')
        );

        $serialized = $result->jsonSerialize();

        $this->assertSame('USD', $serialized['totalSpent']['currency']);
        $this->assertSame('100.00', $serialized['totalSpent']['amount']);
        $this->assertSame(2, $serialized['totalSpent']['scale']);

        $this->assertSame('EUR', $serialized['totalReceived']['currency']);
        $this->assertSame('85.00', $serialized['totalReceived']['amount']);
        $this->assertSame(2, $serialized['totalReceived']['scale']);

        $this->assertSame('0.100000000000000000', $serialized['residualTolerance']);
        $this->assertSame([], $serialized['feeBreakdown']);
        $this->assertSame([], $serialized['legs']);
    }
}
