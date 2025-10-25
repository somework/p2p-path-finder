<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Graph;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SomeWork\P2PPathFinder\Application\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Application\Graph\Graph;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

use function serialize;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * @psalm-type GraphSegment = array{
 *     isMandatory: bool,
 *     base: array{min: Money, max: Money},
 *     quote: array{min: Money, max: Money},
 *     grossBase: array{min: Money, max: Money},
 * }
 * @psalm-type GraphEdge = array{
 *     from: string,
 *     to: string,
 *     orderSide: OrderSide,
 *     order: Order,
 *     rate: ExchangeRate,
 *     baseCapacity: array{min: Money, max: Money},
 *     quoteCapacity: array{min: Money, max: Money},
 *     grossBaseCapacity: array{min: Money, max: Money},
 *     segments: list<GraphSegment>,
 * }
 * @psalm-type GraphNode = array{currency: string, edges: list<GraphEdge>}
 * @psalm-type Graph = array<string, GraphNode>
 */
final class GraphBuilderTest extends TestCase
{
    public function test_build_creates_expected_currency_nodes(): void
    {
        [$graph] = $this->buildGraphFromSampleOrders();

        self::assertCount(5, $graph);
        self::assertEqualsCanonicalizing([
            'BTC',
            'USD',
            'EUR',
            'ETH',
            'LTC',
        ], array_keys($graph));

        self::assertCount(2, $graph['BTC']['edges']);
        self::assertCount(2, $graph['USD']['edges']);
        self::assertCount(0, $graph['EUR']['edges']);
        self::assertCount(0, $graph['ETH']['edges']);
        self::assertCount(0, $graph['LTC']['edges']);
    }

    public function test_build_creates_buy_edges_for_each_order(): void
    {
        [$graph, $orders] = $this->buildGraphFromSampleOrders();

        /** @var list<GraphEdge> $edges */
        /** @var list<GraphEdge> $edges */
        $edges = $graph['BTC']['edges'];
        self::assertCount(2, $edges);

        $quotes = array_map(static fn (array $edge): string => $edge['order']->assetPair()->quote(), $edges);
        self::assertSame(['EUR', 'USD'], $quotes);

        $secondaryEdge = $edges[0];
        $this->assertEdgeBasics($secondaryEdge, 'BTC', 'EUR', OrderSide::BUY, $orders['secondaryBuyOrder']);
        $this->assertEdgeCapacities($secondaryEdge, 'BTC', '0.200', '0.800', 'EUR', '5600.000', '22400.000');
        $this->assertGrossBaseEqualsBase($secondaryEdge);
        self::assertSame([], $secondaryEdge['segments']);

        $primaryEdge = $edges[1];
        $this->assertEdgeBasics($primaryEdge, 'BTC', 'USD', OrderSide::BUY, $orders['primaryBuyOrder']);
        $this->assertEdgeCapacities($primaryEdge, 'BTC', '0.100', '1.000', 'USD', '3000.000', '30000.000');
        $this->assertGrossBaseEqualsBase($primaryEdge);
        self::assertSame([], $primaryEdge['segments']);
    }

    public function test_build_skips_entries_that_are_not_orders(): void
    {
        $validOrder = $this->createOrder(
            OrderSide::BUY,
            'BTC',
            'USD',
            '0.100',
            '1.000',
            '30000.000',
        );

        $graph = $this->exportGraph((new GraphBuilder())->build([
            $validOrder,
            'not-an-order',
            42,
        ]));

        self::assertSame(['BTC', 'USD'], array_keys($graph));
        self::assertCount(1, $graph['BTC']['edges']);
        self::assertSame($validOrder, $graph['BTC']['edges'][0]['order']);
    }

    public function test_build_continues_processing_after_skipping_non_orders(): void
    {
        $firstOrder = $this->createOrder(
            OrderSide::BUY,
            'BTC',
            'USD',
            '0.100',
            '0.400',
            '30000',
        );
        $secondOrder = $this->createOrder(
            OrderSide::BUY,
            'ETH',
            'USD',
            '0.500',
            '1.000',
            '2000',
        );

        $graph = $this->exportGraph((new GraphBuilder())->build([
            $firstOrder,
            'skip-me',
            $secondOrder,
        ]));

        self::assertArrayHasKey('ETH', $graph);
        self::assertCount(1, $graph['ETH']['edges']);
        self::assertSame($secondOrder, $graph['ETH']['edges'][0]['order']);
    }

