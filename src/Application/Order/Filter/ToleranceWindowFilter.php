<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Order\Filter;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Order\Filter\OrderFilterInterface;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function sprintf;

/**
 * Accepts orders whose effective rates fall within a tolerance window around a reference rate.
 */
final class ToleranceWindowFilter implements OrderFilterInterface
{
    private readonly BigDecimal $lowerBound;

    private readonly BigDecimal $upperBound;

    private readonly int $scale;

    /**
     * @param numeric-string $tolerance
     *
     * @throws InvalidInput|PrecisionViolation when the tolerance cannot be normalized into a non-negative ratio
     */
    public function __construct(private readonly ExchangeRate $referenceRate, string $tolerance)
    {
        $this->scale = $referenceRate->scale();

        $referenceDecimal = self::scaleDecimal($referenceRate->decimal(), $this->scale);
        $normalizedTolerance = self::scaleDecimal(self::decimalFromString($tolerance), $this->scale);

        if ($normalizedTolerance->compareTo(BigDecimal::zero()) < 0) {
            throw new InvalidInput('Tolerance cannot be negative.');
        }

        $offset = self::scaleDecimal($referenceDecimal->multipliedBy($normalizedTolerance), $this->scale);

        $min = $referenceDecimal->minus($offset);
        $this->lowerBound = $min->compareTo(BigDecimal::zero()) < 0
            ? self::scaleDecimal(BigDecimal::zero(), $this->scale)
            : self::scaleDecimal($min, $this->scale);

        $this->upperBound = self::scaleDecimal($referenceDecimal->plus($offset), $this->scale);
    }

    public function accepts(Order $order): bool
    {
        $effective = $order->effectiveRate();
        if ($effective->baseCurrency() !== $this->referenceRate->baseCurrency()
            || $effective->quoteCurrency() !== $this->referenceRate->quoteCurrency()
        ) {
            return false;
        }

        $rate = self::scaleDecimal($effective->decimal(), $this->scale);

        if ($rate->compareTo($this->lowerBound) < 0) {
            return false;
        }

        if ($rate->compareTo($this->upperBound) > 0) {
            return false;
        }

        return true;
    }

    /**
     * Maximum allowed scale to prevent memory exhaustion and performance degradation.
     */
    private const MAX_SCALE = 50;

    private static function assertScale(int $scale): void
    {
        if ($scale < 0) {
            throw new InvalidInput('Scale cannot be negative.');
        }
        if ($scale > self::MAX_SCALE) {
            throw new InvalidInput(sprintf('Scale cannot exceed %d decimal places.', self::MAX_SCALE));
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

        return $decimal->toScale($scale, RoundingMode::HalfUp);
    }
}
