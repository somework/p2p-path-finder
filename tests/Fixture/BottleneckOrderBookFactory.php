<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Fixture;

use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;

use function chr;
use function ord;

final class BottleneckOrderBookFactory
{
    private static ?OrderBook $bottleneckOrderBook = null;

    private static ?OrderBook $highFanOutOrderBook = null;

    /**
     * Builds a deterministic order book where every hop has tight bounds around
     * a large mandatory minimum. The optional headroom is intentionally
     * constrained to stress capacity checks in the path finder.
     */
    public static function create(): OrderBook
    {
        if (null === self::$bottleneckOrderBook) {
            self::$bottleneckOrderBook = self::buildBottleneckOrderBook();
        }

        return clone self::$bottleneckOrderBook;
    }

    /**
     * Builds a wider mandatory-minimum stress graph where each branch enforces
     * a high minimum and only a narrow amount of headroom. The topology
     * contains three layers of fan-out so the path finder must aggregate the
     * tight minima across long branches and choose between similarly
     * constrained alternatives.
     */
    public static function createHighFanOut(): OrderBook
    {
        if (null === self::$highFanOutOrderBook) {
            self::$highFanOutOrderBook = self::buildHighFanOutOrderBook();
        }

        return clone self::$highFanOutOrderBook;
    }

    private static function buildBottleneckOrderBook(): OrderBook
    {
        $orders = [
            OrderFactory::sell('SRC', 'HUBA', '120.000', '122.000', '1.000', 3, 3),
            OrderFactory::sell('HUBA', 'HUBAA', '120.000', '122.000', '1.000', 3, 3),
            OrderFactory::sell('HUBAA', 'DST', '120.000', '122.000', '1.000', 3, 3),
            OrderFactory::sell('SRC', 'HUBB', '120.000', '121.500', '1.000', 3, 3),
            OrderFactory::sell('HUBB', 'HUBBA', '120.000', '121.500', '1.000', 3, 3),
            OrderFactory::sell('HUBBA', 'DST', '120.000', '121.500', '1.000', 3, 3),
        ];

        return new OrderBook($orders);
    }

    private static function buildHighFanOutOrderBook(): OrderBook
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

                // Optional shortcut with a slightly higher minimum than the
                // upstream edge so the path finder must reconcile competing
                // thresholds per branch.
                $orders[] = OrderFactory::sell($levelTwoHub, 'DST', '205.000', '207.000', '1.000', 3, 3);
            }

            // Direct exit with the highest minimum keeps the aggregate low hop
            // paths expensive, nudging the search toward the deeper fan-out.
            $orders[] = OrderFactory::sell($levelOneHub, 'DST', '260.000', '261.000', '1.000', 3, 3);
        }

        foreach (array_keys($levelThreeNodes) as $levelThreeHub) {
            $orders[] = OrderFactory::sell($levelThreeHub, 'DST', '150.000', '151.000', '1.000', 3, 3);
        }

        return new OrderBook($orders);
    }
}
