<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use Generator;

use function ltrim;
use function max;
use function random_int;
use function sprintf;
use function str_repeat;

final class NumericStringGenerator
{
    private const MIN_SCALE = 18;

    private const MAX_SCALE = 36;

    private const MAX_INTEGER_DIGITS = 36;

    private const FRACTION_EXCESS_MAX = 6;

    /**
     * @return Generator<int, array{numeric-string, int}>
     */
    public static function decimals(int $samples = 64): Generator
    {
        for ($index = 0; $index < $samples; ++$index) {
            $scale = random_int(self::MIN_SCALE, self::MAX_SCALE);

            yield [self::decimalValue($scale), $scale];
        }
    }

    /**
     * @return Generator<int, array{numeric-string, numeric-string, int}>
     */
    public static function decimalPairs(int $samples = 64): Generator
    {
        for ($index = 0; $index < $samples; ++$index) {
            $scale = random_int(self::MIN_SCALE, self::MAX_SCALE);

            yield [self::decimalValue($scale), self::decimalValue($scale, true), $scale];
        }
    }

    /**
     * @return Generator<int, array{numeric-string, int}>
     */
    public static function toleranceRatios(int $samples = 64): Generator
    {
        for ($index = 0; $index < $samples; ++$index) {
            $scale = random_int(self::MIN_SCALE, self::MAX_SCALE);

            yield [self::toleranceValue($scale), $scale];
        }
    }

    private static function decimalValue(int $scale, bool $nonZero = false): string
    {
        do {
            $integerDigits = random_int(0, self::MAX_INTEGER_DIGITS);
            $integerPart = self::randomIntegerDigits($integerDigits);
            $fractionDigits = $scale + random_int(0, self::FRACTION_EXCESS_MAX);
            $fractionDigits = max($fractionDigits, self::MIN_SCALE);
            $fractionPart = self::randomFractionDigits($fractionDigits);
            $sign = 1 === random_int(0, 1) ? '-' : '';
            $value = sprintf('%s%s.%s', $sign, $integerPart, $fractionPart);
        } while ($nonZero && self::isZero($integerPart, $fractionPart));

        return $value;
    }

    private static function toleranceValue(int $scale): string
    {
        $fractionDigits = $scale + random_int(0, self::FRACTION_EXCESS_MAX);
        $fractionDigits = max($fractionDigits, self::MIN_SCALE);

        if (0 === random_int(0, 10)) {
            return '1.'.str_repeat('0', $fractionDigits);
        }

        return '0.'.self::randomFractionDigits($fractionDigits);
    }

    private static function randomIntegerDigits(int $length): string
    {
        if (0 === $length) {
            return '0';
        }

        $digits = (string) random_int(1, 9);

        for ($index = 1; $index < $length; ++$index) {
            $digits .= (string) random_int(0, 9);
        }

        return $digits;
    }

    private static function randomFractionDigits(int $length): string
    {
        $digits = '';

        for ($index = 0; $index < $length; ++$index) {
            $digits .= (string) random_int(0, 9);
        }

        return $digits;
    }

    private static function isZero(string $integerPart, string $fractionPart): bool
    {
        return '0' === $integerPart && '' === ltrim($fractionPart, '0');
    }
}
