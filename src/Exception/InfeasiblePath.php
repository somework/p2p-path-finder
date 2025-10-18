<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Exception;

use RuntimeException;

/**
 * Thrown when path materialisation fails due to unmet constraints.
 */
final class InfeasiblePath extends RuntimeException
{
}
