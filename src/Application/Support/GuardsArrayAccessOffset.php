<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Support;

use function is_int;
use function is_string;

/**
 * Helper methods for normalizing array access offsets to primitive types.
 */
trait GuardsArrayAccessOffset
{
    private function normalizeStringOffset(mixed $offset): ?string
    {
        if (!is_string($offset)) {
            return null;
        }

        return $offset;
    }

    private function normalizeIntegerOffset(mixed $offset): ?int
    {
        if (!is_int($offset)) {
            return null;
        }

        return $offset;
    }
}
