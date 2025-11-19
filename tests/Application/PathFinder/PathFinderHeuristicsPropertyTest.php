<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Application\Support\Generator\GraphScenarioGenerator;
use SomeWork\P2PPathFinder\Tests\Support\DecimalMath;
use SomeWork\P2PPathFinder\Tests\Support\InfectionIterationLimiter;

use function sprintf;

final class PathFinderHeuristicsPropertyTest extends TestCase
{
    use InfectionIterationLimiter;

    private GraphScenarioGenerator $generator;
    private int $pathFinderScale;
    private int $ratioExtraScale;
    private int $sumExtraScale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new GraphScenarioGenerator();

        $pathFinderReflection = new ReflectionClass(PathFinder::class);
        $this->pathFinderScale = $this->reflectIntConstant($pathFinderReflection, 'SCALE');
        $this->ratioExtraScale = $this->reflectIntConstant($pathFinderReflection, 'RATIO_EXTRA_SCALE');
        $this->sumExtraScale = $this->reflectIntConstant($pathFinderReflection, 'SUM_EXTRA_SCALE');
    }

    public function test_convert_edge_amount_matches_materializer_for_generated_orders(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');
        $convertMethod = new ReflectionMethod(PathFinder::class, 'convertEdgeAmount');
        $convertMethod->setAccessible(true);

        $limit = $this->iterationLimit(15, 5, 'P2P_PATH_FINDER_HEURISTIC_ITERATIONS');

        for ($iteration = 0; $iteration < $limit; ++$iteration) {
            $orders = $this->generator->orders();
            $graph = (new GraphBuilder())->build($orders);

            foreach ($graph as $node) {
                foreach ($node->edges() as $edge) {
                    $sourceCapacity = OrderSide::BUY === $edge->orderSide()
                        ? $edge->grossBaseCapacity()
                        : $edge->quoteCapacity();
                    $targetCapacity = OrderSide::BUY === $edge->orderSide()
                        ? $edge->quoteCapacity()
                        : $edge->baseCapacity();

                    $minSpend = $sourceCapacity->min();
                    $maxSpend = $sourceCapacity->max();

                    foreach ($this->sampleSpendAmounts($minSpend, $maxSpend) as $spend) {
                        $converted = $convertMethod->invoke($finder, $edge, $spend);
                        $expected = $this->interpolateCapacity(
                            $spend,
                            $sourceCapacity->min(),
                            $sourceCapacity->max(),
                            $targetCapacity->min(),
                            $targetCapacity->max()
                        );

                        $comparisonScale = max(
                            $expected->scale(),
                            $converted->scale(),
                            $targetCapacity->min()->scale(),
                            $targetCapacity->max()->scale(),
                        );

                        $minTarget = $targetCapacity->min()->withScale($comparisonScale);
                        $maxTarget = $targetCapacity->max()->withScale($comparisonScale);
                        $convertedComparable = $converted->withScale($comparisonScale);
                        $expectedComparable = $expected->withScale($comparisonScale);

                        self::assertFalse(
                            $convertedComparable->lessThan($minTarget),
                            'Heuristic produced amount below materialized minimum.'
                        );
                        self::assertFalse(
                            $convertedComparable->greaterThan($maxTarget),
                            'Heuristic produced amount above materialized maximum.'
                        );

                        self::assertSame(
                            $expectedComparable->amount(),
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
        $referenceScale = $this->pathFinderScale;
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
        if (0 === DecimalMath::comp($sourceDelta, '0', $ratioScale)) {
            return $targetMin->withScale($ratioScale);
        }

        $targetDelta = $targetMax->subtract($targetMin, $targetScale)->withScale($ratioScale)->amount();
        $ratio = DecimalMath::div($targetDelta, $sourceDelta, $ratioScale + $this->ratioExtraScale);
        $offset = $current->subtract($sourceMin, $sourceScale)->withScale($ratioScale)->amount();
        $increment = DecimalMath::mul($offset, $ratio, $ratioScale + $this->sumExtraScale);
        $result = DecimalMath::add(
            $targetMin->withScale($ratioScale)->amount(),
            $increment,
            $ratioScale + $this->sumExtraScale,
        );

        $normalized = DecimalMath::normalize($result, $ratioScale + $this->sumExtraScale);

        $interpolated = Money::fromString(
            $targetMin->currency(),
            $normalized,
            $ratioScale + $this->sumExtraScale,
        );

        $interpolated = $interpolated->withScale($targetScale);

        if ($interpolated->lessThan($targetMin)) {
            return $targetMin;
        }

        if ($interpolated->greaterThan($targetMax)) {
            return $targetMax;
        }

        return $interpolated;
    }

    private function reflectIntConstant(ReflectionClass $class, string $name): int
    {
        $constant = $class->getReflectionConstant($name);
        self::assertNotNull($constant, sprintf('Constant %s::%s is not defined.', $class->getName(), $name));

        $value = $constant->getValue();
        self::assertIsInt(
            $value,
            sprintf('Expected %s::%s to be an integer, got %s.', $class->getName(), $name, get_debug_type($value)),
        );

        return $value;
    }
}
