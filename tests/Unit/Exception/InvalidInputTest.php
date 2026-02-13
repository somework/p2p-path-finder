<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

#[CoversClass(InvalidInput::class)]
final class InvalidInputTest extends TestCase
{
    #[TestDox('forNonLinearPlan() returns InvalidInput with expected message')]
    public function test_for_non_linear_plan_returns_exception_with_message(): void
    {
        $exception = InvalidInput::forNonLinearPlan();

        self::assertInstanceOf(InvalidInput::class, $exception);
        self::assertSame(
            'Cannot convert non-linear execution plan to Path. Use ExecutionPlan directly.',
            $exception->getMessage(),
        );
    }

    #[TestDox('forEmptyPlan() returns InvalidInput with expected message')]
    public function test_for_empty_plan_returns_exception_with_message(): void
    {
        $exception = InvalidInput::forEmptyPlan();

        self::assertInstanceOf(InvalidInput::class, $exception);
        self::assertSame(
            'Cannot convert empty execution plan to Path.',
            $exception->getMessage(),
        );
    }
}
