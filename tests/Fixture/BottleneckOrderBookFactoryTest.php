<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Fixture;

use PHPUnit\Framework\TestCase;

use function chr;
use function count;
use function ord;

final class BottleneckOrderBookFactoryTest extends TestCase
{
    public function test_create_returns_deterministic_order_book_instances(): void
    {
        $first = BottleneckOrderBookFactory::create();
        $second = BottleneckOrderBookFactory::create();
        $expectedOrders = $this->buildExpectedBottleneckOrders();

        $firstOrders = iterator_to_array($first);
        $secondOrders = iterator_to_array($second);

        self::assertNotSame($first, $second);
        self::assertSame($expectedOrders, $firstOrders);
        self::assertSame($expectedOrders, $secondOrders);

        $second->add(OrderFactory::sell('SRC', 'CLONECHK', '1.000', '1.000', '1.000', 3, 3));

        $firstOrdersAfterMutation = iterator_to_array($first);
        $secondOrdersAfterMutation = iterator_to_array($second);

        self::assertSame($expectedOrders, $firstOrdersAfterMutation);
        self::assertCount(count($expectedOrders), $firstOrdersAfterMutation);
        self::assertNotSame($expectedOrders, $secondOrdersAfterMutation);
        self::assertCount(count($expectedOrders) + 1, $secondOrdersAfterMutation);
    }

    public function test_create_high_fan_out_returns_deterministic_order_book_instances(): void
    {
        $first = BottleneckOrderBookFactory::createHighFanOut();
        $second = BottleneckOrderBookFactory::createHighFanOut();
        $expectedOrders = $this->buildExpectedHighFanOutOrders();

        $firstOrders = iterator_to_array($first);
        $secondOrders = iterator_to_array($second);

        self::assertNotSame($first, $second);
        self::assertSame($expectedOrders, $firstOrders);
        self::assertSame($expectedOrders, $secondOrders);

        $second->add(OrderFactory::sell('SRC', 'CLONECHK2', '1.000', '1.000', '1.000', 3, 3));

        $firstOrdersAfterMutation = iterator_to_array($first);
        $secondOrdersAfterMutation = iterator_to_array($second);

        self::assertSame($expectedOrders, $firstOrdersAfterMutation);
        self::assertCount(count($expectedOrders), $firstOrdersAfterMutation);
        self::assertNotSame($expectedOrders, $secondOrdersAfterMutation);
        self::assertCount(count($expectedOrders) + 1, $secondOrdersAfterMutation);
    }

    /**
     * @return list<\SomeWork\P2PPathFinder\Domain\Order\Order>
     */
    private function buildExpectedBottleneckOrders(): array
    {
        return [
            OrderFactory::sell('SRC', 'HUBA', '120.000', '122.000', '1.000', 3, 3),
            OrderFactory::sell('HUBA', 'HUBAA', '120.000', '122.000', '1.000', 3, 3),
            OrderFactory::sell('HUBAA', 'DST', '120.000', '122.000', '1.000', 3, 3),
            OrderFactory::sell('SRC', 'HUBB', '120.000', '121.500', '1.000', 3, 3),
            OrderFactory::sell('HUBB', 'HUBBA', '120.000', '121.500', '1.000', 3, 3),
            OrderFactory::sell('HUBBA', 'DST', '120.000', '121.500', '1.000', 3, 3),
        ];
    }

    /**
     * @return list<\SomeWork\P2PPathFinder\Domain\Order\Order>
     */
    private function buildExpectedHighFanOutOrders(): array
    {
        $orders = [];
        $levelOneHubs = ['HUBA', 'HUBB', 'HUBC', 'HUBD', 'HUBE', 'HUBF'];
        $levelThreeNodes = [];

        foreach ($levelOneHubs as $levelOneHub) {
            $orders[] = OrderFactory::sell('SRC', $levelOneHub, '250.000', '252.000', '1.000', 3, 3);

            for ($levelTwoIndex = 0; $levelTwoIndex < 3; ++$levelTwoIndex) {
                $levelTwoSuffix = chr(ord('A') + $levelTwoIndex);
                $levelTwoHub = $levelOneHub.$levelTwoSuffix;
                $orders[] = OrderFactory::sell($levelOneHub, $levelTwoHub, '220.000', '225.000', '1.000', 3, 3);

                for ($levelThreeIndex = 0; $levelThreeIndex < 2; ++$levelThreeIndex) {
                    $levelThreeSuffix = chr(ord('A') + $levelThreeIndex);
                    $levelThreeHub = $levelTwoHub.$levelThreeSuffix;
                    $levelThreeNodes[$levelThreeHub] = true;
                    $orders[] = OrderFactory::sell($levelTwoHub, $levelThreeHub, '180.000', '182.000', '1.000', 3, 3);
                }

                $orders[] = OrderFactory::sell($levelTwoHub, 'DST', '205.000', '207.000', '1.000', 3, 3);
            }

            $orders[] = OrderFactory::sell($levelOneHub, 'DST', '260.000', '261.000', '1.000', 3, 3);
        }

        foreach (array_keys($levelThreeNodes) as $levelThreeHub) {
            $orders[] = OrderFactory::sell($levelThreeHub, 'DST', '150.000', '151.000', '1.000', 3, 3);
        }

        return $orders;
    }
}
