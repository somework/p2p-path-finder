<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Math\BrickDecimalMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\MathAdapterFactory;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function sprintf;

final class OrderBoundsTest extends TestCase
{
    public function test_contains_checks_boundaries_inclusively(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $min = Money::fromString('USD', '10.00', 2, $math);
            $max = Money::fromString('USD', '20.00', 2, $math);

            $bounds = OrderBounds::from($min, $max);

            self::assertTrue($bounds->contains(Money::fromString('USD', '10.00', 2, $math)), $adapterName);
            self::assertTrue($bounds->contains(Money::fromString('USD', '15.00', 2, $math)), $adapterName);
            self::assertTrue($bounds->contains(Money::fromString('USD', '20.00', 2, $math)), $adapterName);
            self::assertFalse($bounds->contains(Money::fromString('USD', '9.99', 2, $math)), $adapterName);
            self::assertFalse($bounds->contains(Money::fromString('USD', '20.01', 2, $math)), $adapterName);
        }
    }

    public function test_clamp_returns_nearest_bound(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $bounds = OrderBounds::from(
                Money::fromString('EUR', '1.000', 3, $math),
                Money::fromString('EUR', '2.000', 3, $math),
            );

            self::assertSame('1.000', $bounds->clamp(Money::fromString('EUR', '0.500', 3, $math))->amount(), $adapterName);
            self::assertSame('1.500', $bounds->clamp(Money::fromString('EUR', '1.500', 3, $math))->amount(), $adapterName);
            self::assertSame('2.000', $bounds->clamp(Money::fromString('EUR', '5.000', 3, $math))->amount(), $adapterName);
        }
    }

    public function test_creation_with_inverted_bounds_fails(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $this->assertInvalidInput(
                static fn () => OrderBounds::from(
                    Money::fromString('GBP', '5.00', 2, $math),
                    Money::fromString('GBP', '2.00', 2, $math),
                ),
                $adapterName,
            );
        }
    }

    public function test_creation_with_currency_mismatch_fails(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $this->assertInvalidInput(
                static fn () => OrderBounds::from(
                    Money::fromString('USD', '1.00', 2, $math),
                    Money::fromString('EUR', '2.00', 2, $math),
                ),
                $adapterName,
            );
        }
    }

    public function test_contains_rejects_mismatched_currency(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $bounds = OrderBounds::from(
                Money::fromString('USD', '10.00', 2, $math),
                Money::fromString('USD', '20.00', 2, $math),
            );

            $this->assertInvalidInput(
                static fn () => $bounds->contains(Money::fromString('EUR', '15.00', 2, $math)),
                $adapterName,
            );
        }
    }

    /**
     * @return iterable<string, \SomeWork\P2PPathFinder\Domain\Math\DecimalMathInterface>
     */
    private static function mathAdapters(): iterable
    {
        yield 'bc-math' => MathAdapterFactory::default();
        yield 'brick-decimal' => new BrickDecimalMath();
    }

    /**
     * @param callable():void $callback
     */
    private function assertInvalidInput(callable $callback, string $adapterName): void
    {
        try {
            $callback();
            self::fail(sprintf('[%s] Expected InvalidInput exception to be thrown.', $adapterName));
        } catch (InvalidInput $exception) {
            self::assertInstanceOf(InvalidInput::class, $exception, $adapterName);
        }
    }
}
