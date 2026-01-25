<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Exception;

use InvalidArgumentException as BaseInvalidArgumentException;

/**
 * Thrown when a consumer supplies malformed or unsupported input.
 */
final class InvalidInput extends BaseInvalidArgumentException implements ExceptionInterface
{
    /**
     * Creates an exception for attempting to convert a non-linear execution plan to a Path.
     *
     * Non-linear plans contain splits or merges and cannot be represented as a sequential Path.
     */
    public static function forNonLinearPlan(): self
    {
        return new self('Cannot convert non-linear execution plan to Path. Use ExecutionPlan directly.');
    }

    /**
     * Creates an exception for attempting to convert an empty execution plan to a Path.
     */
    public static function forEmptyPlan(): self
    {
        return new self('Cannot convert empty execution plan to Path.');
    }
}
