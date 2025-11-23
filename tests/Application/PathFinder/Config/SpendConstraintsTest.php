<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Config;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class SpendConstraintsTest extends TestCase
{
    public function test_from_scalars_normalizes_scale_and_currency(): void
    {
        $constraints = SpendConstraints::fromScalars(
            'usd',
            '1.2345678901234567894',
            '5.6789',
            '3.4567',
        );

        self::assertSame('USD', $constraints->min()->currency());
        self::assertSame(18, $constraints->min()->scale());
        self::assertSame('1.234567890123456789', $constraints->min()->amount());
        self::assertSame('5.678900000000000000', $constraints->max()->amount());
        self::assertSame('3.456700000000000000', $constraints->desired()?->amount());
    }

    public function test_from_scalars_applies_half_up_rounding(): void
    {
        $constraints = SpendConstraints::fromScalars(
            'EUR',
            '1.0000000000000000005',
            '2',
        );

        self::assertSame('1.000000000000000001', $constraints->min()->amount());
        self::assertSame('2.000000000000000000', $constraints->max()->amount());
    }

    public function test_from_scalars_requires_currency(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Currency cannot be empty.');

        SpendConstraints::fromScalars('', '1', '2');
    }

    public function test_from_scalars_rejects_negative_bounds(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money amount cannot be negative');

        SpendConstraints::fromScalars('USD', '-1', '2');
    }
}
