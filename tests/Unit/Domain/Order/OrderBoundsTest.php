<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Domain\Order;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\Order\OrderBounds;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Helpers\MoneyAssertions;

#[CoversClass(OrderBounds::class)]
final class OrderBoundsTest extends TestCase
{
    use MoneyAssertions;

    public function test_contains_checks_boundaries_inclusively(): void
    {
        $min = self::money('USD', '10.00', 2);
        $max = self::money('USD', '20.00', 2);

        $bounds = OrderBounds::from($min, $max);

        self::assertTrue($bounds->contains(self::money('USD', '10.00', 2)));
        self::assertTrue($bounds->contains(self::money('USD', '15.00', 2)));
        self::assertTrue($bounds->contains(self::money('USD', '20.00', 2)));
        self::assertFalse($bounds->contains(self::money('USD', '9.99', 2)));
        self::assertFalse($bounds->contains(self::money('USD', '20.01', 2)));
    }

    public function test_clamp_returns_nearest_bound(): void
    {
        $bounds = OrderBounds::from(self::money('EUR', '1.000', 3), self::money('EUR', '2.000', 3));

        self::assertMoneyAmount($bounds->clamp(self::money('EUR', '0.500', 3)), '1.000', 3);
        self::assertMoneyAmount($bounds->clamp(self::money('EUR', '1.500', 3)), '1.500', 3);
        self::assertMoneyAmount($bounds->clamp(self::money('EUR', '5.000', 3)), '2.000', 3);
    }

    public function test_creation_with_inverted_bounds_fails(): void
    {
        $this->expectException(InvalidInput::class);
        OrderBounds::from(self::money('GBP', '5.00'), self::money('GBP', '2.00'));
    }

    public function test_creation_with_currency_mismatch_fails(): void
    {
        $this->expectException(InvalidInput::class);
        OrderBounds::from(self::money('USD', '1.00'), self::money('EUR', '2.00'));
    }

    public function test_contains_rejects_mismatched_currency(): void
    {
        $bounds = OrderBounds::from(self::money('USD', '10.00', 2), self::money('USD', '20.00', 2));

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money currency must match order bounds.');

        $bounds->contains(self::money('EUR', '15.00', 2));
    }

    public function test_equal_bounds_creates_single_point(): void
    {
        $amount = self::money('USD', '10.00', 2);
        $bounds = OrderBounds::from($amount, $amount);

        self::assertMoneyAmount($bounds->min(), '10.00', 2);
        self::assertMoneyAmount($bounds->max(), '10.00', 2);
        self::assertTrue($bounds->contains(self::money('USD', '10.00', 2)));
        self::assertFalse($bounds->contains(self::money('USD', '10.01', 2)));
        self::assertFalse($bounds->contains(self::money('USD', '9.99', 2)));
    }

    public function test_zero_minimum_is_valid(): void
    {
        $bounds = OrderBounds::from(self::money('BTC', '0.00000000', 8), self::money('BTC', '1.00000000', 8));

        self::assertMoneyAmount($bounds->min(), '0.00000000', 8);
        self::assertMoneyAmount($bounds->max(), '1.00000000', 8);
        self::assertTrue($bounds->contains(self::money('BTC', '0.00000000', 8)));
        self::assertTrue($bounds->contains(self::money('BTC', '0.50000000', 8)));
    }

    public function test_different_scales_normalized_to_max(): void
    {
        $min = self::money('EUR', '10.0', 1);
        $max = self::money('EUR', '20.000', 3);

        $bounds = OrderBounds::from($min, $max);

        // Both should be normalized to scale 3
        self::assertMoneyAmount($bounds->min(), '10.000', 3);
        self::assertMoneyAmount($bounds->max(), '20.000', 3);
    }

    public function test_contains_with_different_scale(): void
    {
        $bounds = OrderBounds::from(self::money('USD', '10.00', 2), self::money('USD', '20.00', 2));

        // Test value with different scale should work
        self::assertTrue($bounds->contains(self::money('USD', '15', 0)));
        self::assertTrue($bounds->contains(self::money('USD', '15.0000', 4)));
        self::assertFalse($bounds->contains(self::money('USD', '25.00000', 5)));
    }

