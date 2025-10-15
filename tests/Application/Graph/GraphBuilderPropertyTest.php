<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Graph;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Application\Support\Generator\GraphScenarioGenerator;

use function array_filter;
use function array_key_first;
use function array_keys;
use function count;
use function sort;

final class GraphBuilderPropertyTest extends TestCase
{
    private GraphScenarioGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new GraphScenarioGenerator();
    }

    public function test_build_produces_consistent_edges_for_random_orders(): void
    {
        $builder = new GraphBuilder();

        for ($iteration = 0; $iteration < 25; ++$iteration) {
            $orders = $this->generator->orders();
            $graph = $builder->build($orders);

            $expectedCurrencies = $this->collectCurrencies($orders);
            $actualCurrencies = array_keys($graph);
            sort($expectedCurrencies);
            sort($actualCurrencies);

            self::assertSame($expectedCurrencies, $actualCurrencies);

            foreach ($graph as $node) {
                foreach ($node['edges'] as $edge) {
                    $this->assertEdgeConsistency($edge);
                }
            }
        }
    }

    /**
     * @param list<Order> $orders
     *
     * @return list<string>
     */
    private function collectCurrencies(array $orders): array
    {
        $currencies = [];

        foreach ($orders as $order) {
            $pair = $order->assetPair();
            $currencies[$pair->base()] = true;
            $currencies[$pair->quote()] = true;
        }

        return array_keys($currencies);
    }

    /**
     * @param array{
     *     baseCapacity: array{min: Money, max: Money},
     *     quoteCapacity: array{min: Money, max: Money},
     *     grossBaseCapacity: array{min: Money, max: Money},
     *     segments: list<array{
     *         isMandatory: bool,
     *         base: array{min: Money, max: Money},
     *         quote: array{min: Money, max: Money},
     *         grossBase: array{min: Money, max: Money},
     *     }>,
     * } $edge
     */
    private function assertEdgeConsistency(array $edge): void
    {
        $baseCapacity = $edge['baseCapacity'];
        $quoteCapacity = $edge['quoteCapacity'];
        $grossCapacity = $edge['grossBaseCapacity'];

        self::assertFalse($baseCapacity['max']->lessThan($baseCapacity['min']));
        self::assertFalse($quoteCapacity['max']->lessThan($quoteCapacity['min']));
        self::assertFalse($grossCapacity['max']->lessThan($grossCapacity['min']));

        $segments = $edge['segments'];
        self::assertNotEmpty($segments);
        self::assertLessThanOrEqual(2, count($segments));

        $mandatorySegments = array_filter(
            $segments,
            static fn (array $segment): bool => $segment['isMandatory'],
        );

        self::assertLessThanOrEqual(1, count($mandatorySegments));

        if ([] !== $mandatorySegments) {
            $mandatory = $mandatorySegments[array_key_first($mandatorySegments)];
            self::assertTrue($mandatory['base']['max']->equals($baseCapacity['min']));
            self::assertTrue($mandatory['quote']['max']->equals($quoteCapacity['min']));
            self::assertTrue($mandatory['grossBase']['max']->equals($grossCapacity['min']));
        }

        foreach ($segments as $segment) {
            if ($segment['isMandatory']) {
                continue;
            }

            self::assertTrue($segment['base']['min']->isZero());
            self::assertTrue($segment['quote']['min']->isZero());
            self::assertTrue($segment['grossBase']['min']->isZero());
        }

        $baseTotal = $this->sumSegmentMax($segments, 'base');
        $quoteTotal = $this->sumSegmentMax($segments, 'quote');
        $grossTotal = $this->sumSegmentMax($segments, 'grossBase');

        self::assertTrue($baseTotal->equals($baseCapacity['max']));
        self::assertTrue($quoteTotal->equals($quoteCapacity['max']));
        self::assertTrue($grossTotal->equals($grossCapacity['max']));
    }

    /**
     * @param list<array{base: array{max: Money}, quote: array{max: Money}, grossBase: array{max: Money}}> $segments
     */
    private function sumSegmentMax(array $segments, string $key): Money
    {
        $first = $segments[0][$key]['max'];
        $total = Money::zero($first->currency(), $first->scale());

        foreach ($segments as $segment) {
            $total = $total->add($segment[$key]['max']);
        }

        return $total;
    }
}
