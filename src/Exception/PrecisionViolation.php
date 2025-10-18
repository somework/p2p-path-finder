<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Exception;

use RuntimeException;

/**
 * Thrown when deterministic arithmetic guarantees cannot be upheld.
 */
final class PrecisionViolation extends RuntimeException implements ExceptionInterface
{
}
