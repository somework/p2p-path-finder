<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Graph;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Application\Graph\Graph;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Application\Support\Generator\GraphScenarioGenerator;
use SomeWork\P2PPathFinder\Tests\Support\InfectionIterationLimiter;

use function array_filter;
use function array_key_first;
use function array_keys;
use function array_unique;
use function array_values;
use function count;
use function sort;

final class GraphBuilderPropertyTest extends TestCase
{
    use InfectionIterationLimiter;

    private GraphScenarioGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new GraphScenarioGenerator();
    }

    public function test_build_produces_consistent_edges_for_random_orders(): void
    {
        $builder = new GraphBuilder();

        $limit = $this->iterationLimit(25, 5, 'P2P_GRAPH_BUILDER_PROPERTY_ITERATIONS');

        for ($iteration = 0; $iteration < $limit; ++$iteration) {
            $orders = $this->generator->orders();
            $graph = $this->exportGraph($builder->build($orders));

            $expectedCurrencies = $this->collectCurrencies($orders);
            $actualCurrencies = array_keys($graph);

            self::assertSame($expectedCurrencies, $actualCurrencies);

            foreach ($graph as $node) {
                $origins = array_values(array_unique(array_map(
                    static fn (array $edge): string => $edge['from'],
                    $node['edges'],
                )));

                if ([] !== $node['edges']) {
                    self::assertSame([$node['currency']], $origins);
                }

                foreach ($node['edges'] as $edge) {
                    $this->assertEdgeConsistency($edge);
                }
            }
        }
    }

    /**
     * @return array<string, array{currency: string, edges: list<array<string, mixed>>}>
     */
    private function exportGraph(Graph $graph): array
    {
        $export = [];

        foreach ($graph as $currency => $node) {
            $export[$currency] = [
                'currency' => $currency,
                'edges' => array_map([$this, 'exportEdge'], $node->edges()->toArray()),
            ];
        }

        return $export;
    }

    private function exportEdge(GraphEdge $edge): array
    {
        return [
            'from' => $edge->from(),
            'to' => $edge->to(),
            'orderSide' => $edge->orderSide(),
            'order' => $edge->order(),
            'rate' => $edge->rate(),
            'baseCapacity' => [
                'min' => $edge->baseCapacity()->min(),
                'max' => $edge->baseCapacity()->max(),
            ],
            'quoteCapacity' => [
                'min' => $edge->quoteCapacity()->min(),
                'max' => $edge->quoteCapacity()->max(),
            ],
            'grossBaseCapacity' => [
                'min' => $edge->grossBaseCapacity()->min(),
                'max' => $edge->grossBaseCapacity()->max(),
            ],
            'segments' => array_map([$this, 'exportSegment'], $edge->segments()),
        ];
    }

    private function exportSegment(EdgeSegment $segment): array
    {
        return [
            'isMandatory' => $segment->isMandatory(),
            'base' => [
                'min' => $segment->base()->min(),
                'max' => $segment->base()->max(),
            ],
            'quote' => [
                'min' => $segment->quote()->min(),
                'max' => $segment->quote()->max(),
            ],
            'grossBase' => [
                'min' => $segment->grossBase()->min(),
                'max' => $segment->grossBase()->max(),
            ],
        ];
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
            [$from, $to] = match ($order->side()) {
                OrderSide::BUY => [$order->assetPair()->base(), $order->assetPair()->quote()],
                OrderSide::SELL => [$order->assetPair()->quote(), $order->assetPair()->base()],
            };

            $currencies[$from] = true;
            $currencies[$to] = true;
        }

        $list = array_keys($currencies);
        sort($list);

        return $list;
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
        self::assertLessThanOrEqual(2, count($segments));

        if ([] === $segments) {
            self::assertTrue($baseCapacity['min']->equals($grossCapacity['min']));
            self::assertTrue($baseCapacity['max']->equals($grossCapacity['max']));

            return;
        }

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

        $expectedBaseCoverage = [] !== $mandatorySegments
            ? $baseCapacity['max']
            : $baseCapacity['max']->subtract($baseCapacity['min']);

        $expectedQuoteCoverage = [] !== $mandatorySegments
            ? $quoteCapacity['max']
            : $quoteCapacity['max']->subtract($quoteCapacity['min']);

        $expectedGrossCoverage = [] !== $mandatorySegments
            ? $grossCapacity['max']
            : $grossCapacity['max']->subtract($grossCapacity['min']);

        self::assertTrue($baseTotal->equals($expectedBaseCoverage));
        self::assertTrue($quoteTotal->equals($expectedQuoteCoverage));
        self::assertTrue($grossTotal->equals($expectedGrossCoverage));
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
