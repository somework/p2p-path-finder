<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Application\Support\Generator\ProvidesRandomizedValues;
use SomeWork\P2PPathFinder\Tests\Support\InfectionIterationLimiter;

use function count;

/**
 * Property-based tests for ExchangeRate value object.
 *
 * Tests verify conversion and inversion properties:
 * - Conversion roundtrip: convert(money, rate) -> invert -> convert ≈ original
 * - Inversion identity: invert(invert(rate)) ≈ rate
 * - Conversion linearity: convert(a + b) = convert(a) + convert(b)
 * - Rate multiplication: convert through rate1->rate2 = convert through (rate1 * rate2)
 */
final class ExchangeRatePropertyTest extends TestCase
{
    use InfectionIterationLimiter;
    use MoneyAssertions;
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
     * Property: Conversion roundtrip preserves value (within rounding).
     *
     * Converting currency A to B and back to A via inverted rate
     * should yield approximately the original amount.
     */
    public function test_exchange_rate_conversion_roundtrip(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_EXCHANGE_RATE_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currencyA = $this->randomCurrencyCode();
            $currencyB = $this->randomDistinctCurrency($currencyA);
            $scale = $this->randomScale();

            // Create a rate from A to B  (use moderate rates to minimize rounding)
            $rateValue = $this->formatUnits($this->randomizer->getInt(100, 1000), 2);
            $rate = ExchangeRate::fromString($currencyA, $currencyB, $rateValue, $scale);
            $inverseRate = $rate->invert();

            // Create money in currency A
            $amountA = $this->randomUnits($scale);
            $originalMoney = $this->money($currencyA, $this->formatUnits($amountA, $scale), $scale);

            // Convert A -> B -> A
            $convertedToB = $rate->convert($originalMoney);
            $roundtrip = $inverseRate->convert($convertedToB);

            // Property: roundtrip should approximately equal original
            // Allow up to 2% difference due to two conversions and rounding
            $diff = abs((float) $originalMoney->amount() - (float) $roundtrip->amount());
            $tolerance = max(0.001, (float) $originalMoney->amount() * 0.02);

            self::assertLessThanOrEqual(
                $tolerance,
                $diff,
                "Roundtrip failed: {$originalMoney->amount()} {$currencyA} -> {$convertedToB->amount()} {$currencyB} -> {$roundtrip->amount()} {$currencyA}, diff: {$diff}, tolerance: {$tolerance}"
            );
        }
    }

    /**
     * Property: Double inversion returns approximately the original rate.
     *
     * Inverting a rate twice should yield the original rate (within rounding).
     * Due to scale limitations and HALF_UP rounding, some precision loss is expected.
     */
    public function test_double_inversion_returns_original_rate(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_EXCHANGE_RATE_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currencyA = $this->randomCurrencyCode();
            $currencyB = $this->randomDistinctCurrency($currencyA);
            $scale = $this->randomScale();

            $rateValue = $this->randomRate();
            $originalRate = ExchangeRate::fromString($currencyA, $currencyB, $rateValue, $scale);

            $inverted = $originalRate->invert();
            $doubleInverted = $inverted->invert();

            // Property: invert(invert(rate)) ≈ rate
            self::assertSame($originalRate->baseCurrency(), $doubleInverted->baseCurrency());
            self::assertSame($originalRate->quoteCurrency(), $doubleInverted->quoteCurrency());

            // Rates should be close (allow up to 1% difference due to compounding rounding)
            $originalRateFloat = (float) $originalRate->rate();
            $relativeTolerance = max($originalRateFloat * 0.01, 1e-6);

            self::assertEqualsWithDelta(
                $originalRateFloat,
                (float) $doubleInverted->rate(),
                $relativeTolerance,
                "Double inversion failed: original rate {$originalRate->rate()} != double inverted {$doubleInverted->rate()}"
            );
        }
    }

    /**
     * Property: Conversion is linear: convert(a + b) = convert(a) + convert(b).
     *
     * Converting the sum should equal the sum of conversions.
     */
    public function test_conversion_is_linear(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_EXCHANGE_RATE_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currencyA = $this->randomCurrencyCode();
            $currencyB = $this->randomDistinctCurrency($currencyA);
            $scale = $this->randomScale();

            $rateValue = $this->randomRate();
            $rate = ExchangeRate::fromString($currencyA, $currencyB, $rateValue, $scale);

            $amountA1 = $this->randomUnits($scale);
            $amountA2 = $this->randomUnits($scale);

            $moneyA1 = $this->money($currencyA, $this->formatUnits($amountA1, $scale), $scale);
            $moneyA2 = $this->money($currencyA, $this->formatUnits($amountA2, $scale), $scale);

            // Convert sum
            $sum = $moneyA1->add($moneyA2);
            $convertedSum = $rate->convert($sum);

            // Sum of conversions
            $converted1 = $rate->convert($moneyA1);
            $converted2 = $rate->convert($moneyA2);
            $sumOfConversions = $converted1->add($converted2);

            // Property: convert(a + b) ≈ convert(a) + convert(b) (within rounding)
            $diff = abs((float) $convertedSum->amount() - (float) $sumOfConversions->amount());
            $tolerance = max(0.02, (float) $convertedSum->amount() * 0.001);

            self::assertLessThanOrEqual(
                $tolerance,
                $diff,
                "Linearity failed: convert({$moneyA1->amount()} + {$moneyA2->amount()}) != convert({$moneyA1->amount()}) + convert({$moneyA2->amount()}), diff: {$diff}, tolerance: {$tolerance}"
            );
        }
    }

    /**
     * Property: Converting zero amount yields zero in target currency.
     *
     * Converting zero should always yield zero, regardless of rate.
     */
    public function test_converting_zero_yields_zero(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_EXCHANGE_RATE_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currencyA = $this->randomCurrencyCode();
            $currencyB = $this->randomDistinctCurrency($currencyA);
            $scale = $this->randomScale();

            $rateValue = $this->randomRate();
            $rate = ExchangeRate::fromString($currencyA, $currencyB, $rateValue, $scale);

            $zero = Money::zero($currencyA, $scale);
            $converted = $rate->convert($zero);

            // Property: convert(0) = 0
            self::assertTrue(
                $converted->isZero(),
                "Converting zero failed: converted zero is not zero, got {$converted->amount()}"
            );
            self::assertSame($currencyB, $converted->currency());
        }
    }

    /**
     * Property: Conversion preserves ordering: if a < b, then convert(a) < convert(b).
     *
     * Converting amounts with a positive rate preserves their relative ordering.
     */
    public function test_conversion_preserves_ordering(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_EXCHANGE_RATE_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currencyA = $this->randomCurrencyCode();
            $currencyB = $this->randomDistinctCurrency($currencyA);
            $scale = $this->randomScale();

            $rateValue = $this->randomRate();
            $rate = ExchangeRate::fromString($currencyA, $currencyB, $rateValue, $scale);

            $amount1 = $this->randomUnits($scale);
            // Ensure amounts are meaningfully different to avoid rounding making them equal
            $amount2 = $amount1 + $this->randomizer->getInt(100, 10000);

            $smaller = $this->money($currencyA, $this->formatUnits($amount1, $scale), $scale);
            $larger = $this->money($currencyA, $this->formatUnits($amount2, $scale), $scale);

            $convertedSmaller = $rate->convert($smaller);
            $convertedLarger = $rate->convert($larger);

            // Property: if a < b, then convert(a) < convert(b)
            self::assertTrue(
                $convertedSmaller->lessThan($convertedLarger),
                "Ordering preservation failed: {$smaller->amount()} < {$larger->amount()} but convert({$smaller->amount()}) >= convert({$larger->amount()})"
            );
        }
    }

    /**
     * Property: Conversion scales with multiplier: convert(a * k) = convert(a) * k.
     *
     * Converting a scaled amount is equivalent to scaling the converted amount.
     */
    public function test_conversion_scales_with_multiplier(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_EXCHANGE_RATE_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currencyA = $this->randomCurrencyCode();
            $currencyB = $this->randomDistinctCurrency($currencyA);
            $scale = $this->randomScale();

            $rateValue = $this->randomRate();
            $rate = ExchangeRate::fromString($currencyA, $currencyB, $rateValue, $scale);

            $amount = $this->randomUnits($scale);
            $money = $this->money($currencyA, $this->formatUnits($amount, $scale), $scale);

            // Use a small scalar
            $scalar = $this->formatUnits($this->randomizer->getInt(2, 10), 0);

            // Convert then multiply
            $converted = $rate->convert($money);
            $convertedThenScaled = $converted->multiply($scalar);

            // Multiply then convert
            $scaled = $money->multiply($scalar);
            $scaledThenConverted = $rate->convert($scaled);

            // Property: convert(a * k) ≈ convert(a) * k (within rounding)
            $diff = abs((float) $scaledThenConverted->amount() - (float) $convertedThenScaled->amount());
            $tolerance = max(0.1, (float) $scaledThenConverted->amount() * 0.01);

            self::assertLessThanOrEqual(
                $tolerance,
                $diff,
                "Scaling property failed: convert({$money->amount()} * {$scalar}) != convert({$money->amount()}) * {$scalar}, diff: {$diff}, tolerance: {$tolerance}"
            );
        }
    }

    /**
     * Property: Identity rate (1.0) preserves amount with currency change.
     *
     * A rate of 1.0 from A to B should convert amounts without changing values.
     */
    public function test_identity_rate_preserves_amount(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_EXCHANGE_RATE_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currencyA = $this->randomCurrencyCode();
            $currencyB = $this->randomDistinctCurrency($currencyA);
            $scale = $this->randomScale();

            $identityRate = ExchangeRate::fromString($currencyA, $currencyB, '1.0', $scale);

            $amount = $this->randomUnits($scale);
            $money = $this->money($currencyA, $this->formatUnits($amount, $scale), $scale);

            $converted = $identityRate->convert($money);

            // Property: with rate 1.0, amounts are preserved (only currency changes)
            self::assertSame($money->amount(), $converted->amount());
            self::assertSame($currencyB, $converted->currency());
        }
    }

    /**
     * Property: Chaining rates is transitive: convert A->B->C = convert A->C directly.
     *
     * Converting through intermediate currency should equal direct conversion
     * (when rates are consistent).
     */
    public function test_rate_chain_transitivity(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_EXCHANGE_RATE_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currencyA = $this->randomCurrencyCode();
            $currencyB = $this->randomDistinctCurrency($currencyA);
            $currencyC = $this->randomDistinctCurrency($currencyB);
            // Ensure C is also distinct from A
            while (strtoupper($currencyC) === strtoupper($currencyA)) {
                $currencyC = $this->randomCurrencyCode();
            }

            $scale = $this->randomScale();

            // Rates A->B and B->C
            $rateAB = $this->randomRate();
            $rateBC = $this->randomRate();

            $exchangeAB = ExchangeRate::fromString($currencyA, $currencyB, $rateAB, $scale);
            $exchangeBC = ExchangeRate::fromString($currencyB, $currencyC, $rateBC, $scale);

            // Direct rate A->C = rateAB * rateBC
            $directRate = (float) $rateAB * (float) $rateBC;
            $exchangeAC = ExchangeRate::fromString($currencyA, $currencyC, (string) $directRate, $scale);

            $amount = $this->randomUnits($scale);
            $money = $this->money($currencyA, $this->formatUnits($amount, $scale), $scale);

            // Convert via chain: A -> B -> C
            $convertedAB = $exchangeAB->convert($money);
            $convertedABC = $exchangeBC->convert($convertedAB);

            // Convert direct: A -> C
            $convertedAC = $exchangeAC->convert($money);

            // Property: chain conversion ≈ direct conversion (within rounding)
            // Use relative tolerance because absolute amounts vary widely
            // Allow up to 5% difference due to multiple conversions and float multiplication
            $diff = abs((float) $convertedABC->amount() - (float) $convertedAC->amount());
            $tolerance = max(0.1, (float) $convertedAC->amount() * 0.05);

            self::assertLessThanOrEqual(
                $tolerance,
                $diff,
                "Transitivity failed: A->B->C != A->C directly, diff: {$diff}, tolerance: {$tolerance}"
            );
        }
    }

    /**
     * Property: Inversion swaps base and quote currencies.
     *
     * Inverting a rate should swap the base and quote currencies.
     */
    public function test_inversion_swaps_currencies(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_EXCHANGE_RATE_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currencyA = $this->randomCurrencyCode();
            $currencyB = $this->randomDistinctCurrency($currencyA);
            $scale = $this->randomScale();

            $rateValue = $this->randomRate();
            $rate = ExchangeRate::fromString($currencyA, $currencyB, $rateValue, $scale);
            $inverted = $rate->invert();

            // Property: inversion swaps base and quote
            self::assertSame($currencyA, $rate->baseCurrency());
            self::assertSame($currencyB, $rate->quoteCurrency());
            self::assertSame($currencyB, $inverted->baseCurrency());
            self::assertSame($currencyA, $inverted->quoteCurrency());
        }
    }

    /**
     * Property: Rate and its inverse multiply to approximately 1.
     *
     * rate * invert(rate) ≈ 1 (within rounding error).
     */
    public function test_rate_times_inverse_equals_one(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_EXCHANGE_RATE_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currencyA = $this->randomCurrencyCode();
            $currencyB = $this->randomDistinctCurrency($currencyA);
            $scale = $this->randomScale();

            $rateValue = $this->randomRate();
            $rate = ExchangeRate::fromString($currencyA, $currencyB, $rateValue, $scale);
            $inverted = $rate->invert();

            $product = (float) $rate->rate() * (float) $inverted->rate();

            // Property: rate * inverse ≈ 1.0
            // Allow for larger tolerance due to compounding rounding errors
            // Especially at low scales, rounding can cause significant deviation
            self::assertEqualsWithDelta(
                1.0,
                $product,
                0.1,
                "Rate times inverse failed: {$rate->rate()} * {$inverted->rate()} != 1.0, got {$product}"
            );
        }
    }

    private function randomScale(): int
    {
        // Use higher scales for exchange rates to avoid rounding issues
        // Bias towards common scales for financial calculations
        $commonScales = [6, 8, 12];
        if (0 === $this->randomizer->getInt(0, 1)) {
            return $commonScales[$this->randomizer->getInt(0, count($commonScales) - 1)];
        }

        return $this->randomizer->getInt(6, 18);
    }

    private function randomUnits(int $scale): int
    {
        $upperBound = min($this->safeUnitsUpperBound($scale), 1000000 * $this->powerOfTen($scale));

        return $this->randomizer->getInt(1, $upperBound);
    }

    private function randomRate(): string
    {
        // Generate rates between 0.01 and 1000 to cover realistic scenarios
        // while avoiding extreme values that cause excessive rounding errors
        $exponent = $this->getFloat(-2, 3);
        $rate = max(0.01, 10 ** $exponent * $this->getFloat(1.0, 9.99));

        return (string) $rate;
    }

    /**
     * Get a random float between min and max (PHP 8.2 compatible).
     *
     * @param float $min
     * @param float $max
     * @return float
     */
    private function getFloat(float $min, float $max): float
    {
        // PHP 8.3+ has native getFloat() method
        if (method_exists($this->randomizer, 'getFloat')) {
            return $this->randomizer->getFloat($min, $max);
        }

        // PHP 8.2 fallback: use getInt() and scale
        $precision = 1000000;
        $randomInt = $this->randomizer->getInt(0, $precision);
        $ratio = $randomInt / $precision;

        return $min + ($ratio * ($max - $min));
    }

    private function randomDistinctCurrency(string $existing): string
    {
        do {
            $currency = $this->randomCurrencyCode();
        } while (strtoupper($currency) === strtoupper($existing));

        return $currency;
    }
}