    public function test_min_and_max_accessors_return_money(): void
    {
        $min = self::money('GBP', '5.00', 2);
        $max = self::money('GBP', '10.00', 2);
        $bounds = OrderBounds::from($min, $max);

        self::assertMoneyAmount($bounds->min(), '5.00', 2);
        self::assertSame('GBP', $bounds->min()->currency());
        self::assertMoneyAmount($bounds->max(), '10.00', 2);
        self::assertSame('GBP', $bounds->max()->currency());
    }

    public function test_clamp_at_boundaries(): void
    {
        $bounds = OrderBounds::from(self::money('USD', '10.00', 2), self::money('USD', '20.00', 2));

        // Exactly at boundaries should return same value
        self::assertMoneyAmount($bounds->clamp(self::money('USD', '10.00', 2)), '10.00', 2);
        self::assertMoneyAmount($bounds->clamp(self::money('USD', '20.00', 2)), '20.00', 2);
    }

    public function test_clamp_rejects_mismatched_currency(): void
    {
        $bounds = OrderBounds::from(self::money('USD', '10.00', 2), self::money('USD', '20.00', 2));

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money currency must match order bounds.');

        $bounds->clamp(self::money('EUR', '15.00', 2));
    }

    public function test_clamp_with_different_scale(): void
    {
        $bounds = OrderBounds::from(self::money('USD', '10.00', 2), self::money('USD', '20.00', 2));

        // Clamp should normalize scale
        self::assertMoneyAmount($bounds->clamp(self::money('USD', '5', 0)), '10.00', 2);
        self::assertMoneyAmount($bounds->clamp(self::money('USD', '15.0000', 4)), '15.00', 2);
        self::assertMoneyAmount($bounds->clamp(self::money('USD', '25.000', 3)), '20.00', 2);
    }

    public function test_large_amounts(): void
    {
        $bounds = OrderBounds::from(
            self::money('USD', '1000000.00', 2),
            self::money('USD', '9999999.99', 2)
        );

        self::assertTrue($bounds->contains(self::money('USD', '5000000.00', 2)));
        self::assertFalse($bounds->contains(self::money('USD', '999999.99', 2)));
        self::assertFalse($bounds->contains(self::money('USD', '10000000.00', 2)));
    }

    public function test_very_small_amounts(): void
    {
        $bounds = OrderBounds::from(
            self::money('BTC', '0.00000001', 8),
            self::money('BTC', '0.00000010', 8)
        );

        self::assertTrue($bounds->contains(self::money('BTC', '0.00000001', 8)));
        self::assertTrue($bounds->contains(self::money('BTC', '0.00000005', 8)));
        self::assertTrue($bounds->contains(self::money('BTC', '0.00000010', 8)));
        self::assertFalse($bounds->contains(self::money('BTC', '0.00000011', 8)));
    }

    public function test_immutability_of_bounds(): void
    {
        $min = self::money('USD', '10.00', 2);
        $max = self::money('USD', '20.00', 2);
        $bounds = OrderBounds::from($min, $max);

        // Get bounds and verify they are Money objects
        $retrievedMin = $bounds->min();
        $retrievedMax = $bounds->max();

        self::assertMoneyAmount($retrievedMin, '10.00', 2);
        self::assertMoneyAmount($retrievedMax, '20.00', 2);

        // Creating operations with bounds should not affect original
        $bounds->contains(self::money('USD', '15.00', 2));
        $bounds->clamp(self::money('USD', '5.00', 2));

        self::assertMoneyAmount($bounds->min(), '10.00', 2);
        self::assertMoneyAmount($bounds->max(), '20.00', 2);
    }

    public function test_precision_at_boundaries(): void
    {
        $bounds = OrderBounds::from(self::money('USD', '10.00', 2), self::money('USD', '20.00', 2));

        // Just below minimum
        self::assertFalse($bounds->contains(self::money('USD', '9.99', 2)));
        // At minimum
        self::assertTrue($bounds->contains(self::money('USD', '10.00', 2)));
        // Just above minimum
        self::assertTrue($bounds->contains(self::money('USD', '10.01', 2)));

        // Just below maximum
        self::assertTrue($bounds->contains(self::money('USD', '19.99', 2)));
        // At maximum
        self::assertTrue($bounds->contains(self::money('USD', '20.00', 2)));
        // Just above maximum
        self::assertFalse($bounds->contains(self::money('USD', '20.01', 2)));
    }