    public function test_build_creates_sell_edges_for_each_order(): void
    {
        [$graph, $orders] = $this->buildGraphFromSampleOrders();

        /** @var list<GraphEdge> $edges */
        $edges = $graph['USD']['edges'];
        self::assertCount(2, $edges);

        $serializedOrders = array_map(static fn (array $edge): string => serialize($edge['order']), $edges);
        $sortedOrders = $serializedOrders;
        sort($sortedOrders);
        self::assertSame($sortedOrders, $serializedOrders);

        $primaryEdge = $this->findEdgeByOrder($edges, $orders['primarySellOrder']);
        $this->assertEdgeBasics($primaryEdge, 'USD', 'ETH', OrderSide::SELL, $orders['primarySellOrder']);
        $this->assertEdgeCapacities($primaryEdge, 'ETH', '0.500', '2.000', 'USD', '750.000', '3000.000');
        self::assertSame([], $primaryEdge['segments']);

        $secondaryEdge = $this->findEdgeByOrder($edges, $orders['secondarySellOrder']);
        $this->assertEdgeBasics($secondaryEdge, 'USD', 'LTC', OrderSide::SELL, $orders['secondarySellOrder']);
        $this->assertEdgeCapacities($secondaryEdge, 'LTC', '1.000', '4.000', 'USD', '90.000', '360.000');
        self::assertSame([], $secondaryEdge['segments']);
    }

