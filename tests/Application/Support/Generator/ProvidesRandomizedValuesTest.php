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
