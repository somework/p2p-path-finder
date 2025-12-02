<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Api\Request;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class PathSearchRequestTest extends TestCase
{
    public function test_it_normalizes_target_asset_whitespace(): void
    {
        $request = new PathSearchRequest(
            new OrderBook([]),
            $this->minimalConfig(),
            '  usdT  ',
        );

        self::assertSame('USDT', $request->targetAsset());
    }

    public function test_it_rejects_empty_target_asset_after_trimming(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Target asset cannot be empty.');

        new PathSearchRequest(
            new OrderBook([]),
            $this->minimalConfig(),
            "  \t\n  ",
        );
    }

    private function minimalConfig(): PathSearchConfig
    {
        return PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('SRC', '1', 0))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 1)
            ->build();
    }
}