    public function test_scale_preservation_in_clamp(): void
    {
        $bounds = OrderBounds::from(self::money('EUR', '10.00', 2), self::money('EUR', '20.00', 2));

        // When clamping, the result should have the bounds' scale
        $clamped = $bounds->clamp(self::money('EUR', '5.0', 1));
        self::assertMoneyAmount($clamped, '10.00', 2);

        $clamped2 = $bounds->clamp(self::money('EUR', '15.00000', 5));
        self::assertMoneyAmount($clamped2, '15.00', 2);
    }

    // ==================== Boundary Edge Case Tests ====================

    public function test_min_equals_max(): void
    {
        // Test that min = max creates a valid single-point bounds
        $singleAmount = self::money('USD', '100.50', 2);
        $bounds = OrderBounds::from($singleAmount, $singleAmount);

        // Verify both min and max are the same
        self::assertMoneyAmount($bounds->min(), '100.50', 2);
        self::assertMoneyAmount($bounds->max(), '100.50', 2);
        self::assertTrue($bounds->min()->equals($bounds->max()));

        // Only the exact amount should be contained
        self::assertTrue($bounds->contains(self::money('USD', '100.50', 2)));
        self::assertFalse($bounds->contains(self::money('USD', '100.49', 2)));
        self::assertFalse($bounds->contains(self::money('USD', '100.51', 2)));

        // Clamp should always return the single valid amount
        self::assertMoneyAmount($bounds->clamp(self::money('USD', '0.01', 2)), '100.50', 2);
        self::assertMoneyAmount($bounds->clamp(self::money('USD', '100.50', 2)), '100.50', 2);
        self::assertMoneyAmount($bounds->clamp(self::money('USD', '999.99', 2)), '100.50', 2);

        // Test with different scales
        $singleHigh = self::money('BTC', '1.00000000', 8);
        $boundsHigh = OrderBounds::from($singleHigh, $singleHigh);

        self::assertTrue($boundsHigh->contains(self::money('BTC', '1.00000000', 8)));
        self::assertFalse($boundsHigh->contains(self::money('BTC', '1.00000001', 8)));
        self::assertFalse($boundsHigh->contains(self::money('BTC', '0.99999999', 8)));

        // Test with zero as the single point
        $zeroAmount = self::money('EUR', '0.00', 2);
        $zeroBounds = OrderBounds::from($zeroAmount, $zeroAmount);

        self::assertMoneyAmount($zeroBounds->min(), '0.00', 2);
        self::assertMoneyAmount($zeroBounds->max(), '0.00', 2);
        self::assertTrue($zeroBounds->contains(self::money('EUR', '0.00', 2)));
        self::assertFalse($zeroBounds->contains(self::money('EUR', '0.01', 2)));
    }

    public function test_min_greater_than_max_throws_exception(): void
    {
        // Test that min > max is properly rejected
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Minimum amount cannot exceed the maximum amount.');

        $min = self::money('USD', '100.00', 2);
        $max = self::money('USD', '50.00', 2);

        OrderBounds::from($min, $max);
    }

    public function test_min_greater_than_max_with_various_scales(): void
    {
        // Test min > max with different amounts and scales
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Minimum amount cannot exceed the maximum amount.');

        $min = self::money('BTC', '1.00000001', 8);
        $max = self::money('BTC', '1.00000000', 8);

        OrderBounds::from($min, $max);
    }

    public function test_min_greater_than_max_with_different_scales(): void
    {
        // Test that min > max is detected even with different scales
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Minimum amount cannot exceed the maximum amount.');

        // 10.5 > 10.49 even though scales differ
        $min = self::money('EUR', '10.5', 1);
        $max = self::money('EUR', '10.49', 2);

        OrderBounds::from($min, $max);
    }

