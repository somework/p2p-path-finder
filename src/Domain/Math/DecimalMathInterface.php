<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\Math;

/**
 * Describes deterministic decimal math operations required by the domain layer.
 */
interface DecimalMathInterface
{
    public const DEFAULT_SCALE = 8;

    /**
     * @phpstan-assert numeric-string $values
     *
     * @psalm-assert numeric-string $values
     */
    public function ensureNumeric(string ...$values): void;

    public function isNumeric(string $value): bool;

    /**
     * @param numeric-string $value
     *
     * @return numeric-string
     */
    public function normalize(string $value, int $scale): string;

    /**
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @return numeric-string
     */
    public function add(string $left, string $right, int $scale): string;

    /**
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @return numeric-string
     */
    public function sub(string $left, string $right, int $scale): string;

    /**
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @return numeric-string
     */
    public function mul(string $left, string $right, int $scale): string;

    /**
     * @param numeric-string $left
     * @param numeric-string $right
     *
     * @return numeric-string
     */
    public function div(string $left, string $right, int $scale): string;

    /**
     * @param numeric-string $left
     * @param numeric-string $right
     */
    public function comp(string $left, string $right, int $scale): int;

    /**
     * @param numeric-string $value
     *
     * @return numeric-string
     */
    public function round(string $value, int $scale): string;

    /**
     * @param numeric-string $first
     * @param numeric-string $second
     */
    public function scaleForComparison(string $first, string $second, int $fallbackScale = self::DEFAULT_SCALE): int;
}
