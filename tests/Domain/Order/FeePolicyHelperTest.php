<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\Order;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\Order\FeePolicyHelper;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function str_repeat;

final class FeePolicyHelperTest extends TestCase
{
    public function test_validate_fingerprint_accepts_valid_fingerprint(): void
    {
        FeePolicyHelper::validateFingerprint('base-surcharge:0.001:6');

        self::assertTrue(true); // No exception thrown
    }

    public function test_validate_fingerprint_rejects_empty_string(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Fee policy fingerprint must be non-empty.');

        FeePolicyHelper::validateFingerprint('');
    }

    public function test_validate_fingerprint_rejects_whitespace_only(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Fee policy fingerprint must be non-empty.');

        FeePolicyHelper::validateFingerprint('   ');
    }

    public function test_validate_fingerprint_rejects_too_long(): void
    {
        $longFingerprint = str_repeat('a', 256);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessageMatches('/must be ≤255 characters/');

        FeePolicyHelper::validateFingerprint($longFingerprint);
    }

    public function test_validate_fingerprint_accepts_max_length(): void
    {
        $maxLengthFingerprint = str_repeat('a', 255);

        FeePolicyHelper::validateFingerprint($maxLengthFingerprint);

        self::assertTrue(true);
    }

    public function test_fingerprints_equal_returns_true_for_identical(): void
    {
        self::assertTrue(
            FeePolicyHelper::fingerprintsEqual('base-surcharge:0.001:6', 'base-surcharge:0.001:6')
        );
    }

    public function test_fingerprints_equal_returns_false_for_different(): void
    {
        self::assertFalse(
            FeePolicyHelper::fingerprintsEqual('base-surcharge:0.001:6', 'base-surcharge:0.002:6')
        );
    }

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

    public function test_validate_uniqueness_accepts_empty_array(): void
    {
        FeePolicyHelper::validateUniqueness([]);

        self::assertTrue(true);
    }

    public function test_validate_uniqueness_rejects_duplicate_fingerprints(): void
    {
        $fingerprints = [
            'base-surcharge:0.001:6',
            'quote-percentage-fixed:0.005:2.50:2',
            'base-surcharge:0.001:6', // Duplicate at index 2
        ];

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Fee policy fingerprints must be unique');
        $this->expectExceptionMessage('base-surcharge:0.001:6');
        $this->expectExceptionMessage('indices 0 and 2');

        FeePolicyHelper::validateUniqueness($fingerprints);
    }

    public function test_validate_uniqueness_reports_all_duplicates(): void
    {
        $fingerprints = [
            'policy-a',
            'policy-b',
            'policy-a', // Duplicate of index 0
            'policy-c',
            'policy-b', // Duplicate of index 1
        ];

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('policy-a');
        $this->expectExceptionMessage('policy-b');

        FeePolicyHelper::validateUniqueness($fingerprints);
    }

    public function test_validate_uniqueness_handles_single_element(): void
    {
        FeePolicyHelper::validateUniqueness(['single-policy']);

        self::assertTrue(true);
    }
}