    public function test_zero_bounds(): void
    {
        // Test when both min and max are zero
        $zeroMin = self::money('USD', '0.00', 2);
        $zeroMax = self::money('USD', '0.00', 2);
        $bounds = OrderBounds::from($zeroMin, $zeroMax);

        // Verify both are zero
        self::assertMoneyAmount($bounds->min(), '0.00', 2);
        self::assertMoneyAmount($bounds->max(), '0.00', 2);
        self::assertTrue($bounds->min()->isZero());
        self::assertTrue($bounds->max()->isZero());

        // Only zero should be contained
        self::assertTrue($bounds->contains(self::money('USD', '0.00', 2)));
        self::assertFalse($bounds->contains(self::money('USD', '0.01', 2)));

        // Clamp always returns zero
        self::assertMoneyAmount($bounds->clamp(self::money('USD', '0.00', 2)), '0.00', 2);

        // Test with high precision zero
        $zeroBTC = self::money('BTC', '0.00000000', 8);
        $boundsBTC = OrderBounds::from($zeroBTC, $zeroBTC);

        self::assertMoneyAmount($boundsBTC->min(), '0.00000000', 8);
        self::assertMoneyAmount($boundsBTC->max(), '0.00000000', 8);
        self::assertTrue($boundsBTC->contains(self::money('BTC', '0.00000000', 8)));
    }

    public function test_zero_minimum_with_positive_maximum(): void
    {
        // Test zero as minimum with positive maximum (valid range)
        $bounds = OrderBounds::from(
            self::money('USD', '0.00', 2),
            self::money('USD', '100.00', 2)
        );

        self::assertMoneyAmount($bounds->min(), '0.00', 2);
        self::assertMoneyAmount($bounds->max(), '100.00', 2);
        self::assertTrue($bounds->min()->isZero());

        // Test boundary conditions
        self::assertTrue($bounds->contains(self::money('USD', '0.00', 2)));
        self::assertTrue($bounds->contains(self::money('USD', '0.01', 2)));
        self::assertTrue($bounds->contains(self::money('USD', '50.00', 2)));
        self::assertTrue($bounds->contains(self::money('USD', '100.00', 2)));
        self::assertFalse($bounds->contains(self::money('USD', '100.01', 2)));

        // Clamp behavior
        self::assertMoneyAmount($bounds->clamp(self::money('USD', '0.00', 2)), '0.00', 2);
        self::assertMoneyAmount($bounds->clamp(self::money('USD', '50.00', 2)), '50.00', 2);
        self::assertMoneyAmount($bounds->clamp(self::money('USD', '200.00', 2)), '100.00', 2);
    }

    public function test_same_money_instance_for_min_and_max(): void
    {
        // Test when min and max are the exact same Money object instance
        $sameAmount = self::money('USD', '42.50', 2);
        $bounds = OrderBounds::from($sameAmount, $sameAmount);

        // Verify they are the same instance (after scale normalization)
        self::assertSame($bounds->min(), $bounds->max());

        // Verify properties
        self::assertMoneyAmount($bounds->min(), '42.50', 2);
        self::assertMoneyAmount($bounds->max(), '42.50', 2);
        self::assertTrue($bounds->contains(self::money('USD', '42.50', 2)));
        self::assertFalse($bounds->contains(self::money('USD', '42.49', 2)));
        self::assertFalse($bounds->contains(self::money('USD', '42.51', 2)));

        // Clamp should always return the same value
        self::assertMoneyAmount($bounds->clamp(self::money('USD', '0.00', 2)), '42.50', 2);
        self::assertMoneyAmount($bounds->clamp(self::money('USD', '42.50', 2)), '42.50', 2);
        self::assertMoneyAmount($bounds->clamp(self::money('USD', '100.00', 2)), '42.50', 2);
    }

    public function test_negative_bounds_rejected_by_money_validation(): void
    {
        // Money value object does not allow negative amounts
        // This test verifies that negative bounds are impossible due to Money's invariants
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money amount cannot be negative');

        // Attempting to create negative Money should fail
        $negativeMoney = self::money('USD', '-10.00', 2);

        // This line should never be reached
        OrderBounds::from($negativeMoney, self::money('USD', '10.00', 2));
    }

    public function test_negative_max_bounds_rejected_by_money_validation(): void
    {
        // Verify that negative max is also rejected
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money amount cannot be negative');

        // Attempting to create negative Money should fail
        self::money('USD', '-5.00', 2);
    }

