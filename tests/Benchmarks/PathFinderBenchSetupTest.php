<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Benchmarks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Benchmarks\PathFinderBench;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;

use function iterator_to_array;
use function spl_object_id;

#[CoversClass(PathFinderBench::class)]
final class PathFinderBenchSetupTest extends TestCase
{
    public function test_base_order_set_is_rebuilt_from_cached_prototypes(): void
    {
        $bench = new PathFinderBench();

        /** @var callable(): list<Order> $factory */
        $factory = (static function (PathFinderBench $bench): callable {
            /** @var callable(): list<Order> $callable */
            $callable = (fn (): array => $this->createBaseOrderSet())->bindTo($bench, PathFinderBench::class);

            return $callable;
        })($bench);

        $first = $factory();
        $second = $factory();

        self::assertCount(3, $first);
        self::assertCount(3, $second);

        foreach ($first as $index => $order) {
            self::assertNotSame($order, $second[$index]);
            self::assertSame($order->assetPair()->base(), $second[$index]->assetPair()->base());
            self::assertSame($order->assetPair()->quote(), $second[$index]->assetPair()->quote());
            self::assertSame($order->bounds()->min()->amount(), $second[$index]->bounds()->min()->amount());
            self::assertSame($order->bounds()->max()->amount(), $second[$index]->bounds()->max()->amount());
            self::assertSame($order->effectiveRate()->rate(), $second[$index]->effectiveRate()->rate());
            self::assertSame($order->effectiveRate()->scale(), $second[$index]->effectiveRate()->scale());

            self::assertSame(
                spl_object_id($order->assetPair()),
                spl_object_id($second[$index]->assetPair()),
            );
            self::assertSame(
                spl_object_id($order->bounds()->min()),
                spl_object_id($second[$index]->bounds()->min()),
            );
            self::assertSame(
                spl_object_id($order->bounds()->max()),
                spl_object_id($second[$index]->bounds()->max()),
            );
            self::assertSame(
                spl_object_id($order->effectiveRate()),
                spl_object_id($second[$index]->effectiveRate()),
            );
        }
    }

    public function test_dense_order_book_remains_deterministic_across_invocations(): void
    {
        $bench = new PathFinderBench();

        /** @var callable(int, int): OrderBook $builder */
        $builder = (static function (PathFinderBench $bench): callable {
            /** @var callable(int, int): OrderBook $callable */
            $callable = (fn (int $depth, int $fanout): OrderBook => $this->buildDenseOrderBook($depth, $fanout))
                ->bindTo($bench, PathFinderBench::class);

            return $callable;
        })($bench);

        $first = $builder(2, 3);
        $second = $builder(2, 3);

        $firstOrders = iterator_to_array($first, false);
        $secondOrders = iterator_to_array($second, false);

        $expected = $this->expectedDenseOrderCount(2, 3);

        self::assertCount($expected, $firstOrders);
        self::assertCount($expected, $secondOrders);

        foreach ($firstOrders as $index => $order) {
            self::assertNotSame($order, $secondOrders[$index]);
        }

        self::assertSame(
            spl_object_id($firstOrders[0]->effectiveRate()),
            spl_object_id($secondOrders[0]->effectiveRate()),
        );
        self::assertSame(
            spl_object_id($firstOrders[0]->bounds()->min()),
            spl_object_id($secondOrders[0]->bounds()->min()),
        );
        self::assertSame(
            spl_object_id($firstOrders[0]->bounds()->max()),
            spl_object_id($secondOrders[0]->bounds()->max()),
        );

        $lastIndex = $expected - 1;
        self::assertSame('DST', $firstOrders[$lastIndex]->assetPair()->base());
        self::assertSame('DST', $secondOrders[$lastIndex]->assetPair()->base());
        self::assertSame(
            spl_object_id($firstOrders[$lastIndex]->effectiveRate()),
            spl_object_id($secondOrders[$lastIndex]->effectiveRate()),
        );
    }

    public function test_k_best_order_book_remains_deterministic_across_invocations(): void
    {
        $bench = new PathFinderBench();

        /** @var callable(int): OrderBook $builder */
        $builder = (static function (PathFinderBench $bench): callable {
            /** @var callable(int): OrderBook $callable */
            $callable = (fn (int $orderCount): OrderBook => $this->buildKBestOrderBook($orderCount))
                ->bindTo($bench, PathFinderBench::class);

            return $callable;
        })($bench);

        $first = $builder(10);
        $second = $builder(10);

        $firstOrders = iterator_to_array($first, false);
        $secondOrders = iterator_to_array($second, false);

        $expected = $this->expectedKBestOrderCount(10);

        self::assertCount($expected, $firstOrders);
        self::assertCount($expected, $secondOrders);

        foreach ($firstOrders as $index => $order) {
            self::assertNotSame($order, $secondOrders[$index]);
            self::assertSame(
                spl_object_id($order->effectiveRate()),
                spl_object_id($secondOrders[$index]->effectiveRate()),
            );
        }

        self::assertSame('SRC', $firstOrders[0]->assetPair()->base());
        self::assertSame('SRC', $secondOrders[0]->assetPair()->base());
        self::assertSame('DST', $firstOrders[$expected - 1]->assetPair()->quote());
        self::assertSame('DST', $secondOrders[$expected - 1]->assetPair()->quote());
    }

    private function expectedDenseOrderCount(int $depth, int $fanout): int
    {
        $total = 0;

        for ($layer = 1; $layer <= $depth; ++$layer) {
            $total += $fanout ** $layer;
        }

        return $total + $fanout ** $depth;
    }

    private function expectedKBestOrderCount(int $orderCount): int
    {
        return intdiv($orderCount, 2) * 2;
    }
}
