<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Domain\Money;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Domain\Money\AssetPair;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Helpers\Generator\ProvidesRandomizedValues;
use SomeWork\P2PPathFinder\Tests\Helpers\InfectionIterationLimiter;

use function chr;
use function count;
use function ord;
use function str_repeat;
use function strlen;
use function strtoupper;

#[CoversClass(AssetPair::class)]
final class AssetPairPropertyTest extends TestCase
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
     * Property: For any valid asset pair P, P should equal itself.
     * Tests reflexivity of equality.
     */
    public function test_asset_pair_equality_is_reflexive(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ASSET_PAIR_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $base = $this->randomCurrencyCode();
            $quote = $this->randomDistinctCurrency($base);

            $pair1 = AssetPair::fromString($base, $quote);
            $pair2 = AssetPair::fromString($base, $quote);

            self::assertSame($pair1->base(), $pair2->base());
            self::assertSame($pair1->quote(), $pair2->quote());
        }
    }

    /**
     * Property: For any valid asset pairs P1 and P2, if P1 == P2 then P2 == P1.
     * Tests symmetry of equality.
     */
    public function test_asset_pair_equality_is_symmetric(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ASSET_PAIR_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $base = $this->randomCurrencyCode();
            $quote = $this->randomDistinctCurrency($base);

            $pair1 = AssetPair::fromString($base, $quote);
            $pair2 = AssetPair::fromString($base, $quote);

            // Verify symmetry through base/quote equality
            self::assertSame($pair1->base(), $pair2->base());
            self::assertSame($pair1->quote(), $pair2->quote());
            self::assertSame($pair2->base(), $pair1->base());
            self::assertSame($pair2->quote(), $pair1->quote());
        }
    }

    /**
     * Property: For any valid asset pairs P1, P2, P3, if P1 == P2 and P2 == P3, then P1 == P3.
     * Tests transitivity of equality.
     */
    public function test_asset_pair_equality_is_transitive(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ASSET_PAIR_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $base = $this->randomCurrencyCode();
            $quote = $this->randomDistinctCurrency($base);

            $pair1 = AssetPair::fromString($base, $quote);
            $pair2 = AssetPair::fromString($base, $quote);
            $pair3 = AssetPair::fromString($base, $quote);

            // If P1 == P2 and P2 == P3, then P1 == P3
            self::assertSame($pair1->base(), $pair2->base());
            self::assertSame($pair2->base(), $pair3->base());
            self::assertSame($pair1->base(), $pair3->base());

            self::assertSame($pair1->quote(), $pair2->quote());
            self::assertSame($pair2->quote(), $pair3->quote());
            self::assertSame($pair1->quote(), $pair3->quote());
        }
    }

    /**
     * Property: Asset pair currencies are always normalized to uppercase regardless of input case.
     */
    public function test_base_and_quote_are_normalized_to_uppercase(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ASSET_PAIR_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $base = $this->randomCurrencyCode();
            $quote = $this->randomDistinctCurrency($base);

            // Apply random case transformations
            $caseVariant = $this->randomizer->getInt(0, 2);
            $inputBase = match ($caseVariant) {
                0 => strtolower($base),
                1 => strtoupper($base),
                default => $this->mixedCase($base),
            };

            $caseVariant = $this->randomizer->getInt(0, 2);
            $inputQuote = match ($caseVariant) {
                0 => strtolower($quote),
                1 => strtoupper($quote),
                default => $this->mixedCase($quote),
            };

            $pair = AssetPair::fromString($inputBase, $inputQuote);

            // Should always be uppercase
            self::assertSame(strtoupper($base), $pair->base());
            self::assertSame(strtoupper($quote), $pair->quote());
        }
    }

    /**
     * Property: Currency codes must be 3-12 alphabetic characters.
     * Invalid currencies should always throw InvalidInput.
     */
    public function test_invalid_currencies_always_throw(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ASSET_PAIR_PROPERTY_ITERATIONS');

        $invalidGenerators = [
            // Too short (< 3 chars)
            fn () => str_repeat('A', $this->randomizer->getInt(0, 2)),
            // Too long (> 12 chars)
            fn () => str_repeat('A', $this->randomizer->getInt(13, 20)),
            // Contains digits
            fn () => 'AB'.$this->randomizer->getInt(0, 9),
            // Contains special chars
            fn () => (static function (Randomizer $r): string {
                $chars = ['!', '@', '#', '$', '%', '^', '&', '*'];
                $idx = $r->pickArrayKeys($chars, 1)[0];

                return 'AB'.$chars[$idx];
            })($this->randomizer),
            // Empty string
            fn () => '',
        ];

        for ($i = 0; $i < $limit; ++$i) {
            $generator = $invalidGenerators[$this->randomizer->getInt(0, count($invalidGenerators) - 1)];
            $invalid = $generator();
            $valid = $this->randomCurrencyCode();

            // Test invalid base
            try {
                AssetPair::fromString($invalid, $valid);
                self::fail('Expected InvalidInput for invalid base currency: '.$invalid);
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }

            // Test invalid quote
            try {
                AssetPair::fromString($valid, $invalid);
                self::fail('Expected InvalidInput for invalid quote currency: '.$invalid);
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Property: Base and quote currencies must be distinct (after normalization).
     * Same currencies should always throw InvalidInput.
     */
    public function test_identical_currencies_always_throw(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ASSET_PAIR_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();

            // Try various case combinations of same currency
            $caseVariants = [
                [strtoupper($currency), strtolower($currency)],
                [strtolower($currency), strtoupper($currency)],
                [$this->mixedCase($currency), strtoupper($currency)],
                [strtoupper($currency), $currency],
            ];

            foreach ($caseVariants as [$base, $quote]) {
                try {
                    AssetPair::fromString($base, $quote);
                    self::fail('Expected InvalidInput for identical currencies: '.$base.' = '.$quote);
                } catch (InvalidInput $e) {
                    self::assertStringContainsString('distinct assets', $e->getMessage());
                }
            }
        }
    }

    /**
     * Property: AssetPair is immutable - repeated access returns consistent values.
     */
    public function test_immutability_across_repeated_access(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ASSET_PAIR_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $base = $this->randomCurrencyCode();
            $quote = $this->randomDistinctCurrency($base);

            $pair = AssetPair::fromString($base, $quote);

            // Multiple accesses should return identical values
            $base1 = $pair->base();
            $base2 = $pair->base();
            $base3 = $pair->base();

            $quote1 = $pair->quote();
            $quote2 = $pair->quote();
            $quote3 = $pair->quote();

            self::assertSame($base1, $base2);
            self::assertSame($base2, $base3);
            self::assertSame($quote1, $quote2);
            self::assertSame($quote2, $quote3);
        }
    }

    /**
     * Property: Valid currency lengths (3-12) should always succeed.
     */
    public function test_valid_currency_length_bounds(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ASSET_PAIR_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            // Test boundary lengths: 3, 12, and random in between
            $lengths = [3, $this->randomizer->getInt(4, 11), 12];
            $length = $lengths[$this->randomizer->getInt(0, count($lengths) - 1)];

            $base = $this->randomCurrencyOfLength($length);
            $quote = $this->randomDistinctCurrency($base);

            $pair = AssetPair::fromString($base, $quote);

            self::assertSame(strtoupper($base), $pair->base());
            self::assertSame(strtoupper($quote), $pair->quote());
        }
    }

    /**
     * Property: Invalid length currencies should always fail.
     */
    public function test_invalid_currency_lengths_always_fail(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ASSET_PAIR_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            // Generate invalid lengths
            $invalidLengths = [
                $this->randomizer->getInt(0, 2),      // 0-2 (too short)
                $this->randomizer->getInt(13, 20),    // 13-20 (too long)
            ];
            $length = $invalidLengths[$this->randomizer->getInt(0, count($invalidLengths) - 1)];

            $invalidCurrency = $this->randomCurrencyOfLength($length);
            $validCurrency = $this->randomCurrencyCode();

            // Test invalid base
            try {
                AssetPair::fromString($invalidCurrency, $validCurrency);
                self::fail('Expected InvalidInput for invalid length base currency: '.$invalidCurrency.' (length: '.strlen($invalidCurrency).')');
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }

            // Test invalid quote
            try {
                AssetPair::fromString($validCurrency, $invalidCurrency);
                self::fail('Expected InvalidInput for invalid length quote currency: '.$invalidCurrency.' (length: '.strlen($invalidCurrency).')');
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Property: Currencies with non-alphabetic characters should always fail.
     */
    public function test_non_alphabetic_currencies_always_fail(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ASSET_PAIR_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $validCurrency = $this->randomCurrencyCode();

            // Generate invalid currencies with non-alphabetic characters
            $invalidGenerators = [
                // Add digits
                fn () => $validCurrency.$this->randomizer->getInt(0, 9),
                fn () => $this->randomizer->getInt(0, 9).$validCurrency,
                // Add special characters
                fn () => $validCurrency.'!',
                fn () => $validCurrency.'@',
                fn () => $validCurrency.'#',
                // Add spaces
                fn () => $validCurrency.' ',
                // Add dashes
                fn () => $validCurrency.'-',
                // Add underscores
                fn () => $validCurrency.'_',
            ];

            $generator = $invalidGenerators[$this->randomizer->getInt(0, count($invalidGenerators) - 1)];
            $invalidCurrency = $generator();

            // Test invalid base
            try {
                AssetPair::fromString($invalidCurrency, $validCurrency);
                self::fail('Expected InvalidInput for non-alphabetic base currency: '.$invalidCurrency);
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }

            // Test invalid quote
            try {
                AssetPair::fromString($validCurrency, $invalidCurrency);
                self::fail('Expected InvalidInput for non-alphabetic quote currency: '.$invalidCurrency);
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Property: AssetPair creation should be deterministic - same inputs produce identical results.
     */
    public function test_asset_pair_creation_is_deterministic(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ASSET_PAIR_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $base = $this->randomCurrencyCode();
            $quote = $this->randomDistinctCurrency($base);

            // Apply different case variations
            $caseVariations = [
                [strtolower($base), strtolower($quote)],
                [strtoupper($base), strtoupper($quote)],
                [$this->mixedCase($base), $this->mixedCase($quote)],
                [strtolower($base), strtoupper($quote)],
                [strtoupper($base), strtolower($quote)],
            ];

            $referencePair = AssetPair::fromString($base, $quote);

            foreach ($caseVariations as [$inputBase, $inputQuote]) {
                $pair = AssetPair::fromString($inputBase, $inputQuote);

                // All should produce the same normalized result
                self::assertSame($referencePair->base(), $pair->base());
                self::assertSame($referencePair->quote(), $pair->quote());
            }
        }
    }

    /**
     * Property: Base and quote currencies are always properly ordered and distinct after creation.
     */
    public function test_base_and_quote_are_always_distinct_and_normalized(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ASSET_PAIR_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $base = $this->randomCurrencyCode();
            $quote = $this->randomDistinctCurrency($base);

            $pair = AssetPair::fromString($base, $quote);

            // Base and quote should always be different
            self::assertNotSame($pair->base(), $pair->quote());

            // Both should be uppercase
            self::assertMatchesRegularExpression('/^[A-Z]{3,12}$/', $pair->base());
            self::assertMatchesRegularExpression('/^[A-Z]{3,12}$/', $pair->quote());

            // Should match the normalized versions of inputs
            self::assertSame(strtoupper($base), $pair->base());
            self::assertSame(strtoupper($quote), $pair->quote());
        }
    }

    private function randomCurrencyOfLength(int $length): string
    {
        $currency = '';
        for ($j = 0; $j < $length; ++$j) {
            $currency .= chr($this->randomizer->getInt(ord('A'), ord('Z')));
        }

        return $currency;
    }

    private function randomDistinctCurrency(string $existing): string
    {
        do {
            $currency = $this->randomCurrencyCode();
        } while (strtoupper($currency) === strtoupper($existing));

        return $currency;
    }

    private function mixedCase(string $str): string
    {
        $result = '';
        $length = strlen($str);
        for ($i = 0; $i < $length; ++$i) {
            $char = $str[$i];
            $result .= 0 === $this->randomizer->getInt(0, 1) ? strtolower($char) : strtoupper($char);
        }

        return $result;
    }
}
