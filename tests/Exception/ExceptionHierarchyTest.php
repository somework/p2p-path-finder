<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SomeWork\P2PPathFinder\Exception\ExceptionInterface;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Exception\InfeasiblePath;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

#[CoversClass(ExceptionInterface::class)]
#[CoversClass(GuardLimitExceeded::class)]
#[CoversClass(InfeasiblePath::class)]
#[CoversClass(InvalidInput::class)]
#[CoversClass(PrecisionViolation::class)]
final class ExceptionHierarchyTest extends TestCase
{
    public function test_guard_limit_exceeded_is_runtime_exception(): void
    {
        $exception = new GuardLimitExceeded('Guard breached.');

        self::assertInstanceOf(ExceptionInterface::class, $exception);
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('Guard breached.', $exception->getMessage());
    }

    public function test_infeasible_path_is_runtime_exception(): void
    {
        $exception = new InfeasiblePath('Infeasible path.');

        self::assertInstanceOf(ExceptionInterface::class, $exception);
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('Infeasible path.', $exception->getMessage());
    }

    public function test_precision_violation_is_runtime_exception(): void
    {
        $exception = new PrecisionViolation('Precision violation.');

        self::assertInstanceOf(ExceptionInterface::class, $exception);
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('Precision violation.', $exception->getMessage());
    }

    public function test_invalid_input_is_invalid_argument_exception(): void
    {
        $exception = new InvalidInput('Invalid input.');

        self::assertInstanceOf(ExceptionInterface::class, $exception);
        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
        self::assertSame('Invalid input.', $exception->getMessage());
    }
}
