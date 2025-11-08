<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Result;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Result\MoneyMap;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

final class MoneyMapTest extends TestCase
{
    public function test_it_allows_access_by_currency_code(): void
    {
        $map = MoneyMap::fromList([Money::fromString('USD', '1', 2)]);

        self::assertTrue($map->has('USD'));
        $usd = $map->get('USD');
        self::assertNotNull($usd);
        self::assertSame('USD', $usd->currency());
    }

    public function test_it_returns_null_for_unknown_currency(): void
    {
        $map = MoneyMap::fromList([Money::fromString('USD', '1', 2)]);

        self::assertFalse($map->has('EUR'));
        self::assertNull($map->get('EUR'));
    }
}
