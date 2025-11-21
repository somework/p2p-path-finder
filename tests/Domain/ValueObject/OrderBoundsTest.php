<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class OrderBoundsTest extends TestCase
{
    use MoneyAssertions;

    public function test_contains_checks_boundaries_inclusively(): void
    {
        $min = $this->money('USD', '10.00', 2);
        $max = $this->money('USD', '20.00', 2);

        $bounds = OrderBounds::from($min, $max);

        self::assertTrue($bounds->contains($this->money('USD', '10.00', 2)));
        self::assertTrue($bounds->contains($this->money('USD', '15.00', 2)));
        self::assertTrue($bounds->contains($this->money('USD', '20.00', 2)));
        self::assertFalse($bounds->contains($this->money('USD', '9.99', 2)));
        self::assertFalse($bounds->contains($this->money('USD', '20.01', 2)));
    }

    public function test_clamp_returns_nearest_bound(): void
    {
        $bounds = OrderBounds::from($this->money('EUR', '1.000', 3), $this->money('EUR', '2.000', 3));

        self::assertMoneyAmount($bounds->clamp($this->money('EUR', '0.500', 3)), '1.000', 3);
        self::assertMoneyAmount($bounds->clamp($this->money('EUR', '1.500', 3)), '1.500', 3);
        self::assertMoneyAmount($bounds->clamp($this->money('EUR', '5.000', 3)), '2.000', 3);
    }

    public function test_creation_with_inverted_bounds_fails(): void
    {
        $this->expectException(InvalidInput::class);
        OrderBounds::from($this->money('GBP', '5.00'), $this->money('GBP', '2.00'));
    }

    public function test_creation_with_currency_mismatch_fails(): void
    {
        $this->expectException(InvalidInput::class);
        OrderBounds::from($this->money('USD', '1.00'), $this->money('EUR', '2.00'));
    }

    public function test_contains_rejects_mismatched_currency(): void
    {
        $bounds = OrderBounds::from($this->money('USD', '10.00', 2), $this->money('USD', '20.00', 2));

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money currency must match order bounds.');

        $bounds->contains($this->money('EUR', '15.00', 2));
    }

    public function test_equal_bounds_creates_single_point(): void
    {
        $amount = $this->money('USD', '10.00', 2);
        $bounds = OrderBounds::from($amount, $amount);

        self::assertMoneyAmount($bounds->min(), '10.00', 2);
        self::assertMoneyAmount($bounds->max(), '10.00', 2);
        self::assertTrue($bounds->contains($this->money('USD', '10.00', 2)));
        self::assertFalse($bounds->contains($this->money('USD', '10.01', 2)));
        self::assertFalse($bounds->contains($this->money('USD', '9.99', 2)));
    }

    public function test_zero_minimum_is_valid(): void
    {
        $bounds = OrderBounds::from($this->money('BTC', '0.00000000', 8), $this->money('BTC', '1.00000000', 8));

        self::assertMoneyAmount($bounds->min(), '0.00000000', 8);
        self::assertMoneyAmount($bounds->max(), '1.00000000', 8);
        self::assertTrue($bounds->contains($this->money('BTC', '0.00000000', 8)));
        self::assertTrue($bounds->contains($this->money('BTC', '0.50000000', 8)));
    }

    public function test_different_scales_normalized_to_max(): void
    {
        $min = $this->money('EUR', '10.0', 1);
        $max = $this->money('EUR', '20.000', 3);

        $bounds = OrderBounds::from($min, $max);

        // Both should be normalized to scale 3
        self::assertMoneyAmount($bounds->min(), '10.000', 3);
        self::assertMoneyAmount($bounds->max(), '20.000', 3);
    }

    public function test_contains_with_different_scale(): void
    {
        $bounds = OrderBounds::from($this->money('USD', '10.00', 2), $this->money('USD', '20.00', 2));

        // Test value with different scale should work
        self::assertTrue($bounds->contains($this->money('USD', '15', 0)));
        self::assertTrue($bounds->contains($this->money('USD', '15.0000', 4)));
        self::assertFalse($bounds->contains($this->money('USD', '25.00000', 5)));
    }

    public function test_min_and_max_accessors_return_money(): void
    {
        $min = $this->money('GBP', '5.00', 2);
        $max = $this->money('GBP', '10.00', 2);
        $bounds = OrderBounds::from($min, $max);

        self::assertMoneyAmount($bounds->min(), '5.00', 2);
        self::assertSame('GBP', $bounds->min()->currency());
        self::assertMoneyAmount($bounds->max(), '10.00', 2);
        self::assertSame('GBP', $bounds->max()->currency());
    }

    public function test_clamp_at_boundaries(): void
    {
        $bounds = OrderBounds::from($this->money('USD', '10.00', 2), $this->money('USD', '20.00', 2));

        // Exactly at boundaries should return same value
        self::assertMoneyAmount($bounds->clamp($this->money('USD', '10.00', 2)), '10.00', 2);
        self::assertMoneyAmount($bounds->clamp($this->money('USD', '20.00', 2)), '20.00', 2);
    }

    public function test_clamp_rejects_mismatched_currency(): void
    {
        $bounds = OrderBounds::from($this->money('USD', '10.00', 2), $this->money('USD', '20.00', 2));

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money currency must match order bounds.');

        $bounds->clamp($this->money('EUR', '15.00', 2));
    }

    public function test_clamp_with_different_scale(): void
    {
        $bounds = OrderBounds::from($this->money('USD', '10.00', 2), $this->money('USD', '20.00', 2));

        // Clamp should normalize scale
        self::assertMoneyAmount($bounds->clamp($this->money('USD', '5', 0)), '10.00', 2);
        self::assertMoneyAmount($bounds->clamp($this->money('USD', '15.0000', 4)), '15.00', 2);
        self::assertMoneyAmount($bounds->clamp($this->money('USD', '25.000', 3)), '20.00', 2);
    }

    public function test_large_amounts(): void
    {
        $bounds = OrderBounds::from(
            $this->money('USD', '1000000.00', 2),
            $this->money('USD', '9999999.99', 2)
        );

        self::assertTrue($bounds->contains($this->money('USD', '5000000.00', 2)));
        self::assertFalse($bounds->contains($this->money('USD', '999999.99', 2)));
        self::assertFalse($bounds->contains($this->money('USD', '10000000.00', 2)));
    }

    public function test_very_small_amounts(): void
    {
        $bounds = OrderBounds::from(
            $this->money('BTC', '0.00000001', 8),
            $this->money('BTC', '0.00000010', 8)
        );

        self::assertTrue($bounds->contains($this->money('BTC', '0.00000001', 8)));
        self::assertTrue($bounds->contains($this->money('BTC', '0.00000005', 8)));
        self::assertTrue($bounds->contains($this->money('BTC', '0.00000010', 8)));
        self::assertFalse($bounds->contains($this->money('BTC', '0.00000011', 8)));
    }

    public function test_immutability_of_bounds(): void
    {
        $min = $this->money('USD', '10.00', 2);
        $max = $this->money('USD', '20.00', 2);
        $bounds = OrderBounds::from($min, $max);

        // Get bounds and verify they are Money objects
        $retrievedMin = $bounds->min();
        $retrievedMax = $bounds->max();

        self::assertMoneyAmount($retrievedMin, '10.00', 2);
        self::assertMoneyAmount($retrievedMax, '20.00', 2);

        // Creating operations with bounds should not affect original
        $bounds->contains($this->money('USD', '15.00', 2));
        $bounds->clamp($this->money('USD', '5.00', 2));

        self::assertMoneyAmount($bounds->min(), '10.00', 2);
        self::assertMoneyAmount($bounds->max(), '20.00', 2);
    }

    public function test_precision_at_boundaries(): void
    {
        $bounds = OrderBounds::from($this->money('USD', '10.00', 2), $this->money('USD', '20.00', 2));

        // Just below minimum
        self::assertFalse($bounds->contains($this->money('USD', '9.99', 2)));
        // At minimum
        self::assertTrue($bounds->contains($this->money('USD', '10.00', 2)));
        // Just above minimum
        self::assertTrue($bounds->contains($this->money('USD', '10.01', 2)));

        // Just below maximum
        self::assertTrue($bounds->contains($this->money('USD', '19.99', 2)));
        // At maximum
        self::assertTrue($bounds->contains($this->money('USD', '20.00', 2)));
        // Just above maximum
        self::assertFalse($bounds->contains($this->money('USD', '20.01', 2)));
    }

    public function test_scale_preservation_in_clamp(): void
    {
        $bounds = OrderBounds::from($this->money('EUR', '10.00', 2), $this->money('EUR', '20.00', 2));

        // When clamping, the result should have the bounds' scale
        $clamped = $bounds->clamp($this->money('EUR', '5.0', 1));
        self::assertMoneyAmount($clamped, '10.00', 2);

        $clamped2 = $bounds->clamp($this->money('EUR', '15.00000', 5));
        self::assertMoneyAmount($clamped2, '15.00', 2);
    }
}
