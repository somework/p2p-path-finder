<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\Graph;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Tests\Helpers\Generator\GraphScenarioGenerator;
use SomeWork\P2PPathFinder\Tests\Helpers\InfectionIterationLimiter;

use function array_filter;
use function array_key_first;
use function array_keys;
use function array_unique;
use function array_values;
use function count;
use function sort;

final class GraphBuilderIntegrationTest extends TestCase
{
    use InfectionIterationLimiter;

    private GraphScenarioGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        // Use deterministic seed for reproducible test results
        $engine = new Mt19937(12345); // Fixed seed for consistency
        $randomizer = new Randomizer($engine);
        $this->generator = new GraphScenarioGenerator($randomizer);
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
                    $this->assertEdgeDirectionality($edge, $orders);
                    $this->assertEdgeCurrenciesExistInOrders($edge, $orders);
                    $this->assertEdgeCapacityBounds($edge);
                    $this->assertEdgeSegmentsValid($edge);
                }
            }

            $this->assertAllCurrenciesFromOrdersArePresent($graph, $orders);
            $this->assertNoExtraCurrencies($graph, $orders);
            $this->assertCurrenciesAreSorted($graph);
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
            $this->assertMoneyApproximatelyEqual($baseCapacity['min'], $grossCapacity['min'], 'Base capacity min should equal gross capacity min when no segments');
            $this->assertMoneyApproximatelyEqual($baseCapacity['max'], $grossCapacity['max'], 'Base capacity max should equal gross capacity max when no segments');

            return;
        }

        $mandatorySegments = array_filter(
            $segments,
            static fn (array $segment): bool => $segment['isMandatory'],
        );

        self::assertLessThanOrEqual(1, count($mandatorySegments));

        if ([] !== $mandatorySegments) {
            $mandatory = $mandatorySegments[array_key_first($mandatorySegments)];
            $this->assertMoneyApproximatelyEqual($mandatory['base']['max'], $baseCapacity['min'], 'Mandatory segment base max should equal base capacity min');
            $this->assertMoneyApproximatelyEqual($mandatory['quote']['max'], $quoteCapacity['min'], 'Mandatory segment quote max should equal quote capacity min');
            $this->assertMoneyApproximatelyEqual($mandatory['grossBase']['max'], $grossCapacity['min'], 'Mandatory segment gross base max should equal gross capacity min');
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

        $this->assertMoneyApproximatelyEqual($baseTotal, $expectedBaseCoverage, 'Base segment total should match expected base coverage');
        $this->assertMoneyApproximatelyEqual($quoteTotal, $expectedQuoteCoverage, 'Quote segment total should match expected quote coverage');
        $this->assertMoneyApproximatelyEqual($grossTotal, $expectedGrossCoverage, 'Gross base segment total should match expected gross coverage');
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

    /**
     * Assert that two Money objects are approximately equal within a small tolerance.
     * This helps avoid flaky tests due to tiny precision differences in floating-point calculations.
     */
    private function assertMoneyApproximatelyEqual(Money $expected, Money $actual, string $message = ''): void
    {
        if ($expected->currency() !== $actual->currency()) {
            self::fail("Money currencies don't match: {$expected->currency()} vs {$actual->currency()}");
        }

        if ($expected->scale() !== $actual->scale()) {
            // Try to compare with the higher precision scale
            $maxScale = max($expected->scale(), $actual->scale());
            $expectedNormalized = $expected->withScale($maxScale);
            $actualNormalized = $actual->withScale($maxScale);
            $expectedDecimal = $expectedNormalized->decimal();
            $actualDecimal = $actualNormalized->decimal();
        } else {
            $expectedDecimal = $expected->decimal();
            $actualDecimal = $actual->decimal();
        }

        // Allow for tiny differences due to floating-point precision
        $tolerance = BigDecimal::of('0.000001'); // 1 millionth tolerance
        $difference = $expectedDecimal->minus($actualDecimal)->abs();

        self::assertTrue(
            $difference->compareTo($tolerance) <= 0,
            $message ?: "Money values not approximately equal: expected {$expected->amount()}, got {$actual->amount()}"
        );
    }

    /**
     * @param array{
     *     from: string,
     *     to: string,
     *     orderSide: OrderSide,
     * } $edge
     * @param list<Order> $orders
     */
    private function assertEdgeDirectionality(array $edge, array $orders): void
    {
        $matchingOrder = null;
        foreach ($orders as $order) {
            if ($order === $edge['order']) {
                $matchingOrder = $order;
                break;
            }
        }

        self::assertNotNull($matchingOrder, 'Edge order should match one of the input orders');

        // Verify directionality based on order side
        $pair = $matchingOrder->assetPair();
        [$expectedFrom, $expectedTo] = match ($matchingOrder->side()) {
            OrderSide::BUY => [$pair->base(), $pair->quote()],
            OrderSide::SELL => [$pair->quote(), $pair->base()],
        };

        self::assertSame($expectedFrom, $edge['from'], 'Edge from currency should match order directionality');
        self::assertSame($expectedTo, $edge['to'], 'Edge to currency should match order directionality');
    }

    /**
     * @param array{from: string, to: string} $edge
     * @param list<Order>                     $orders
     */
    private function assertEdgeCurrenciesExistInOrders(array $edge, array $orders): void
    {
        $allCurrencies = [];
        foreach ($orders as $order) {
            $pair = $order->assetPair();
            $allCurrencies[] = $pair->base();
            $allCurrencies[] = $pair->quote();
        }
        $allCurrencies = array_unique($allCurrencies);

        self::assertContains($edge['from'], $allCurrencies, 'Edge from currency should exist in orders');
        self::assertContains($edge['to'], $allCurrencies, 'Edge to currency should exist in orders');
    }

    /**
     * @param array{
     *     baseCapacity: array{min: Money, max: Money},
     *     quoteCapacity: array{min: Money, max: Money},
     *     grossBaseCapacity: array{min: Money, max: Money},
     * } $edge
     */
    private function assertEdgeCapacityBounds(array $edge): void
    {
        $baseCapacity = $edge['baseCapacity'];
        $quoteCapacity = $edge['quoteCapacity'];
        $grossCapacity = $edge['grossBaseCapacity'];

        $zeroBase = Money::zero($baseCapacity['min']->currency(), $baseCapacity['min']->scale());
        $zeroQuote = Money::zero($quoteCapacity['min']->currency(), $quoteCapacity['min']->scale());
        $zeroGross = Money::zero($grossCapacity['min']->currency(), $grossCapacity['min']->scale());

        // Capacities should never be negative
        self::assertFalse($baseCapacity['min']->lessThan($zeroBase));
        self::assertFalse($baseCapacity['max']->lessThan($zeroBase));
        self::assertFalse($quoteCapacity['min']->lessThan($zeroQuote));
        self::assertFalse($quoteCapacity['max']->lessThan($zeroQuote));
        self::assertFalse($grossCapacity['min']->lessThan($zeroGross));
        self::assertFalse($grossCapacity['max']->lessThan($zeroGross));

        // Max should be >= min
        self::assertFalse($baseCapacity['max']->lessThan($baseCapacity['min']));
        self::assertFalse($quoteCapacity['max']->lessThan($quoteCapacity['min']));
        self::assertFalse($grossCapacity['max']->lessThan($grossCapacity['min']));
    }

    /**
     * @param array{segments: list<array{isMandatory: bool}>} $edge
     */
    private function assertEdgeSegmentsValid(array $edge): void
    {
        $segments = $edge['segments'];

        // Should have 0, 1, or 2 segments
        self::assertLessThanOrEqual(2, count($segments));

        $mandatorySegments = array_filter($segments, static fn ($segment) => $segment['isMandatory']);
        $optionalSegments = array_filter($segments, static fn ($segment) => !$segment['isMandatory']);

        // At most one mandatory segment
        self::assertLessThanOrEqual(1, count($mandatorySegments));

        // All optional segments should have zero minimums
        foreach ($optionalSegments as $segment) {
            self::assertTrue($segment['base']['min']->isZero());
            self::assertTrue($segment['quote']['min']->isZero());
            self::assertTrue($segment['grossBase']['min']->isZero());
        }
    }

    /**
     * @param array<string, mixed> $graph
     * @param list<Order>          $orders
     */
    private function assertAllCurrenciesFromOrdersArePresent(array $graph, array $orders): void
    {
        $expectedCurrencies = [];
        foreach ($orders as $order) {
            $pair = $order->assetPair();
            $expectedCurrencies[] = $pair->base();
            $expectedCurrencies[] = $pair->quote();
        }
        $expectedCurrencies = array_unique($expectedCurrencies);

        $actualCurrencies = array_keys($graph);

        foreach ($expectedCurrencies as $currency) {
            self::assertArrayHasKey($currency, $graph, "Currency {$currency} from orders should be present in graph");
        }
    }

    /**
     * @param array<string, mixed> $graph
     * @param list<Order>          $orders
     */
    private function assertNoExtraCurrencies(array $graph, array $orders): void
    {
        $expectedCurrencies = [];
        foreach ($orders as $order) {
            $pair = $order->assetPair();
            $expectedCurrencies[] = $pair->base();
            $expectedCurrencies[] = $pair->quote();
        }
        $expectedCurrencies = array_unique($expectedCurrencies);

        $actualCurrencies = array_keys($graph);

        foreach ($actualCurrencies as $currency) {
            self::assertContains($currency, $expectedCurrencies, "Currency {$currency} in graph should exist in orders");
        }
    }

    /**
     * @param array<string, mixed> $graph
     */
    private function assertCurrenciesAreSorted(array $graph): void
    {
        $currencies = array_keys($graph);
        $sortedCurrencies = $currencies;
        sort($sortedCurrencies);

        self::assertSame($sortedCurrencies, $currencies, 'Currencies in graph should be sorted alphabetically');
    }
}
