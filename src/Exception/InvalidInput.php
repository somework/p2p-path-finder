<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Exception;

use InvalidArgumentException as BaseInvalidArgumentException;

/**
 * Thrown when a consumer supplies malformed or unsupported input.
 */
final class InvalidInput extends BaseInvalidArgumentException implements ExceptionInterface
{
}
