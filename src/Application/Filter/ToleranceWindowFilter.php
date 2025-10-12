<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Filter;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;

final class ToleranceWindowFilter implements OrderFilterInterface
{
    private readonly string $lowerBound;
    private readonly string $upperBound;
    private readonly int $scale;

    public function __construct(private readonly ExchangeRate $referenceRate, string $tolerance)
    {
        BcMath::ensureNumeric($tolerance);
        $this->scale = $referenceRate->scale();
        $normalizedTolerance = BcMath::normalize($tolerance, $this->scale);

        if (-1 === BcMath::comp($normalizedTolerance, '0', $this->scale)) {
            throw new InvalidArgumentException('Tolerance cannot be negative.');
        }

        $offset = BcMath::mul($referenceRate->rate(), $normalizedTolerance, $this->scale);

        $min = BcMath::sub($referenceRate->rate(), $offset, $this->scale);
        if (-1 === BcMath::comp($min, '0', $this->scale)) {
            $min = BcMath::normalize('0', $this->scale);
        }

        $this->lowerBound = $min;
        $this->upperBound = BcMath::add($referenceRate->rate(), $offset, $this->scale);
    }

    public function accepts(Order $order): bool
    {
        $effective = $order->effectiveRate();
        if ($effective->baseCurrency() !== $this->referenceRate->baseCurrency()
            || $effective->quoteCurrency() !== $this->referenceRate->quoteCurrency()
        ) {
            return false;
        }

        $rate = BcMath::normalize($effective->rate(), $this->scale);

        if (-1 === BcMath::comp($rate, $this->lowerBound, $this->scale)) {
            return false;
        }

        if (1 === BcMath::comp($rate, $this->upperBound, $this->scale)) {
            return false;
        }

        return true;
    }
}
