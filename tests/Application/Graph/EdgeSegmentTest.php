<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Graph;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

final class EdgeSegmentTest extends TestCase
{
    public function test_json_serialization_exposes_all_capacity_ranges(): void
    {
        $segment = new EdgeSegment(
            true,
            new EdgeCapacity(
                Money::fromString('USD', '1.00', 2),
                Money::fromString('USD', '2.00', 2),
            ),
            new EdgeCapacity(
                Money::fromString('EUR', '0.90', 2),
                Money::fromString('EUR', '1.80', 2),
            ),
            new EdgeCapacity(
                Money::fromString('USD', '1.10', 2),
                Money::fromString('USD', '2.20', 2),
            ),
        );

        self::assertSame(
            [
                'isMandatory' => true,
                'base' => ['min' => ['currency' => 'USD', 'amount' => '1.00', 'scale' => 2], 'max' => ['currency' => 'USD', 'amount' => '2.00', 'scale' => 2]],
                'quote' => ['min' => ['currency' => 'EUR', 'amount' => '0.90', 'scale' => 2], 'max' => ['currency' => 'EUR', 'amount' => '1.80', 'scale' => 2]],
                'grossBase' => ['min' => ['currency' => 'USD', 'amount' => '1.10', 'scale' => 2], 'max' => ['currency' => 'USD', 'amount' => '2.20', 'scale' => 2]],
            ],
            $segment->jsonSerialize(),
        );
    }
}
