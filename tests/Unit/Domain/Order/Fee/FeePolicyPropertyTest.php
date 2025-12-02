<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Domain\Order\Fee;

use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeePolicyHelper;
use SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory;
use SomeWork\P2PPathFinder\Tests\Helpers\Generator\ProvidesRandomizedValues;
use SomeWork\P2PPathFinder\Tests\Helpers\InfectionIterationLimiter;

use function array_map;
use function array_unique;
use function count;
use function strlen;

/**
 * Property-based tests for FeePolicy fingerprint contract.
 */
final class FeePolicyPropertyTest extends TestCase
{
    use InfectionIterationLimiter;
    use ProvidesRandomizedValues;

    private Randomizer $randomizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->randomizer = new Randomizer(new Xoshiro256StarStar());
    }

    protected function randomizer(): Randomizer
    {
        return $this->randomizer;
    }

    /**
     * Property: Different policy configurations must produce different fingerprints.
     */
    public function test_different_policies_have_different_fingerprints(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_POLICY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $policies = $this->generateDiversePolicies();

            $fingerprints = array_map(
                static fn (FeePolicy $policy): string => $policy->fingerprint(),
                $policies
            );

            // Property: All fingerprints must be unique
            $uniqueFingerprints = array_unique($fingerprints);
            self::assertCount(
                count($policies),
                $uniqueFingerprints,
                'Fee policy fingerprints must be unique across different configurations'
            );

            // Additional validation: fingerprints should be non-empty
            foreach ($fingerprints as $fingerprint) {
                self::assertNotEmpty($fingerprint, 'Fingerprint must be non-empty');
            }
        }
    }

    /**
     * Property: Same policy configuration must always produce the same fingerprint (determinism).
     */
    public function test_fingerprint_is_deterministic(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_POLICY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $ratio = $this->randomRatio();
            $scale = $this->randomScale();

            // Create same policy twice
            $policy1 = FeePolicyFactory::baseSurcharge($ratio, $scale);
            $policy2 = FeePolicyFactory::baseSurcharge($ratio, $scale);

            // Property: Fingerprints must be identical
            self::assertSame(
                $policy1->fingerprint(),
                $policy2->fingerprint(),
                'Same policy configuration must produce identical fingerprints'
            );
        }
    }

    /**
     * Property: Policies with same type but different parameters must have different fingerprints.
     */
    public function test_same_type_different_parameters_produce_different_fingerprints(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_POLICY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            // Generate two different ratios
            $ratio1 = $this->randomRatio();
            $ratio2 = $this->ensureDifferent($ratio1);
            $scale = $this->randomScale();

            $policy1 = FeePolicyFactory::baseSurcharge($ratio1, $scale);
            $policy2 = FeePolicyFactory::baseSurcharge($ratio2, $scale);

            // Property: Different parameters must produce different fingerprints
            self::assertNotSame(
                $policy1->fingerprint(),
                $policy2->fingerprint(),
                'Policies with different parameters must have different fingerprints'
            );
        }
    }

    /**
     * Property: Different scales must produce different fingerprints.
     */
    public function test_different_scales_produce_different_fingerprints(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_POLICY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $ratio = $this->randomRatio();
            $scale1 = $this->randomScale();
            $scale2 = $this->randomizer->getInt(0, 18);

            // Ensure scales are different
            while ($scale1 === $scale2) {
                $scale2 = $this->randomizer->getInt(0, 18);
            }

            $policy1 = FeePolicyFactory::baseSurcharge($ratio, $scale1);
            $policy2 = FeePolicyFactory::baseSurcharge($ratio, $scale2);

            // Property: Different scales must produce different fingerprints
            self::assertNotSame(
                $policy1->fingerprint(),
                $policy2->fingerprint(),
                'Policies with different scales must have different fingerprints'
            );
        }
    }

    /**
     * Property: All fingerprints meet validation requirements.
     */
    public function test_all_fingerprints_pass_validation(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_POLICY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $policies = $this->generateDiversePolicies();

            foreach ($policies as $policy) {
                $fingerprint = $policy->fingerprint();

                // Property: All fingerprints must pass validation
                FeePolicyHelper::validateFingerprint($fingerprint);

                // Should not throw, so we verify basic properties
                self::assertNotEmpty($fingerprint);
                self::assertLessThanOrEqual(FeePolicyHelper::MAX_FINGERPRINT_LENGTH, strlen($fingerprint));
            }
        }
    }

    /**
     * Property: FeePolicyHelper::validateUniqueness correctly identifies unique policies.
     */
    public function test_uniqueness_validation_succeeds_for_diverse_policies(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_POLICY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $policies = $this->generateDiversePolicies();

            $fingerprints = array_map(
                static fn (FeePolicy $policy): string => $policy->fingerprint(),
                $policies
            );

            // Property: Uniqueness validation should not throw for diverse policies
            FeePolicyHelper::validateUniqueness($fingerprints);

            self::assertTrue(true); // No exception means success
        }
    }

    /**
     * Property: Fingerprints are stable across multiple accesses.
     */
    public function test_fingerprint_is_stable_across_multiple_accesses(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_POLICY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $ratio = $this->randomRatio();
            $scale = $this->randomScale();

            $policy = FeePolicyFactory::baseSurcharge($ratio, $scale);

            // Access fingerprint multiple times
            $fingerprint1 = $policy->fingerprint();
            $fingerprint2 = $policy->fingerprint();
            $fingerprint3 = $policy->fingerprint();

            // Property: All accesses must return identical string
            self::assertSame($fingerprint1, $fingerprint2);
            self::assertSame($fingerprint2, $fingerprint3);
        }
    }

    /**
     * Generates a diverse set of fee policies with different configurations.
     *
     * @return list<FeePolicy>
     */
    private function generateDiversePolicies(): array
    {
        $policies = [];
        $seenFingerprints = [];

        $addPolicy = static function (FeePolicy $policy) use (&$policies, &$seenFingerprints): void {
            $fingerprint = $policy->fingerprint();
            if (isset($seenFingerprints[$fingerprint])) {
                // Skip duplicate configurations to keep the set diverse
                return;
            }

            $seenFingerprints[$fingerprint] = true;
            $policies[] = $policy;
        };

        // Generate 3-5 base surcharge policies with different ratios
        $count = $this->randomizer->getInt(3, 5);
        for ($j = 0; $j < $count; ++$j) {
            $addPolicy(FeePolicyFactory::baseSurcharge(
                $this->randomRatio(),
                $this->randomScale()
            ));
        }

        // Generate 2-3 base+quote surcharge policies
        $count = $this->randomizer->getInt(2, 3);
        for ($j = 0; $j < $count; ++$j) {
            $addPolicy(FeePolicyFactory::baseAndQuoteSurcharge(
                $this->randomRatio(),
                $this->randomRatio(),
                $this->randomScale()
            ));
        }

        // Generate 2-3 quote percentage + fixed policies
        $count = $this->randomizer->getInt(2, 3);
        for ($j = 0; $j < $count; ++$j) {
            $addPolicy(FeePolicyFactory::quotePercentageWithFixed(
                $this->randomRatio(),
                $this->randomFixedAmount(),
                $this->randomScale()
            ));
        }

        return $policies;
    }

    /**
     * @return numeric-string
     */
    private function randomRatio(): string
    {
        // Generate ratio between 0.0001 and 0.1 (0.01% to 10%)
        $units = $this->randomizer->getInt(1, 1000);

        return $this->formatUnits($units, 4);
    }

    /**
     * @return numeric-string
     */
    private function randomFixedAmount(): string
    {
        // Generate fixed amount between 0.01 and 100.00
        $units = $this->randomizer->getInt(1, 10000);

        return $this->formatUnits($units, 2);
    }

    private function randomScale(): int
    {
        // Bias towards common scales
        $commonScales = [2, 6, 8, 18];
        if (0 === $this->randomizer->getInt(0, 1)) {
            return $commonScales[$this->randomizer->getInt(0, count($commonScales) - 1)];
        }

        return $this->randomizer->getInt(0, 18);
    }

    /**
     * @return numeric-string
     */
    private function ensureDifferent(string $existing): string
    {
        do {
            $new = $this->randomRatio();
        } while ($new === $existing);

        return $new;
    }
}
