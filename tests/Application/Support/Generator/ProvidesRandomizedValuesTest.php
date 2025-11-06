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

    private function helper(): object
    {
        return new class {
            use ProvidesRandomizedValues {
                parseUnits as public;
            }

            protected function randomizer(): Randomizer
            {
                return new Randomizer(new Mt19937());
            }
        };
    }
}
