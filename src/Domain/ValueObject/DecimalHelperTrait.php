<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\ValueObject;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function sprintf;

trait DecimalHelperTrait
{
    private static function assertScale(int $scale): void
    {
        if ($scale < 0) {
            throw new InvalidInput('Scale must be a non-negative integer.');
        }
    }

    private static function decimalFromString(string $value): BigDecimal
    {
        try {
            return BigDecimal::of($value);
        } catch (MathException $exception) {
            throw new InvalidInput(sprintf('Value "%s" is not numeric.', $value), 0, $exception);
        }
    }

    private static function scaleDecimal(BigDecimal $decimal, int $scale): BigDecimal
    {
        self::assertScale($scale);

        return $decimal->toScale($scale, RoundingMode::HALF_UP);
    }

    /**
     * @return numeric-string
     */
    private static function decimalToString(BigDecimal $decimal, int $scale): string
    {
        /** @var numeric-string $result */
        $result = self::scaleDecimal($decimal, $scale)->__toString();

        return $result;
    }
}