    public function test_build_produces_canonical_serialization_for_permuted_orders(): void
    {
        $orders = [
            $this->createOrder(OrderSide::BUY, 'AAA', 'USD', '0.100', '0.500', '2.000'),
            $this->createOrder(OrderSide::BUY, 'AAA', 'EUR', '0.200', '0.600', '1.800'),
            $this->createOrder(OrderSide::SELL, 'ETH', 'AAA', '0.300', '0.900', '1500'),
            $this->createOrder(OrderSide::SELL, 'LTC', 'AAA', '1.000', '3.000', '90'),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $permutedGraph = (new GraphBuilder())->build([
            $orders[3],
            $orders[1],
            $orders[0],
            $orders[2],
        ]);

        $expected = $graph->jsonSerialize();

        self::assertSame($expected, $permutedGraph->jsonSerialize());

        self::assertSame([
            'AAA',
            'ETH',
            'EUR',
            'LTC',
            'USD',
        ], array_keys($expected));

        $expectedJson = <<<'JSON'
            {
                "AAA": {
                    "currency": "AAA",
                    "edges": [
                        {
                            "from": "AAA",
                            "to": "ETH",
                            "orderSide": "sell",
                            "order": {
                                "side": "sell",
                                "assetPair": {
                                    "base": "ETH",
                                    "quote": "AAA"
                                },
                                "bounds": {
                                    "min": {
                                        "currency": "ETH",
                                        "amount": "0.300",
                                        "scale": 3
                                    },
                                    "max": {
                                        "currency": "ETH",
                                        "amount": "0.900",
                                        "scale": 3
                                    }
                                },
                                "effectiveRate": {
                                    "baseCurrency": "ETH",
                                    "quoteCurrency": "AAA",
                                    "value": "1500.000",
                                    "scale": 3
                                }
                            },
                            "rate": {
                                "baseCurrency": "ETH",
                                "quoteCurrency": "AAA",
                                "value": "1500.000",
                                "scale": 3
                            },
                            "baseCapacity": {
                                "min": {
                                    "currency": "ETH",
                                    "amount": "0.300",
                                    "scale": 3
                                },
                                "max": {
                                    "currency": "ETH",
                                    "amount": "0.900",
                                    "scale": 3
                                }
                            },
                            "quoteCapacity": {
                                "min": {
                                    "currency": "AAA",
                                    "amount": "450.000",
                                    "scale": 3
                                },
                                "max": {
                                    "currency": "AAA",
                                    "amount": "1350.000",
                                    "scale": 3
                                }
                            },
                            "grossBaseCapacity": {
                                "min": {
                                    "currency": "ETH",
                                    "amount": "0.300",
                                    "scale": 3
                                },
                                "max": {
                                    "currency": "ETH",
                                    "amount": "0.900",
                                    "scale": 3
                                }
                            },
                            "segments": []
                        },
                        {
                            "from": "AAA",
                            "to": "LTC",
                            "orderSide": "sell",
                            "order": {
                                "side": "sell",
                                "assetPair": {
                                    "base": "LTC",
                                    "quote": "AAA"
                                },
                                "bounds": {
                                    "min": {
                                        "currency": "LTC",
                                        "amount": "1.000",
                                        "scale": 3
                                    },
                                    "max": {
                                        "currency": "LTC",
                                        "amount": "3.000",
                                        "scale": 3
                                    }
                                },
                                "effectiveRate": {
                                    "baseCurrency": "LTC",
                                    "quoteCurrency": "AAA",
                                    "value": "90.000",
                                    "scale": 3
                                }
                            },
                            "rate": {
                                "baseCurrency": "LTC",
                                "quoteCurrency": "AAA",
                                "value": "90.000",
                                "scale": 3
                            },
                            "baseCapacity": {
                                "min": {
                                    "currency": "LTC",
                                    "amount": "1.000",
                                    "scale": 3
                                },
                                "max": {
                                    "currency": "LTC",
                                    "amount": "3.000",
                                    "scale": 3
                                }
                            },
                            "quoteCapacity": {
                                "min": {
                                    "currency": "AAA",
                                    "amount": "90.000",
                                    "scale": 3
                                },
                                "max": {
                                    "currency": "AAA",
                                    "amount": "270.000",
                                    "scale": 3
                                }
                            },
                            "grossBaseCapacity": {
                                "min": {
                                    "currency": "LTC",
                                    "amount": "1.000",
                                    "scale": 3
                                },
                                "max": {
                                    "currency": "LTC",
                                    "amount": "3.000",
                                    "scale": 3
                                }
                            },
                            "segments": []
                        },
                        {
                            "from": "AAA",
                            "to": "EUR",
                            "orderSide": "buy",
                            "order": {
                                "side": "buy",
                                "assetPair": {
                                    "base": "AAA",
                                    "quote": "EUR"
                                },
                                "bounds": {
                                    "min": {
                                        "currency": "AAA",
                                        "amount": "0.200",
                                        "scale": 3
                                    },
                                    "max": {
                                        "currency": "AAA",
                                        "amount": "0.600",
                                        "scale": 3
                                    }
                                },
                                "effectiveRate": {
                                    "baseCurrency": "AAA",
                                    "quoteCurrency": "EUR",
                                    "value": "1.800",
                                    "scale": 3
                                }
                            },
                            "rate": {
                                "baseCurrency": "AAA",
                                "quoteCurrency": "EUR",
                                "value": "1.800",
                                "scale": 3
                            },
                            "baseCapacity": {
                                "min": {
                                    "currency": "AAA",
                                    "amount": "0.200",
                                    "scale": 3
                                },
                                "max": {
                                    "currency": "AAA",
                                    "amount": "0.600",
                                    "scale": 3
                                }
                            },
                            "quoteCapacity": {
                                "min": {
                                    "currency": "EUR",
                                    "amount": "0.360",
                                    "scale": 3
                                },
                                "max": {
                                    "currency": "EUR",
                                    "amount": "1.080",
                                    "scale": 3
                                }
                            },
                            "grossBaseCapacity": {
                                "min": {
                                    "currency": "AAA",
                                    "amount": "0.200",
                                    "scale": 3
                                },
                                "max": {
                                    "currency": "AAA",
                                    "amount": "0.600",
                                    "scale": 3
                                }
                            },
                            "segments": []
                        },
                        {
                            "from": "AAA",
                            "to": "USD",
                            "orderSide": "buy",
                            "order": {
                                "side": "buy",
                                "assetPair": {
                                    "base": "AAA",
                                    "quote": "USD"
                                },
                                "bounds": {
                                    "min": {
                                        "currency": "AAA",
                                        "amount": "0.100",
                                        "scale": 3
                                    },
                                    "max": {
                                        "currency": "AAA",
                                        "amount": "0.500",
                                        "scale": 3
                                    }
                                },
                                "effectiveRate": {
                                    "baseCurrency": "AAA",
                                    "quoteCurrency": "USD",
                                    "value": "2.000",
                                    "scale": 3
                                }
                            },
                            "rate": {
                                "baseCurrency": "AAA",
                                "quoteCurrency": "USD",
                                "value": "2.000",
                                "scale": 3
                            },
                            "baseCapacity": {
                                "min": {
                                    "currency": "AAA",
                                    "amount": "0.100",
                                    "scale": 3
                                },
                                "max": {
                                    "currency": "AAA",
                                    "amount": "0.500",
                                    "scale": 3
                                }
                            },
                            "quoteCapacity": {
                                "min": {
                                    "currency": "USD",
                                    "amount": "0.200",
                                    "scale": 3
                                },
                                "max": {
                                    "currency": "USD",
                                    "amount": "1.000",
                                    "scale": 3
                                }
                            },
                            "grossBaseCapacity": {
                                "min": {
                                    "currency": "AAA",
                                    "amount": "0.100",
                                    "scale": 3
                                },
                                "max": {
                                    "currency": "AAA",
                                    "amount": "0.500",
                                    "scale": 3
                                }
                            },
                            "segments": []
                        }
                    ]
                },
                "ETH": {
                    "currency": "ETH",
                    "edges": []
                },
                "EUR": {
                    "currency": "EUR",
                    "edges": []
                },
                "LTC": {
                    "currency": "LTC",
                    "edges": []
                },
                "USD": {
                    "currency": "USD",
                    "edges": []
                }
            }
            JSON;

        self::assertJsonStringEqualsJsonString(
            $expectedJson,
            json_encode($expected, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
    }

    public function test_constructor_preserves_injected_fill_evaluator(): void
    {
        $customEvaluator = new OrderFillEvaluator();

        $builder = new GraphBuilder($customEvaluator);

        $property = new ReflectionProperty(GraphBuilder::class, 'fillEvaluator');
        $property->setAccessible(true);

        self::assertSame($customEvaluator, $property->getValue($builder));
    }

    public function test_build_nodes_include_currency_metadata(): void
    {
        [$graph] = $this->buildGraphFromSampleOrders();

        foreach ($graph as $currency => $node) {
            self::assertArrayHasKey('currency', $node);
            self::assertSame($currency, $node['currency']);
        }
    }

    public function test_build_uses_net_quote_capacity_for_buy_orders_with_fee(): void
    {
        $order = $this->createOrder(
            OrderSide::BUY,
            'BTC',
            'USD',
            '1.000',
            '3.000',
            '100.000',
            $this->percentageFeePolicy('0.10'),
        );

        $graph = $this->exportGraph((new GraphBuilder())->build([$order]));

        $edges = $graph['BTC']['edges'];
        self::assertCount(1, $edges);

        $edge = $edges[0];

        self::assertTrue($edge['quoteCapacity']['min']->equals(Money::fromString('USD', '90.000', 3)));
        self::assertTrue($edge['quoteCapacity']['max']->equals(Money::fromString('USD', '270.000', 3)));
        self::assertTrue($edge['grossBaseCapacity']['min']->equals($edge['baseCapacity']['min']));
        self::assertTrue($edge['grossBaseCapacity']['max']->equals($edge['baseCapacity']['max']));

        $rawMin = Money::fromString('USD', '100.000', 3);
        $rawMax = Money::fromString('USD', '300.000', 3);
        self::assertTrue($edge['quoteCapacity']['min']->lessThan($rawMin));
        self::assertTrue($edge['quoteCapacity']['max']->lessThan($rawMax));

        self::assertCount(2, $edge['segments']);

        $mandatory = $edge['segments'][0];
        self::assertTrue($mandatory['isMandatory']);
        self::assertTrue($mandatory['quote']['max']->equals(Money::fromString('USD', '90.000', 3)));
        self::assertTrue($mandatory['grossBase']['max']->equals($mandatory['base']['max']));

        $optional = $edge['segments'][1];
        self::assertFalse($optional['isMandatory']);
        self::assertTrue($optional['quote']['max']->equals(Money::fromString('USD', '180.000', 3)));
        self::assertTrue($optional['grossBase']['max']->equals($optional['base']['max']));
    }

    public function test_build_uses_gross_quote_capacity_for_sell_orders_with_fee(): void
    {
        $order = $this->createOrder(
            OrderSide::SELL,
            'USD',
            'EUR',
            '1.000',
            '3.000',
            '0.500',
            $this->percentageFeePolicy('0.10'),
        );

        $graph = $this->exportGraph((new GraphBuilder())->build([$order]));

        /** @var list<GraphEdge> $edges */
        $edges = $graph['EUR']['edges'];
        self::assertCount(1, $edges);

        $edge = $edges[0];

        self::assertTrue($edge['quoteCapacity']['min']->equals(Money::fromString('EUR', '0.550', 3)));
        self::assertTrue($edge['quoteCapacity']['max']->equals(Money::fromString('EUR', '1.650', 3)));

        $rawMin = Money::fromString('EUR', '0.500', 3);
        $rawMax = Money::fromString('EUR', '1.500', 3);

        self::assertTrue($edge['quoteCapacity']['min']->greaterThan($rawMin));
        self::assertTrue($edge['quoteCapacity']['max']->greaterThan($rawMax));

        self::assertCount(2, $edge['segments']);

        $mandatory = $edge['segments'][0];
        self::assertTrue($mandatory['isMandatory']);
        self::assertTrue($mandatory['quote']['max']->equals(Money::fromString('EUR', '0.550', 3)));

        $optional = $edge['segments'][1];
        self::assertFalse($optional['isMandatory']);
        self::assertTrue($optional['quote']['max']->equals(Money::fromString('EUR', '1.100', 3)));
        self::assertArrayHasKey('grossBase', $optional);
        self::assertInstanceOf(Money::class, $optional['grossBase']['min']);
        self::assertInstanceOf(Money::class, $optional['grossBase']['max']);
    }

    public function test_build_provides_zero_gross_base_bounds_when_no_capacity_available(): void
    {
        $pointOrder = $this->createOrder(
            OrderSide::BUY,
            'BTC',
            'USD',
            '0.000',
            '0.000',
            '30000',
        );

        $graph = $this->exportGraph((new GraphBuilder())->build([$pointOrder]));

        /** @var list<GraphEdge> $edges */
        $edges = $graph['BTC']['edges'];
        self::assertCount(1, $edges);

        $edge = $edges[0];
        self::assertSame([], $edge['segments']);
        self::assertTrue($edge['grossBaseCapacity']['min']->isZero());
        self::assertTrue($edge['grossBaseCapacity']['max']->isZero());
    }

    public function test_build_reduces_base_capacity_for_sell_orders_with_base_fee(): void
    {
        $order = $this->createOrder(
            OrderSide::SELL,
            'BTC',
            'USD',
            '1.000',
            '3.000',
            '2.000',
            $this->basePercentageFeePolicy('0.10'),
        );

        $graph = $this->exportGraph((new GraphBuilder())->build([$order]));

        /** @var list<GraphEdge> $edges */
        $edges = $graph['USD']['edges'];
        self::assertCount(1, $edges);

        $edge = $edges[0];

        self::assertTrue($edge['baseCapacity']['min']->equals(Money::fromString('BTC', '0.900', 3)));
        self::assertTrue($edge['baseCapacity']['max']->equals(Money::fromString('BTC', '2.700', 3)));

        $rawMin = Money::fromString('BTC', '1.000', 3);
        $rawMax = Money::fromString('BTC', '3.000', 3);

        self::assertTrue($edge['baseCapacity']['min']->lessThan($rawMin));
        self::assertTrue($edge['baseCapacity']['max']->lessThan($rawMax));

        $segments = $edge['segments'];
        self::assertCount(2, $segments);

        $mandatory = $segments[0];
        self::assertTrue($mandatory['isMandatory']);
        self::assertTrue($mandatory['base']['max']->equals(Money::fromString('BTC', '0.900', 3)));

        $optional = $segments[1];
        self::assertFalse($optional['isMandatory']);
        self::assertTrue($optional['base']['max']->equals(Money::fromString('BTC', '1.800', 3)));
    }

    public function test_build_calculates_gross_base_capacity_for_buy_orders_with_base_fee(): void
    {
        $order = $this->createOrder(
            OrderSide::BUY,
            'BTC',
            'USD',
            '0.500',
            '2.500',
            '30000.000',
            $this->basePercentageFeePolicy('0.02'),
        );

        $graph = $this->exportGraph((new GraphBuilder())->build([$order]));

        /** @var list<GraphEdge> $edges */
        $edges = $graph['BTC']['edges'];
        self::assertCount(1, $edges);

        $edge = $edges[0];

        self::assertTrue($edge['baseCapacity']['min']->equals(Money::fromString('BTC', '0.500', 3)));
        self::assertTrue($edge['baseCapacity']['max']->equals(Money::fromString('BTC', '2.500', 3)));
        self::assertTrue($edge['grossBaseCapacity']['min']->equals(Money::fromString('BTC', '0.510', 3)));
        self::assertTrue($edge['grossBaseCapacity']['max']->equals(Money::fromString('BTC', '2.550', 3)));

        self::assertCount(2, $edge['segments']);

        $mandatory = $edge['segments'][0];
        self::assertTrue($mandatory['isMandatory']);
        self::assertTrue($mandatory['base']['max']->equals(Money::fromString('BTC', '0.500', 3)));
        self::assertTrue($mandatory['grossBase']['max']->equals(Money::fromString('BTC', '0.510', 3)));

        $optional = $edge['segments'][1];
        self::assertFalse($optional['isMandatory']);
        self::assertTrue($optional['base']['max']->equals(Money::fromString('BTC', '2.000', 3)));
        self::assertTrue($optional['grossBase']['max']->equals(Money::fromString('BTC', '2.040', 3)));
    }

    /**
     * Mixed-fee policies should increase gross base spend while simultaneously reducing net quote receipts so path scoring can reflect dual-fee flows.
     */
    public function test_build_applies_combined_base_and_quote_fees_to_buy_orders(): void
    {
        $order = $this->createOrder(
            OrderSide::BUY,
            'BTC',
            'USD',
            '1.000',
            '3.000',
            '100.000',
            $this->mixedPercentageFeePolicy('0.02', '0.05'),
        );

        $graph = $this->exportGraph((new GraphBuilder())->build([$order]));

        /** @var list<GraphEdge> $edges */
        $edges = $graph['BTC']['edges'];
        self::assertCount(1, $edges);

        $edge = $edges[0];

        self::assertTrue($edge['quoteCapacity']['min']->equals(Money::fromString('USD', '95.000', 3)));
        self::assertTrue($edge['quoteCapacity']['max']->equals(Money::fromString('USD', '285.000', 3)));

        self::assertTrue($edge['grossBaseCapacity']['min']->equals(Money::fromString('BTC', '1.020', 3)));
        self::assertTrue($edge['grossBaseCapacity']['max']->equals(Money::fromString('BTC', '3.060', 3)));

        $segments = $edge['segments'];
        self::assertCount(2, $segments);

        $mandatory = $segments[0];
        self::assertTrue($mandatory['isMandatory']);
        self::assertTrue($mandatory['quote']['max']->equals(Money::fromString('USD', '95.000', 3)));
        self::assertTrue($mandatory['grossBase']['max']->equals(Money::fromString('BTC', '1.020', 3)));

        $optional = $segments[1];
        self::assertFalse($optional['isMandatory']);
        self::assertTrue($optional['quote']['max']->equals(Money::fromString('USD', '190.000', 3)));
        self::assertTrue($optional['grossBase']['max']->equals(Money::fromString('BTC', '2.040', 3)));
    }

    public function test_build_encodes_fully_flexible_orders_as_single_segment(): void
    {
        $order = $this->createOrder(OrderSide::BUY, 'ETH', 'USN', '0.000', '5.000', '2000');

        $graph = $this->exportGraph((new GraphBuilder())->build([$order]));

        /** @var list<GraphEdge> $edges */
        $edges = $graph['ETH']['edges'];
        self::assertCount(1, $edges);

        $edge = $edges[0];
        self::assertSame([], $edge['segments']);
        self::assertTrue($edge['baseCapacity']['min']->equals(Money::fromString('ETH', '0.000', 3)));
        self::assertTrue($edge['baseCapacity']['max']->equals(Money::fromString('ETH', '5.000', 3)));
        self::assertTrue($edge['quoteCapacity']['min']->equals(Money::fromString('USN', '0.000', 3)));
        self::assertTrue($edge['quoteCapacity']['max']->equals(Money::fromString('USN', '10000.000', 3)));
        $this->assertGrossBaseEqualsBase($edge);
    }

    public function test_build_splits_bounds_into_mandatory_and_optional_segments(): void
    {
        $order = $this->createOrder(OrderSide::BUY, 'BTC', 'USD', '1.250', '3.750', '2500.000');

        $graph = $this->exportGraph((new GraphBuilder())->build([$order]));

        $edges = $graph['BTC']['edges'];
        self::assertCount(1, $edges);

        $edge = $edges[0];
        self::assertTrue($edge['baseCapacity']['min']->equals(Money::fromString('BTC', '1.250', 3)));
        self::assertTrue($edge['baseCapacity']['max']->equals(Money::fromString('BTC', '3.750', 3)));
        self::assertTrue($edge['quoteCapacity']['min']->equals(Money::fromString('USD', '3125.000', 3)));
        self::assertTrue($edge['quoteCapacity']['max']->equals(Money::fromString('USD', '9375.000', 3)));
        self::assertSame([], $edge['segments']);
    }

    public function test_build_creates_zero_capacity_segment_for_point_orders(): void
    {
        $order = $this->createOrder(OrderSide::BUY, 'ETH', 'USD', '0.000', '0.000', '1800.000');

        $graph = $this->exportGraph((new GraphBuilder())->build([$order]));

        $edges = $graph['ETH']['edges'];
        self::assertCount(1, $edges);

        $edge = $edges[0];
        self::assertSame([], $edge['segments']);
        self::assertTrue($edge['baseCapacity']['min']->isZero());
        self::assertTrue($edge['baseCapacity']['max']->isZero());
        self::assertTrue($edge['quoteCapacity']['min']->isZero());
        self::assertTrue($edge['quoteCapacity']['max']->isZero());
    }

    /**
     * @return array{0: Graph, 1: array{primaryBuyOrder: Order, secondaryBuyOrder: Order, primarySellOrder: Order, secondarySellOrder: Order}}
     */
    private function buildGraphFromSampleOrders(): array
    {
        $orders = [
            'primaryBuyOrder' => $this->createOrder(OrderSide::BUY, 'BTC', 'USD', '0.100', '1.000', '30000'),
            'secondaryBuyOrder' => $this->createOrder(OrderSide::BUY, 'BTC', 'EUR', '0.200', '0.800', '28000'),
            'primarySellOrder' => $this->createOrder(OrderSide::SELL, 'ETH', 'USD', '0.500', '2.000', '1500'),
            'secondarySellOrder' => $this->createOrder(OrderSide::SELL, 'LTC', 'USD', '1.000', '4.000', '90'),
        ];

        /** @var Graph $graphObject */
        $graphObject = (new GraphBuilder())->build([
            $orders['primaryBuyOrder'],
            $orders['secondaryBuyOrder'],
            $orders['primarySellOrder'],
            $orders['secondarySellOrder'],
        ]);

        $graph = $this->exportGraph($graphObject);

        return [$graph, $orders];
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
     * @param GraphEdge $edge
     */
    private function assertEdgeBasics(array $edge, string $from, string $to, OrderSide $side, Order $order): void
    {
        self::assertSame($from, $edge['from']);
        self::assertSame($to, $edge['to']);
        self::assertSame($side, $edge['orderSide']);
        self::assertSame($order, $edge['order']);
    }

    /**
     * @param GraphEdge $edge
     */
    private function assertEdgeCapacities(
        array $edge,
        string $baseCurrency,
        string $baseMin,
        string $baseMax,
        string $quoteCurrency,
        string $quoteMin,
        string $quoteMax,
    ): void {
        $this->assertMoneyEquals($edge['baseCapacity']['min'], $baseCurrency, $baseMin);
        $this->assertMoneyEquals($edge['baseCapacity']['max'], $baseCurrency, $baseMax);
        $this->assertMoneyEquals($edge['quoteCapacity']['min'], $quoteCurrency, $quoteMin);
        $this->assertMoneyEquals($edge['quoteCapacity']['max'], $quoteCurrency, $quoteMax);
    }

    /**
     * @param GraphEdge $edge
     */
    private function assertGrossBaseEqualsBase(array $edge): void
    {
        self::assertTrue($edge['grossBaseCapacity']['min']->equals($edge['baseCapacity']['min']));
        self::assertTrue($edge['grossBaseCapacity']['max']->equals($edge['baseCapacity']['max']));
    }

    /**
     * @param list<GraphEdge> $edges
     *
     * @return GraphEdge
     */
    private function findEdgeByOrder(array $edges, Order $order): array
    {
        foreach ($edges as $edge) {
            if ($edge['order'] === $order) {
                return $edge;
            }
        }

        self::fail('Expected edge for order was not found.');
    }

    private function assertMoneyEquals(Money $actual, string $currency, string $amount): void
    {
        self::assertTrue($actual->equals($this->money($currency, $amount)));
    }

    private function money(string $currency, string $amount): Money
    {
        return Money::fromString($currency, $amount, 3);
    }

    /**
     * @param non-empty-string $base
     * @param non-empty-string $quote
     */
    private function createOrder(
        OrderSide $side,
        string $base,
        string $quote,
        string $min,
        string $max,
        string $rate,
        ?FeePolicy $feePolicy = null,
    ): Order {
        $assetPair = AssetPair::fromString($base, $quote);
        $bounds = OrderBounds::from(
            Money::fromString($base, $min, 3),
            Money::fromString($base, $max, 3),
        );
        $exchangeRate = ExchangeRate::fromString($base, $quote, $rate, 3);

        return new Order($side, $assetPair, $bounds, $exchangeRate, $feePolicy);
    }

    private function percentageFeePolicy(string $percentage): FeePolicy
    {
        return new class($percentage) implements FeePolicy {
            public function __construct(private readonly string $percentage)
            {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $fee = $quoteAmount->multiply($this->percentage, $quoteAmount->scale());

                return FeeBreakdown::forQuote($fee);
            }

            public function fingerprint(): string
            {
                return 'percentage-quote:'.$this->percentage;
            }
        };
    }

    private function basePercentageFeePolicy(string $percentage): FeePolicy
    {
        return new class($percentage) implements FeePolicy {
            public function __construct(private readonly string $percentage)
            {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $fee = $baseAmount->multiply($this->percentage, $baseAmount->scale());

                return FeeBreakdown::forBase($fee);
            }

            public function fingerprint(): string
            {
                return 'percentage-base:'.$this->percentage;
            }
        };
    }

    private function mixedPercentageFeePolicy(string $basePercentage, string $quotePercentage): FeePolicy
    {
        return new class($basePercentage, $quotePercentage) implements FeePolicy {
            public function __construct(
                private readonly string $basePercentage,
                private readonly string $quotePercentage,
            ) {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $baseFee = $baseAmount->multiply($this->basePercentage, $baseAmount->scale());
                $quoteFee = $quoteAmount->multiply($this->quotePercentage, $quoteAmount->scale());

                return FeeBreakdown::of($baseFee, $quoteFee);
            }

            public function fingerprint(): string
            {
                return 'percentage-mixed:'.$this->basePercentage.':'.$this->quotePercentage;
            }
        };
    }
}
