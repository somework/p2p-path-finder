<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Result;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Result\MoneyMap;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class MoneyMapTest extends TestCase
{
    public function test_it_allows_access_by_currency_code(): void
    {
        $map = MoneyMap::fromList([Money::fromString('USD', '1', 2)]);

        self::assertTrue(isset($map['USD']));
        self::assertSame('USD', $map['USD']->currency());
    }

    public function test_it_rejects_non_string_offsets(): void
    {
        $map = MoneyMap::fromList([Money::fromString('USD', '1', 2)]);

        self::assertFalse(isset($map[0]));

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money map index must be a known currency code.');

        $map[0];
    }
}
