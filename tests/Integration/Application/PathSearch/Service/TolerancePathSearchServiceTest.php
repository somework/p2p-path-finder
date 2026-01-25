<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use ReflectionProperty;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Response\SearchOutcome;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\PathSearchEngine;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\Path;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class TolerancePathSearchServiceTest extends PathSearchServiceTestCase
{
    /**
     * @return list<Path>
     */
    private static function extractPaths(SearchOutcome $result): array
    {
        return $result->paths()->toArray();
    }

    private static function extractGuardLimits(SearchOutcome $result): SearchGuardReport
    {
        return $result->guardLimits();
    }

    // NOTE: The following tolerance-specific tests were removed as part of MUL-12 cleanup:
    //
    // - test_it_handles_buy_base_fee_within_tolerance_window
    //   Reason: PathSearchEngine-specific tolerance clamping behavior.
    //   ExecutionPlanService calculates fill amounts differently.
    //
    // - test_it_caps_buy_gross_spend_at_tolerance_upper_bound
    //   Reason: PathSearchEngine-specific fee clamping behavior.
    //   ExecutionPlanService has different tolerance handling.
    //
    // Tolerance evaluation is now handled by ToleranceEvaluator which applies
    // tolerance constraints after ExecutionPlanService finds optimal plans.

    /**
     * @testdox Rejects candidate paths when tolerance rules from the provider scenarios are violated
     *
     * @dataProvider toleranceRejectionProvider
     *
     * @param list<array{
     *     side: OrderSide,
     *     base: non-empty-string,
     *     quote: non-empty-string,
     *     min: numeric-string,
     *     max: numeric-string,
     *     rate: numeric-string,
     *     scale: positive-int,
     *     feePolicy?: array{type: string, value: numeric-string}|null,
     * }> $orders
     * @param array{currency: non-empty-string, amount: numeric-string, scale: int} $spend
     * @param array{0: string, 1: string}                                           $tolerance
     * @param array{0: int, 1: int}                                                 $hopLimits
     * @param non-empty-string                                                      $target
     */
    public function test_it_rejects_paths_outside_tolerance_window(
        array $orders,
        array $spend,
        array $tolerance,
        array $hopLimits,
        string $target
    ): void {
        $orderList = array_map(fn (array $order): Order => $this->createOrder(
            $order['side'],
            $order['base'],
            $order['quote'],
            $order['min'],
            $order['max'],
            $order['rate'],
            $order['scale'],
            $this->resolveFeePolicy($order['feePolicy'] ?? null),
        ), $orders);

        $orderBook = $this->orderBook(...$orderList);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString($spend['currency'], $spend['amount'], $spend['scale']))
            ->withToleranceBounds($tolerance[0], $tolerance[1])
            ->withHopLimits($hopLimits[0], $hopLimits[1])
            ->build();

        $searchResult = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, $target));

        self::assertSame([], self::extractPaths($searchResult));
        $guardLimits = self::extractGuardLimits($searchResult);
        self::assertFalse($guardLimits->expansionsReached());
        self::assertFalse($guardLimits->visitedStatesReached());
    }

    /**
     * @return iterable<array{
     *     0: list<array{
     *         side: OrderSide,
     *         base: non-empty-string,
     *         quote: non-empty-string,
     *         min: numeric-string,
     *         max: numeric-string,
     *         rate: numeric-string,
     *         scale: positive-int,
     *         feePolicy?: array{type: string, value: numeric-string}|null,
     *     }>,
     *     1: array{currency: non-empty-string, amount: numeric-string, scale: int},
     *     2: array{0: string, 1: string},
     *     3: array{0: int, 1: int},
     *     4: non-empty-string,
     * }>
     */
    public static function toleranceRejectionProvider(): iterable
    {
        yield 'insufficient tolerance for chained sell' => [
            [
                [
                    'side' => OrderSide::SELL,
                    'base' => 'USD',
                    'quote' => 'EUR',
                    'min' => '10.000',
                    'max' => '200.000',
                    'rate' => '0.900',
                    'scale' => 3,
                ],
                [
                    'side' => OrderSide::SELL,
                    'base' => 'JPY',
                    'quote' => 'EUR',
                    'min' => '10.000',
                    'max' => '20000.000',
                    'rate' => '0.007500',
                    'scale' => 6,
                ],
            ],
            ['currency' => 'EUR', 'amount' => '5.00', 'scale' => 2],
            ['0.0', '0.40'],
            [1, 2],
            'USD',
        ];

        yield 'gross spend exceeds tolerance' => [
            [
                [
                    'side' => OrderSide::SELL,
                    'base' => 'USD',
                    'quote' => 'EUR',
                    'min' => '100.000',
                    'max' => '200.000',
                    'rate' => '1.000',
                    'scale' => 3,
                    'feePolicy' => ['type' => 'percentage', 'value' => '0.10'],
                ],
            ],
            ['currency' => 'EUR', 'amount' => '100.00', 'scale' => 2],
            ['0.0', '0.05'],
            [1, 1],
            'USD',
        ];
    }

    /**
     * @testdox Enforces minimum hop requirement even when an otherwise valid multi-hop bridge exists
     */
    public function test_it_enforces_minimum_hop_requirement(): void
    {
        $orderBook = $this->scenarioEuroToUsdToJpyBridge();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.25')
            ->withHopLimits(3, 3)
            ->build();

        $searchResult = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'JPY'));

        self::assertSame([], self::extractPaths($searchResult));
        $guardLimits = self::extractGuardLimits($searchResult);
        self::assertFalse($guardLimits->expansionsReached());
        self::assertFalse($guardLimits->visitedStatesReached());
    }

    // NOTE: Additional tolerance-specific tests removed as part of MUL-12:
    //
    // - test_it_handles_under_spend_within_tolerance_bounds
    //   Reason: PathSearchEngine-specific underspend calculation.
    //
    // - test_it_discovers_buy_path_when_order_minimum_exceeds_configured_minimum
    //   Reason: PathSearchEngine-specific order minimum scaling.
    //
    // - test_it_discovers_sell_path_when_order_minimum_exceeds_configured_minimum
    //   Reason: PathSearchEngine-specific order minimum scaling.
    //
    // ExecutionPlanService uses OrderSpendAnalyzer for spend constraint filtering
    // and ToleranceEvaluator for post-search tolerance validation.

    public function test_it_propagates_high_precision_tolerance_to_path_finder(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '1.00', 2))
            ->withToleranceBounds('0.1', '0.999999999999999999')
            ->withHopLimits(1, 1)
            ->build();

        $tolerance = $config->pathFinderTolerance();

        self::assertIsString($tolerance);

        $pathFinder = new PathSearchEngine($config->maximumHops(), $tolerance);

        $amplifierProperty = new ReflectionProperty(PathSearchEngine::class, 'toleranceAmplifier');
        $amplifierProperty->setAccessible(true);

        $amplifier = $amplifierProperty->getValue($pathFinder);

        self::assertInstanceOf(BigDecimal::class, $amplifier);
        $normalizedTolerance = BigDecimal::of($tolerance)->toScale(18, RoundingMode::HALF_UP);
        $expectedAmplifier = BigDecimal::one()
            ->dividedBy(BigDecimal::one()->minus($normalizedTolerance), 18, RoundingMode::HALF_UP)
            ->__toString();

        self::assertSame($expectedAmplifier, $amplifier->__toString());
    }

    /**
     * @param array{type: string, value: numeric-string}|null $definition
     */
    private function resolveFeePolicy(?array $definition): ?FeePolicy
    {
        if (null === $definition) {
            return null;
        }

        return match ($definition['type']) {
            'percentage' => $this->percentageFeePolicy($definition['value']),
            default => throw new InvalidInput('Unsupported fee policy type: '.$definition['type']),
        };
    }

    // NOTE: scenarioEurBuyWithBaseFeeWithinTolerance() and scenarioEurBuyClampedByTolerance()
    // were removed as part of MUL-12 cleanup - no longer used after removing legacy tests.

    private function scenarioEuroToUsdToJpyBridge(): OrderBook
    {
        return $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3),
            $this->createOrder(OrderSide::SELL, 'JPY', 'EUR', '10.000', '20000.000', '0.007500', 6),
        );
    }
}
