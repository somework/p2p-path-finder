<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\Order;

use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function sprintf;
use function strlen;
use function trim;

/**
 * Helper utilities for validating and working with FeePolicy implementations.
 */
final class FeePolicyHelper
{
    /**
     * Maximum recommended length for a fee policy fingerprint.
     */
    public const MAX_FINGERPRINT_LENGTH = 255;

    /**
     * Validates that a fingerprint meets the requirements specified in the FeePolicy contract.
     *
     * @param string $fingerprint The fingerprint to validate
     *
     * @throws InvalidInput when the fingerprint is invalid
     */
    public static function validateFingerprint(string $fingerprint): void
    {
        if ('' === trim($fingerprint)) {
            throw new InvalidInput('Fee policy fingerprint must be non-empty.');
        }

        if (strlen($fingerprint) > self::MAX_FINGERPRINT_LENGTH) {
            throw new InvalidInput(sprintf('Fee policy fingerprint must be ≤%d characters, got %d characters.', self::MAX_FINGERPRINT_LENGTH, strlen($fingerprint)));
        }
    }

    /**
     * Checks if two fingerprints are identical.
     *
     * @param string $fingerprint1 First fingerprint
     * @param string $fingerprint2 Second fingerprint
     *
     * @return bool True if fingerprints are identical
     */
    public static function fingerprintsEqual(string $fingerprint1, string $fingerprint2): bool
    {
        return $fingerprint1 === $fingerprint2;
    }

    /**
     * Validates that all provided fingerprints are unique.
     *
     * @param list<string> $fingerprints Array of fingerprints to check
     *
     * @throws InvalidInput when duplicate fingerprints are found
     */
    public static function validateUniqueness(array $fingerprints): void
    {
        $seen = [];
        $duplicates = [];

        foreach ($fingerprints as $index => $fingerprint) {
            if (isset($seen[$fingerprint])) {
                $duplicates[] = sprintf(
                    'Fingerprint "%s" appears at indices %d and %d',
                    $fingerprint,
                    $seen[$fingerprint],
                    $index
                );
            } else {
                $seen[$fingerprint] = $index;
            }
        }

        if ([] !== $duplicates) {
            throw new InvalidInput('Fee policy fingerprints must be unique. Found duplicates: '.implode('; ', $duplicates));
        }
    }
}
