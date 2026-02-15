<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine\Ordering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

#[CoversClass(RouteSignature::class)]
final class RouteSignatureTest extends TestCase
{
    #[TestDox('fromNodes() with multiple nodes creates arrow-delimited value')]
    public function test_from_nodes_creates_arrow_delimited_value(): void
    {
        $signature = RouteSignature::fromNodes(['USD', 'EUR', 'GBP']);

        self::assertSame('USD->EUR->GBP', $signature->value());
    }

    #[TestDox('fromNodes() with empty array creates empty value')]
    public function test_from_nodes_with_empty_array_creates_empty_value(): void
    {
        $signature = RouteSignature::fromNodes([]);

        self::assertSame('', $signature->value());
        self::assertSame([], $signature->nodes());
    }

    #[TestDox('fromNodes() with single node creates value without arrows')]
    public function test_from_nodes_with_single_node(): void
    {
        $signature = RouteSignature::fromNodes(['USD']);

        self::assertSame('USD', $signature->value());
        self::assertSame(['USD'], $signature->nodes());
    }

    #[TestDox('nodes() returns the list of trimmed node strings')]
    public function test_nodes_returns_list_of_nodes(): void
    {
        $signature = RouteSignature::fromNodes(['USD', 'EUR', 'GBP']);

        self::assertSame(['USD', 'EUR', 'GBP'], $signature->nodes());
    }

    #[TestDox('value() returns arrow-delimited string representation')]
    public function test_value_returns_arrow_delimited_string(): void
    {
        $signature = RouteSignature::fromNodes(['A', 'B', 'C', 'D']);

        self::assertSame('A->B->C->D', $signature->value());
    }

    #[TestDox('equals() returns true for signatures with same nodes')]
    public function test_equals_returns_true_for_same_route(): void
    {
        $a = RouteSignature::fromNodes(['USD', 'EUR', 'GBP']);
        $b = RouteSignature::fromNodes(['USD', 'EUR', 'GBP']);

        self::assertTrue($a->equals($b));
    }

    #[TestDox('equals() returns false for signatures with different nodes')]
    public function test_equals_returns_false_for_different_route(): void
    {
        $a = RouteSignature::fromNodes(['USD', 'EUR', 'GBP']);
        $b = RouteSignature::fromNodes(['USD', 'JPY', 'GBP']);

        self::assertFalse($a->equals($b));
    }

    #[TestDox('compare() returns 0 for equal signatures')]
    public function test_compare_returns_zero_for_equal(): void
    {
        $a = RouteSignature::fromNodes(['USD', 'EUR']);
        $b = RouteSignature::fromNodes(['USD', 'EUR']);

        self::assertSame(0, $a->compare($b));
    }

    #[TestDox('compare() returns negative for alphabetically lesser signature')]
    public function test_compare_returns_negative_for_lesser(): void
    {
        $lesser = RouteSignature::fromNodes(['AAA', 'BBB']);
        $greater = RouteSignature::fromNodes(['ZZZ', 'YYY']);

        self::assertLessThan(0, $lesser->compare($greater));
    }

    #[TestDox('compare() returns positive for alphabetically greater signature')]
    public function test_compare_returns_positive_for_greater(): void
    {
        $lesser = RouteSignature::fromNodes(['AAA', 'BBB']);
        $greater = RouteSignature::fromNodes(['ZZZ', 'YYY']);

        self::assertGreaterThan(0, $greater->compare($lesser));
    }

    #[TestDox('__toString() matches value()')]
    public function test_to_string_matches_value(): void
    {
        $signature = RouteSignature::fromNodes(['A', 'B', 'C']);

        self::assertSame($signature->value(), (string) $signature);
    }

    #[TestDox('Rejects empty node string with InvalidInput exception')]
    public function test_rejects_empty_node_string(): void
    {
        self::expectException(InvalidInput::class);

        RouteSignature::fromNodes(['USD', '', 'GBP']);
    }

    #[TestDox('Rejects whitespace-only node with InvalidInput exception')]
    public function test_rejects_whitespace_only_node(): void
    {
        self::expectException(InvalidInput::class);

        RouteSignature::fromNodes(['USD', '   ', 'GBP']);
    }

    #[TestDox('Trims whitespace from node strings')]
    public function test_trims_whitespace_from_nodes(): void
    {
        $signature = RouteSignature::fromNodes([' USD ', '  EUR  ', 'GBP ']);

        self::assertSame(['USD', 'EUR', 'GBP'], $signature->nodes());
        self::assertSame('USD->EUR->GBP', $signature->value());
    }
}