    public function test_boundary_precision_with_high_scale(): void
    {
        // Test boundary behavior with very high precision
        $bounds = OrderBounds::from(
            self::money('ETH', '1.000000000000', 12),
            self::money('ETH', '2.000000000000', 12)
        );

        // Test values just at the boundaries
        self::assertTrue($bounds->contains(self::money('ETH', '1.000000000000', 12)));
        self::assertFalse($bounds->contains(self::money('ETH', '0.999999999999', 12)));
        self::assertTrue($bounds->contains(self::money('ETH', '1.000000000001', 12)));

        self::assertTrue($bounds->contains(self::money('ETH', '2.000000000000', 12)));
        self::assertTrue($bounds->contains(self::money('ETH', '1.999999999999', 12)));
        self::assertFalse($bounds->contains(self::money('ETH', '2.000000000001', 12)));

        // Clamp at high precision
        self::assertMoneyAmount(
            $bounds->clamp(self::money('ETH', '0.500000000000', 12)),
            '1.000000000000',
            12
        );
        self::assertMoneyAmount(
            $bounds->clamp(self::money('ETH', '1.500000000000', 12)),
            '1.500000000000',
            12
        );
        self::assertMoneyAmount(
            $bounds->clamp(self::money('ETH', '3.000000000000', 12)),
            '2.000000000000',
            12
        );
    }

    public function test_scale_fifty_boundary(): void
    {
        // Test maximum allowed scale (50)
        $amountStr = '1.'.str_repeat('1', 50);
        $bounds = OrderBounds::from(
            self::money('CRYPTO', '1.'.str_repeat('0', 49).'1', 50),
            self::money('CRYPTO', $amountStr, 50)
        );

        // Verify scale is preserved
        self::assertSame(50, $bounds->min()->scale());
        self::assertSame(50, $bounds->max()->scale());

        // Test contains at maximum scale
        self::assertTrue($bounds->contains(self::money('CRYPTO', '1.'.str_repeat('0', 49).'1', 50)));
        self::assertTrue($bounds->contains(self::money('CRYPTO', $amountStr, 50)));
        self::assertFalse($bounds->contains(self::money('CRYPTO', '1.'.str_repeat('0', 50), 50)));

        // Test clamp at maximum scale
        self::assertMoneyAmount(
            $bounds->clamp(self::money('CRYPTO', '0.'.str_repeat('9', 50), 50)),
            '1.'.str_repeat('0', 49).'1',
            50
        );
        self::assertMoneyAmount(
            $bounds->clamp(self::money('CRYPTO', '2.'.str_repeat('0', 50), 50)),
            $amountStr,
            50
        );
    }

    public function test_extreme_values_at_scale_boundaries(): void
    {
        // Test very large amounts at high scales
        $largeAmount = '999999999999999999999999999.99999999999999999999999999999999999999999999999999';
        $bounds = OrderBounds::from(
            self::money('ASSET', '100000000000000000000000000.00000000000000000000000000000000000000000000000000', 50),
            self::money('ASSET', $largeAmount, 50)
        );

        self::assertSame(50, $bounds->min()->scale());
        self::assertSame(50, $bounds->max()->scale());

        // Test contains with extreme values
        self::assertTrue($bounds->contains(self::money('ASSET', '500000000000000000000000000.00000000000000000000000000000000000000000000000000', 50)));
        self::assertFalse($bounds->contains(self::money('ASSET', '50000000000000000000000000.00000000000000000000000000000000000000000000000000', 50)));

        // Test very small amounts at high scales
        $tinyBounds = OrderBounds::from(
            self::money('SATOSHI', '0.'.str_repeat('0', 49).'1', 50),
            self::money('SATOSHI', '0.'.str_repeat('0', 48).'1'.str_repeat('0', 1), 50)
        );

        self::assertTrue($tinyBounds->contains(self::money('SATOSHI', '0.'.str_repeat('0', 49).'1', 50)));
        self::assertFalse($tinyBounds->contains(self::money('SATOSHI', '0.'.str_repeat('0', 50), 50)));
    }

    // ==================== contains() Method Edge Case Tests ====================

