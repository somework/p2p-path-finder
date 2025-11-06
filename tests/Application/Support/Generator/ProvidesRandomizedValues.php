<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Support\Generator;

use Random\Randomizer;

use function chr;
use function explode;
use function intdiv;
use function max;
use function ord;
use function str_pad;
use function substr;

use const PHP_INT_MAX;
use const STR_PAD_LEFT;
use const STR_PAD_RIGHT;

/**
 * Common helpers for generator fixtures that rely on {@see Randomizer}.
 */
trait ProvidesRandomizedValues
{
    abstract protected function randomizer(): Randomizer;

    /**
     * @return non-empty-string
     */
    private function randomCurrencyCode(): string
    {
        $code = '';

        for ($index = 0; $index < 3; ++$index) {
            $code .= chr($this->randomizer()->getInt(ord('A'), ord('Z')));
        }

        return $code;
    }

    private function powerOfTen(int $scale): int
    {
        return (int) (10 ** $scale);
    }

    private function safeUnitsUpperBound(int $scale, int $maxFactor = 9): int
    {
        $base = $this->powerOfTen($scale);
        $factor = max(1, intdiv(PHP_INT_MAX - 1, $base));
        $factor = max(1, min($maxFactor, $factor));

        return $base * $factor;
    }

    private function formatUnits(int $units, int $scale): string
    {
        if (0 === $scale) {
            return (string) $units;
        }

        $divisor = $this->powerOfTen($scale);
        $integer = intdiv($units, $divisor);
        $fraction = $units % $divisor;

        /** @var numeric-string $formatted */
        $formatted = $integer.'.'.str_pad((string) $fraction, $scale, '0', STR_PAD_LEFT);

        return $formatted;
    }

    private function parseUnits(string $value, int $scale): int
    {
        if (0 === $scale) {
            return (int) $value;
        }

        $parts = explode('.', $value, 2);
        $integer = (int) ($parts[0] ?? '0');
        $fraction = $parts[1] ?? '';
        $fraction = substr($fraction, 0, $scale);
        $fraction = str_pad($fraction, $scale, '0', STR_PAD_RIGHT);

        return $integer * $this->powerOfTen($scale) + (int) $fraction;
    }
}
