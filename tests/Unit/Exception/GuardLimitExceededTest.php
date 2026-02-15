<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SomeWork\P2PPathFinder\Exception\ExceptionInterface;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;

#[CoversClass(GuardLimitExceeded::class)]
final class GuardLimitExceededTest extends TestCase
{
    #[TestDox('Can be instantiated with a message')]
    public function test_instantiation_with_message(): void
    {
        $exception = new GuardLimitExceeded('Search exceeded expansion limit.');

        self::assertSame('Search exceeded expansion limit.', $exception->getMessage());
    }

    #[TestDox('Implements ExceptionInterface')]
    public function test_implements_exception_interface(): void
    {
        $exception = new GuardLimitExceeded('Guard limit.');

        self::assertInstanceOf(ExceptionInterface::class, $exception);
    }

    #[TestDox('Extends RuntimeException')]
    public function test_extends_runtime_exception(): void
    {
        $exception = new GuardLimitExceeded('Guard limit.');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[TestDox('Supports custom error code')]
    public function test_supports_custom_error_code(): void
    {
        $exception = new GuardLimitExceeded('Guard limit.', 42);

        self::assertSame(42, $exception->getCode());
    }

    #[TestDox('Supports previous exception')]
    public function test_supports_previous_exception(): void
    {
        $previous = new RuntimeException('Original error.');
        $exception = new GuardLimitExceeded('Guard limit.', 0, $previous);

        self::assertSame($previous, $exception->getPrevious());
    }
}
