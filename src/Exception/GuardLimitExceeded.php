<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Exception;

use RuntimeException;

/**
 * Thrown when search guard rails are exceeded before a path can be materialised.
 */
final class GuardLimitExceeded extends RuntimeException implements ExceptionInterface
{
}
