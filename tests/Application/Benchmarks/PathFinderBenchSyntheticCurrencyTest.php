<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Benchmarks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Benchmarks\PathFinderBench;

use function preg_match;
use function strlen;

#[CoversClass(PathFinderBench::class)]
final class PathFinderBenchSyntheticCurrencyTest extends TestCase
{
    public function test_synthetic_currency_generator_provides_unique_codes_beyond_triple_length(): void
    {
        $bench = new PathFinderBench();
        /** @var callable(int&):string $next */
        $next = (static function (PathFinderBench $bench): callable {
            /** @var callable(int&):string $callable */
            $callable = (fn (int &$cursor): string => $this->syntheticCurrency($cursor))->bindTo($bench, PathFinderBench::class);

            return $callable;
        })($bench);

        $counter = 0;
        $first = $next($counter);
        $second = $next($counter);
        $third = $next($counter);

        self::assertSame('AAA', $first);
        self::assertSame('AAB', $second);
        self::assertSame('AAC', $third);

        $counter = 17575;
        $preWrap = $next($counter);
        $wrap = $next($counter);
        $postWrap = $next($counter);

        self::assertNotSame($preWrap, $wrap);
        self::assertNotSame($wrap, $postWrap);
        self::assertSame(3, strlen($preWrap));
        self::assertGreaterThan(3, strlen($wrap));
        self::assertGreaterThanOrEqual(strlen($wrap), strlen($postWrap));
        self::assertSame(0, preg_match('/[^A-Z]/', $wrap));
        self::assertSame(0, preg_match('/[^A-Z]/', $postWrap));
        self::assertNotSame('SRC', $wrap);
        self::assertNotSame('DST', $wrap);
    }
}
