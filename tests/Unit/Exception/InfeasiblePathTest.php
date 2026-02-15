<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SomeWork\P2PPathFinder\Exception\ExceptionInterface;
use SomeWork\P2PPathFinder\Exception\InfeasiblePath;

#[CoversClass(InfeasiblePath::class)]
final class InfeasiblePathTest extends TestCase
{
    #[TestDox('Can be instantiated with a message')]
    public function test_instantiation_with_message(): void
    {
        $exception = new InfeasiblePath('No viable path exists between USD and BTC.');

        self::assertSame('No viable path exists between USD and BTC.', $exception->getMessage());
    }

    #[TestDox('Implements ExceptionInterface')]
    public function test_implements_exception_interface(): void
    {
        $exception = new InfeasiblePath('Infeasible.');

        self::assertInstanceOf(ExceptionInterface::class, $exception);
    }

    #[TestDox('Extends RuntimeException')]
    public function test_extends_runtime_exception(): void
    {
        $exception = new InfeasiblePath('Infeasible.');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[TestDox('Supports custom error code')]
    public function test_supports_custom_error_code(): void
    {
        $exception = new InfeasiblePath('Infeasible.', 99);

        self::assertSame(99, $exception->getCode());
    }

    #[TestDox('Supports previous exception')]
    public function test_supports_previous_exception(): void
    {
        $previous = new RuntimeException('Root cause.');
        $exception = new InfeasiblePath('Infeasible.', 0, $previous);

        self::assertSame($previous, $exception->getPrevious());
    }
}
