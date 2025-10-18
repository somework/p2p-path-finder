<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Support;

use function ctype_digit;
use function getenv;

trait InfectionIterationLimiter
{
    private function iterationLimit(int $default, int $infectionLimit = 5, ?string $overrideEnv = null): int
    {
        $limit = $default;

        if (null !== $overrideEnv) {
            $override = getenv($overrideEnv);
            if (false !== $override && ctype_digit($override)) {
                $candidate = (int) $override;
                if ($candidate > 0) {
                    $limit = min($default, $candidate);
                }
            }
        }

        if (false !== getenv('INFECTION')) {
            $limit = min($limit, $infectionLimit);
        }

        return $limit;
    }
}