    public function test_contains_at_minimum(): void
    {
        // Test that contains() returns true for value exactly at minimum
        $bounds = OrderBounds::from(
            self::money('USD', '10.00', 2),
            self::money('USD', '20.00', 2)
        );

        // Exactly at minimum should be contained (inclusive)
        self::assertTrue($bounds->contains(self::money('USD', '10.00', 2)));

        // Test with different scales
        $boundsHighScale = OrderBounds::from(
            self::money('BTC', '0.00100000', 8),
            self::money('BTC', '0.01000000', 8)
        );

        self::assertTrue($boundsHighScale->contains(self::money('BTC', '0.00100000', 8)));

        // Test with scale 0
        $boundsScale0 = OrderBounds::from(
            self::money('JPY', '1000', 0),
            self::money('JPY', '5000', 0)
        );

        self::assertTrue($boundsScale0->contains(self::money('JPY', '1000', 0)));

        // Test with zero as minimum
        $boundsWithZero = OrderBounds::from(
            self::money('EUR', '0.00', 2),
            self::money('EUR', '100.00', 2)
        );

        self::assertTrue($boundsWithZero->contains(self::money('EUR', '0.00', 2)));
    }

    public function test_contains_at_maximum(): void
    {
        // Test that contains() returns true for value exactly at maximum
        $bounds = OrderBounds::from(
            self::money('USD', '10.00', 2),
            self::money('USD', '20.00', 2)
        );

        // Exactly at maximum should be contained (inclusive)
        self::assertTrue($bounds->contains(self::money('USD', '20.00', 2)));

        // Test with different scales
        $boundsHighScale = OrderBounds::from(
            self::money('ETH', '1.000000000000', 12),
            self::money('ETH', '10.000000000000', 12)
        );

        self::assertTrue($boundsHighScale->contains(self::money('ETH', '10.000000000000', 12)));

        // Test with large maximum
        $boundsLarge = OrderBounds::from(
            self::money('USD', '1.00', 2),
            self::money('USD', '999999999.99', 2)
        );

        self::assertTrue($boundsLarge->contains(self::money('USD', '999999999.99', 2)));

        // Test when min = max (single point)
        $singlePoint = OrderBounds::from(
            self::money('GBP', '50.00', 2),
            self::money('GBP', '50.00', 2)
        );

        self::assertTrue($singlePoint->contains(self::money('GBP', '50.00', 2)));
    }

    public function test_contains_just_below_minimum(): void
    {
        // Test that contains() returns false for values just below minimum
        $bounds = OrderBounds::from(
            self::money('USD', '10.00', 2),
            self::money('USD', '20.00', 2)
        );

        // Just below minimum should NOT be contained
        self::assertFalse($bounds->contains(self::money('USD', '9.99', 2)));
        self::assertFalse($bounds->contains(self::money('USD', '9.50', 2)));
        self::assertFalse($bounds->contains(self::money('USD', '0.01', 2)));

        // Test with high precision
        $boundsHighScale = OrderBounds::from(
            self::money('BTC', '0.10000000', 8),
            self::money('BTC', '1.00000000', 8)
        );

        // One satoshi below minimum
        self::assertFalse($boundsHighScale->contains(self::money('BTC', '0.09999999', 8)));

        // Test with very high precision
        $boundsVeryHigh = OrderBounds::from(
            self::money('ETH', '1.000000000000', 12),
            self::money('ETH', '2.000000000000', 12)
        );

        self::assertFalse($boundsVeryHigh->contains(self::money('ETH', '0.999999999999', 12)));

        // Test edge case: significantly below minimum
        self::assertFalse($bounds->contains(self::money('USD', '0.00', 2)));
    }

    public function test_contains_just_above_maximum(): void
    {
        // Test that contains() returns false for values just above maximum
        $bounds = OrderBounds::from(
            self::money('USD', '10.00', 2),
            self::money('USD', '20.00', 2)
        );

        // Just above maximum should NOT be contained
        self::assertFalse($bounds->contains(self::money('USD', '20.01', 2)));
        self::assertFalse($bounds->contains(self::money('USD', '20.50', 2)));
        self::assertFalse($bounds->contains(self::money('USD', '100.00', 2)));

        // Test with high precision
        $boundsHighScale = OrderBounds::from(
            self::money('BTC', '0.10000000', 8),
            self::money('BTC', '1.00000000', 8)
        );

        // One satoshi above maximum
        self::assertFalse($boundsHighScale->contains(self::money('BTC', '1.00000001', 8)));

        // Test with very high precision
        $boundsVeryHigh = OrderBounds::from(
            self::money('ETH', '1.000000000000', 12),
            self::money('ETH', '2.000000000000', 12)
        );

        self::assertFalse($boundsVeryHigh->contains(self::money('ETH', '2.000000000001', 12)));

        // Test edge case: significantly above maximum
        self::assertFalse($bounds->contains(self::money('USD', '1000000.00', 2)));

        // Test when min = max (single point)
        $singlePoint = OrderBounds::from(
            self::money('GBP', '50.00', 2),
            self::money('GBP', '50.00', 2)
        );

        self::assertFalse($singlePoint->contains(self::money('GBP', '50.01', 2)));
    }

