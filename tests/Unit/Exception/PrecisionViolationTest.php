<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SomeWork\P2PPathFinder\Exception\ExceptionInterface;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

#[CoversClass(PrecisionViolation::class)]
final class PrecisionViolationTest extends TestCase
{
    #[TestDox('Can be instantiated with a message')]
    public function test_instantiation_with_message(): void
    {
        $exception = new PrecisionViolation('Scale exceeds maximum allowed precision.');

        self::assertSame('Scale exceeds maximum allowed precision.', $exception->getMessage());
    }

    #[TestDox('Implements ExceptionInterface')]
    public function test_implements_exception_interface(): void
    {
        $exception = new PrecisionViolation('Precision issue.');

        self::assertInstanceOf(ExceptionInterface::class, $exception);
    }

    #[TestDox('Extends RuntimeException')]
    public function test_extends_runtime_exception(): void
    {
        $exception = new PrecisionViolation('Precision issue.');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[TestDox('Supports custom error code')]
    public function test_supports_custom_error_code(): void
    {
        $exception = new PrecisionViolation('Precision issue.', 7);

        self::assertSame(7, $exception->getCode());
    }

    #[TestDox('Supports previous exception')]
    public function test_supports_previous_exception(): void
    {
        $previous = new RuntimeException('Arithmetic overflow.');
        $exception = new PrecisionViolation('Precision issue.', 0, $previous);

        self::assertSame($previous, $exception->getPrevious());
    }
}
