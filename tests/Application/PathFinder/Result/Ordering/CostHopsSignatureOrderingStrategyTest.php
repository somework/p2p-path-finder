<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Result\Ordering;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;

final class CostHopsSignatureOrderingStrategyTest extends TestCase
{
    private CostHopsSignatureOrderingStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->strategy = new CostHopsSignatureOrderingStrategy(18);
    }

    public function test_it_prefers_lower_costs(): void
    {
        $cheaper = new PathOrderKey(new PathCost('0.100000000000000000'), 2, new RouteSignature(['SRC', 'MID', 'DST']), 0);
        $expensive = new PathOrderKey(new PathCost('0.200000000000000000'), 1, new RouteSignature(['SRC', 'ALT', 'DST']), 1);

        self::assertSame(-1, $this->strategy->compare($cheaper, $expensive));
        self::assertSame(1, $this->strategy->compare($expensive, $cheaper));
    }

    public function test_it_prefers_fewer_hops_when_costs_are_equal(): void
    {
        $fewerHops = new PathOrderKey(new PathCost('0.150000000000000000'), 1, new RouteSignature(['SRC', 'DST']), 2);
        $moreHops = new PathOrderKey(new PathCost('0.150000000000000000'), 3, new RouteSignature(['SRC', 'MID', 'ALT', 'DST']), 3);

        self::assertSame(-1, $this->strategy->compare($fewerHops, $moreHops));
        self::assertSame(1, $this->strategy->compare($moreHops, $fewerHops));
    }

    public function test_it_prefers_lexicographically_smaller_signatures_when_cost_and_hops_are_equal(): void
    {
        $alphaSignature = new PathOrderKey(new PathCost('0.175000000000000000'), 2, new RouteSignature(['SRC', 'ALP', 'DST']), 4);
        $betaSignature = new PathOrderKey(new PathCost('0.175000000000000000'), 2, new RouteSignature(['SRC', 'BET', 'DST']), 5);

        self::assertSame(-1, $this->strategy->compare($alphaSignature, $betaSignature));
        self::assertSame(1, $this->strategy->compare($betaSignature, $alphaSignature));
    }

    public function test_it_prefers_lower_insertion_order_when_all_other_keys_match(): void
    {
        $firstDiscovered = new PathOrderKey(new PathCost('0.300000000000000000'), 3, new RouteSignature(['SRC', 'X', 'Y', 'DST']), 6);
        $secondDiscovered = new PathOrderKey(new PathCost('0.300000000000000000'), 3, new RouteSignature(['SRC', 'X', 'Y', 'DST']), 7);

        self::assertSame(-1, $this->strategy->compare($firstDiscovered, $secondDiscovered));
        self::assertSame(1, $this->strategy->compare($secondDiscovered, $firstDiscovered));
    }

    public function test_it_treats_identical_keys_as_equal(): void
    {
        $key = new PathOrderKey(new PathCost('0.250000000000000000'), 2, new RouteSignature(['SRC', 'MID', 'DST']), 8);

        self::assertSame(0, $this->strategy->compare($key, $key));
    }
}
