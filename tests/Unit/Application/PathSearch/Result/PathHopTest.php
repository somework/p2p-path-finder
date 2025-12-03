<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHop;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

#[CoversClass(PathHop::class)]
final class PathHopTest extends TestCase
{
    public function test_it_normalizes_assets_and_exposes_values(): void
    {
        $spent = Money::fromString('USD', '10', 2);
        $received = Money::fromString('BTC', '0.00100000', 8);
        $order = OrderFactory::sell('USD', 'BTC');
        $fees = MoneyMap::fromList([
            Money::fromString('USD', '0.10', 2),
        ]);

        $hop = new PathHop('usd', 'btc', $spent, $received, $order, $fees);

        self::assertSame('USD', $hop->from());
        self::assertSame('BTC', $hop->to());
        self::assertSame($spent, $hop->spent());
        self::assertSame($received, $hop->received());
        self::assertSame($fees, $hop->fees());
        self::assertSame($order, $hop->order());
        self::assertSame(['USD' => $fees->get('USD')], $hop->feesAsArray());

        $array = $hop->toArray();
        self::assertSame($order, $array['order']);
        self::assertSame($fees, $array['fees']);
    }

    public function test_it_defaults_to_empty_fees(): void
    {
        $hop = new PathHop(
            'eth',
            'usdt',
            Money::fromString('ETH', '1', 0),
            Money::fromString('USDT', '2000', 0),
            OrderFactory::sell('ETH', 'USDT'),
        );

        self::assertTrue($hop->fees()->isEmpty());
        self::assertSame([], $hop->feesAsArray());
    }

    public function test_money_currency_must_match_assets(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path hop spent currency must match the from asset.');

        new PathHop(
            'usd',
            'btc',
            Money::fromString('EUR', '10', 2),
            Money::fromString('BTC', '0.001', 6),
            OrderFactory::sell('USD', 'BTC'),
        );
    }

    public function test_assets_cannot_be_empty(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path hop from asset cannot be empty.');

        new PathHop(
            ' ',
            'btc',
            Money::fromString('USD', '10', 2),
            Money::fromString('BTC', '0.001', 6),
            OrderFactory::sell('USD', 'BTC'),
        );
    }
}
