<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Support;

use SomeWork\P2PPathFinder\Domain\Money\Money;

/**
 * Shared helpers for normalizing money value objects during serialization.
 *
 * @internal
 */
trait SerializesMoney
{
    /**
     * @return array{currency: string, amount: numeric-string, scale: int}
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
