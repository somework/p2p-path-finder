<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Domain\Order\Fee;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeePolicyHelper;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function str_repeat;

#[CoversClass(FeePolicyHelper::class)]
final class FeePolicyHelperTest extends TestCase
{
    #[TestDox('validateFingerprint accepts a valid non-empty string without throwing')]
    public function test_validate_fingerprint_accepts_valid_fingerprint(): void
    {
        FeePolicyHelper::validateFingerprint('base-surcharge:0.001:6');

        self::assertTrue(true); // No exception thrown
    }

    #[TestDox('validateFingerprint rejects an empty string')]
    public function test_validate_fingerprint_rejects_empty_string(): void
    {
        self::expectException(InvalidInput::class);
        self::expectExceptionMessage('Fee policy fingerprint must be non-empty.');

        FeePolicyHelper::validateFingerprint('');
    }

    #[TestDox('validateFingerprint rejects a whitespace-only string')]
    public function test_validate_fingerprint_rejects_whitespace_only(): void
    {
        self::expectException(InvalidInput::class);
        self::expectExceptionMessage('Fee policy fingerprint must be non-empty.');

        FeePolicyHelper::validateFingerprint('   ');
    }

    #[TestDox('validateFingerprint rejects a string longer than 255 characters')]
    public function test_validate_fingerprint_rejects_too_long(): void
    {
        $longFingerprint = str_repeat('a', 256);

        self::expectException(InvalidInput::class);
        self::expectExceptionMessageMatches('/must be ≤255 characters/');

        FeePolicyHelper::validateFingerprint($longFingerprint);
    }

    #[TestDox('validateFingerprint accepts a string of exactly 255 characters')]
    public function test_validate_fingerprint_accepts_max_length(): void
    {
        $maxLengthFingerprint = str_repeat('a', 255);

        FeePolicyHelper::validateFingerprint($maxLengthFingerprint);

        self::assertTrue(true);
    }

    #[TestDox('fingerprintsEqual returns true for identical strings')]
    public function test_fingerprints_equal_returns_true_for_identical(): void
    {
        self::assertTrue(
            FeePolicyHelper::fingerprintsEqual('base-surcharge:0.001:6', 'base-surcharge:0.001:6')
        );
    }

    #[TestDox('fingerprintsEqual returns false for different strings')]
    public function test_fingerprints_equal_returns_false_for_different(): void
    {
        self::assertFalse(
            FeePolicyHelper::fingerprintsEqual('base-surcharge:0.001:6', 'base-surcharge:0.002:6')
        );
    }

    #[TestDox('validateUniqueness passes with a list of unique fingerprints')]
    public function test_validate_uniqueness_accepts_unique_fingerprints(): void
    {
        $fingerprints = [
            'base-surcharge:0.001:6',
            'quote-percentage-fixed:0.005:2.50:2',
            'base-quote-surcharge:0.002:0.003:8',
        ];

        FeePolicyHelper::validateUniqueness($fingerprints);

        self::assertTrue(true);
    }

    #[TestDox('validateUniqueness passes with an empty array')]
    public function test_validate_uniqueness_accepts_empty_array(): void
    {
        FeePolicyHelper::validateUniqueness([]);

        self::assertTrue(true);
    }

    #[TestDox('validateUniqueness throws InvalidInput on duplicate fingerprints with indices')]
    public function test_validate_uniqueness_rejects_duplicate_fingerprints(): void
    {
        $fingerprints = [
            'base-surcharge:0.001:6',
            'quote-percentage-fixed:0.005:2.50:2',
            'base-surcharge:0.001:6', // Duplicate at index 2
        ];

        self::expectException(InvalidInput::class);
        self::expectExceptionMessage('Fee policy fingerprints must be unique');

        FeePolicyHelper::validateUniqueness($fingerprints);
    }
}
