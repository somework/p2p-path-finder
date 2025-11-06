<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Support\Generator;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * @covers \SomeWork\P2PPathFinder\Tests\Application\Support\Generator\ProvidesRandomizedValues
 */
final class ProvidesRandomizedValuesTest extends TestCase
{
    public function test_parse_units_pads_fractional_components_to_scale(): void
    {
        $helper = $this->helper();

        self::assertSame(1200, $helper->parseUnits('1.2', 3));
    }

    public function test_parse_units_preserves_negative_sign_for_fractional_values(): void
    {
        $helper = $this->helper();

        self::assertSame(-500, $helper->parseUnits('-.5', 3));
    }

    /**
     * @param non-empty-string $value
     *
     * @dataProvider provideUnitParsingSamples
     */
    public function test_parse_units_handles_various_inputs(string $value, int $scale, int $expected): void
    {
        $helper = $this->helper();

        self::assertSame($expected, $helper->parseUnits($value, $scale));
    }

    /**
     * @return iterable<array{string, int, int}>
     */
    public static function provideUnitParsingSamples(): iterable
    {
        yield 'integer scaled to precision' => ['5', 3, 5000];
        yield 'zero at higher precision' => ['0', 3, 0];
        yield 'integer without scale' => ['123', 0, 123];
        yield 'value already at scale precision' => ['1.000', 3, 1000];
        yield 'single decimal place' => ['9.9', 1, 99];
        yield 'expanded fractional padding' => ['12.34', 4, 123400];
        yield 'truncates excess fractional precision' => ['7.1234567', 6, 7123456];
        yield 'large number within bounds' => ['123456789.987654', 6, 123456789987654];
    }

    public function test_random_currency_code_uses_seeded_randomizer(): void
    {
        $helper = $this->helper();

        self::assertSame('IPZ', $helper->randomCurrencyCode());
        self::assertSame('DSZ', $helper->randomCurrencyCode());
    }

    /**
     * @param int<0, max> $scale
     *
     * @dataProvider providePowerOfTenSamples
     */
    public function test_power_of_ten_returns_expected_values(int $scale, int $expected): void
    {
        $helper = $this->helper();

        self::assertSame($expected, $helper->powerOfTen($scale));
    }

    /**
     * @return iterable<array{int, int}>
     */
    public static function providePowerOfTenSamples(): iterable
    {
        yield 'zero scale' => [0, 1];
        yield 'single digit scale' => [1, 10];
        yield 'thousands scale' => [3, 1000];
    }

    /**
     * @param int<0, max>       $scale
     * @param positive-int|null $maxFactor
     *
     * @dataProvider provideSafeUnitsUpperBoundSamples
     */
    public function test_safe_units_upper_bound_limits_units(int $scale, int $expected, ?int $maxFactor): void
    {
        $helper = $this->helper();

        $actual = null === $maxFactor
            ? $helper->safeUnitsUpperBound($scale)
            : $helper->safeUnitsUpperBound($scale, $maxFactor);

        self::assertSame($expected, $actual);
    }

    /**
     * @return iterable<array{int, int, positive-int|null}>
     */
    public static function provideSafeUnitsUpperBoundSamples(): iterable
    {
        yield 'scale zero defaults to nine' => [0, 9, null];
        yield 'scale one caps at max factor' => [1, 90, null];
        yield 'scale six caps at nine million' => [6, 9000000, null];
        yield 'custom factor respected' => [2, 300, 3];
    }

    /**
     * @param int<0, max> $scale
     *
     * @dataProvider provideFormatUnitsSamples
     */
    public function test_format_units_produces_decimal_strings(int $units, int $scale, string $expected): void
    {
        $helper = $this->helper();

        self::assertSame($expected, $helper->formatUnits($units, $scale));
    }

    /**
     * @return iterable<array{int, int, string}>
     */
    public static function provideFormatUnitsSamples(): iterable
    {
        yield 'zero scale returns integer string' => [1234, 0, '1234'];
        yield 'zero with fractional scale' => [0, 3, '0.000'];
        yield 'two decimal places' => [1234, 2, '12.34'];
        yield 'fractional padding' => [50, 3, '0.050'];
    }

    private function helper(): object
    {
        return new class {
            use ProvidesRandomizedValues {
                parseUnits as public;
                randomCurrencyCode as public;
                powerOfTen as public;
                safeUnitsUpperBound as public;
                formatUnits as public;
            }

            private Randomizer $randomizer;

            public function __construct()
            {
                $this->randomizer = new Randomizer(new Mt19937(12345));
            }

            protected function randomizer(): Randomizer
            {
                return $this->randomizer;
            }
        };
    }
}