    public function test_contains_with_scale_mismatch(): void
    {
        // Test that contains() works correctly when the amount has a different scale than bounds
        $bounds = OrderBounds::from(
            self::money('USD', '10.00', 2),
            self::money('USD', '20.00', 2)
        );

        // Test with lower scale (scale 0)
        self::assertTrue($bounds->contains(self::money('USD', '10', 0)));
        self::assertTrue($bounds->contains(self::money('USD', '15', 0)));
        self::assertTrue($bounds->contains(self::money('USD', '20', 0)));
        self::assertFalse($bounds->contains(self::money('USD', '9', 0)));
        self::assertFalse($bounds->contains(self::money('USD', '21', 0)));

        // Test with higher scale (scale 4)
        self::assertTrue($bounds->contains(self::money('USD', '10.0000', 4)));
        self::assertTrue($bounds->contains(self::money('USD', '15.5000', 4)));
        self::assertTrue($bounds->contains(self::money('USD', '20.0000', 4)));
        self::assertFalse($bounds->contains(self::money('USD', '9.9900', 4)));
        self::assertFalse($bounds->contains(self::money('USD', '20.0100', 4)));

        // Test with much higher scale (scale 8)
        self::assertTrue($bounds->contains(self::money('USD', '10.00000000', 8)));
        self::assertTrue($bounds->contains(self::money('USD', '15.12345678', 8)));
        self::assertTrue($bounds->contains(self::money('USD', '20.00000000', 8)));
        self::assertFalse($bounds->contains(self::money('USD', '9.99000000', 8)));
        self::assertFalse($bounds->contains(self::money('USD', '20.01000000', 8)));

        // Test boundary values with scale mismatch
        $boundsScale8 = OrderBounds::from(
            self::money('BTC', '0.10000000', 8),
            self::money('BTC', '1.00000000', 8)
        );

        // Test with scale 2 (lower)
        self::assertTrue($boundsScale8->contains(self::money('BTC', '0.10', 2)));
        self::assertTrue($boundsScale8->contains(self::money('BTC', '0.50', 2)));
        self::assertTrue($boundsScale8->contains(self::money('BTC', '1.00', 2)));
        self::assertFalse($boundsScale8->contains(self::money('BTC', '0.09', 2)));
        self::assertFalse($boundsScale8->contains(self::money('BTC', '1.01', 2)));

        // Test with scale 12 (higher)
        self::assertTrue($boundsScale8->contains(self::money('BTC', '0.100000000000', 12)));
        self::assertTrue($boundsScale8->contains(self::money('BTC', '0.555555555555', 12)));
        self::assertTrue($boundsScale8->contains(self::money('BTC', '1.000000000000', 12)));
        self::assertFalse($boundsScale8->contains(self::money('BTC', '0.099000000000', 12)));
        self::assertFalse($boundsScale8->contains(self::money('BTC', '1.001000000000', 12)));

        // Test edge case: bounds with different scales internally normalized
        $boundsMixedScales = OrderBounds::from(
            self::money('EUR', '10.0', 1),
            self::money('EUR', '20.000', 3)
        );

        // Both should be normalized to scale 3
        self::assertTrue($boundsMixedScales->contains(self::money('EUR', '10.00', 2)));
        self::assertTrue($boundsMixedScales->contains(self::money('EUR', '15.0000', 4)));
        self::assertFalse($boundsMixedScales->contains(self::money('EUR', '9.999', 3)));
        self::assertFalse($boundsMixedScales->contains(self::money('EUR', '20.001', 3)));
    }
}
