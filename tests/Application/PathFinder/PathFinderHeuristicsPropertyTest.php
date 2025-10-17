<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Application\Support\Generator\GraphScenarioGenerator;

final class PathFinderHeuristicsPropertyTest extends TestCase
{
    private GraphScenarioGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new GraphScenarioGenerator();
    }

    public function test_convert_edge_amount_matches_materializer_for_generated_orders(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');
        $convertMethod = new ReflectionMethod(PathFinder::class, 'convertEdgeAmount');
        $convertMethod->setAccessible(true);

        for ($iteration = 0; $iteration < 15; ++$iteration) {
            $orders = $this->generator->orders();
            $graph = (new GraphBuilder())->build($orders);

            foreach ($graph as $node) {
                foreach ($node['edges'] as $edge) {
                    $sourceCapacity = OrderSide::BUY === $edge['orderSide']
                        ? $edge['grossBaseCapacity']
                        : $edge['quoteCapacity'];
                    $targetCapacity = OrderSide::BUY === $edge['orderSide']
                        ? $edge['quoteCapacity']
                        : $edge['baseCapacity'];

                    $minSpend = $sourceCapacity['min'];
                    $maxSpend = $sourceCapacity['max'];

                    foreach ($this->sampleSpendAmounts($minSpend, $maxSpend) as $spend) {
                        $converted = $convertMethod->invoke($finder, $edge, $spend);
                        $expected = $this->interpolateCapacity(
                            $spend,
                            $sourceCapacity['min'],
                            $sourceCapacity['max'],
                            $targetCapacity['min'],
                            $targetCapacity['max']
                        );

                        $minTarget = $targetCapacity['min']->withScale($expected->scale());
                        $maxTarget = $targetCapacity['max']->withScale($expected->scale());
                        $convertedComparable = $converted->withScale($expected->scale());

                        self::assertFalse(
                            $convertedComparable->lessThan($minTarget),
                            'Heuristic produced amount below materialized minimum.'
                        );
                        self::assertFalse(
                            $convertedComparable->greaterThan($maxTarget),
                            'Heuristic produced amount above materialized maximum.'
                        );

                        self::assertSame(
                            $expected->amount(),
                            $convertedComparable->amount(),
                            'Heuristic interpolation diverged from materialized capacity.'
                        );
                    }
                }
            }
        }
    }

    /**
     * @return list<Money>
     */
    private function sampleSpendAmounts(Money $min, Money $max): array
    {
        $scale = max($min->scale(), $max->scale());
        $min = $min->withScale($scale);
        $max = $max->withScale($scale);

        if ($min->equals($max)) {
            return [$min];
        }

        $half = $max->subtract($min, $scale)->divide('2', $scale);
        $mid = $min->add($half, $scale);

        return [$min, $mid, $max];
    }

    private function interpolateCapacity(
        Money $value,
        Money $sourceMin,
        Money $sourceMax,
        Money $targetMin,
        Money $targetMax
    ): Money {
        $referenceScale = 18;
        $sourceScale = max($sourceMin->scale(), $sourceMax->scale(), $value->scale(), $referenceScale);
        $targetScale = max($targetMin->scale(), $targetMax->scale(), $referenceScale);

        $sourceMin = $sourceMin->withScale($sourceScale);
        $sourceMax = $sourceMax->withScale($sourceScale);
        $targetMin = $targetMin->withScale($targetScale);
        $targetMax = $targetMax->withScale($targetScale);
        $current = $value->withScale($sourceScale);

        if ($sourceMax->equals($sourceMin)) {
            return $targetMin->withScale(max($targetScale, $referenceScale));
        }

        $ratioScale = max($sourceScale, $targetScale, $referenceScale);
        $sourceDelta = $sourceMax->subtract($sourceMin, $sourceScale)->withScale($ratioScale)->amount();
        if (0 === BcMath::comp($sourceDelta, '0', $ratioScale)) {
            return $targetMin->withScale($ratioScale);
        }

        $targetDelta = $targetMax->subtract($targetMin, $targetScale)->withScale($ratioScale)->amount();
        $ratio = BcMath::div($targetDelta, $sourceDelta, $ratioScale + 4);
        $offset = $current->subtract($sourceMin, $sourceScale)->withScale($ratioScale)->amount();
        $increment = BcMath::mul($offset, $ratio, $ratioScale + 2);
        $result = BcMath::add($targetMin->withScale($ratioScale)->amount(), $increment, $ratioScale + 2);

        return Money::fromString($targetMin->currency(), BcMath::normalize($result, $ratioScale), $ratioScale);
    }
}
