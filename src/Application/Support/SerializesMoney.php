<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Support;

use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

/**
 * Shared helpers for normalizing money value objects during serialization.
 */
trait SerializesMoney
{
    /**
     * @return array{currency: string, amount: string, scale: int}
     */
    private static function serializeMoney(Money $money): array
    {
        return [
            'currency' => $money->currency(),
            'amount' => $money->amount(),
            'scale' => $money->scale(),
        ];
    }
}
