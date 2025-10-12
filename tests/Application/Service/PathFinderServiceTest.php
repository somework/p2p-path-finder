<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

use function sprintf;
use function substr;

final class PathFinderServiceTest extends TestCase
{
    public function test_it_builds_multi_hop_path_and_aggregates_amounts(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3),
            $this->createOrder(OrderSide::SELL, 'JPY', 'EUR', '10.000', '20000.000', '0.007500', 6),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.25)
            ->withHopLimits(1, 3)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'JPY');

        self::assertNotNull($result);
        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('100.000', $result->totalSpent()->amount());
        self::assertSame('JPY', $result->totalReceived()->currency());
        self::assertSame('16665.000', $result->totalReceived()->amount());
        self::assertSame(0.0, $result->residualTolerance());

        $legs = $result->legs();
        self::assertCount(2, $legs);

        self::assertSame('EUR', $legs[0]->from());
        self::assertSame('USD', $legs[0]->to());
        self::assertSame('100.000', $legs[0]->spent()->amount());
        self::assertSame('111.100', $legs[0]->received()->amount());

        self::assertSame('USD', $legs[1]->from());
        self::assertSame('JPY', $legs[1]->to());
        self::assertSame('111.100', $legs[1]->spent()->amount());
        self::assertSame('16665.000', $legs[1]->received()->amount());
    }

    public function test_it_materializes_leg_fees_and_breakdown(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3, $this->percentageFeePolicy('0.01')),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3, $this->percentageFeePolicy('0.02')),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.25)
            ->withHopLimits(1, 2)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'JPY');

        self::assertNotNull($result);

        $legs = $result->legs();
        self::assertCount(2, $legs);

        self::assertSame('100.000', $legs[0]->spent()->amount());
        self::assertSame('112.233', $legs[0]->received()->amount());
        self::assertSame('EUR', $legs[0]->fee()->currency());
        self::assertSame('1.010', $legs[0]->fee()->amount());

        self::assertSame('112.233', $legs[1]->spent()->amount());
        self::assertSame('17171.649', $legs[1]->received()->amount());
        self::assertSame('JPY', $legs[1]->fee()->currency());
        self::assertSame('336.699', $legs[1]->fee()->amount());

        $feeBreakdown = $result->feeBreakdown();
        self::assertCount(2, $feeBreakdown);
        self::assertArrayHasKey('EUR', $feeBreakdown);
        self::assertArrayHasKey('JPY', $feeBreakdown);
        self::assertSame('1.010', $feeBreakdown['EUR']->amount());
        self::assertSame('336.699', $feeBreakdown['JPY']->amount());
    }

    public function test_it_skips_highest_scoring_path_when_complex_book_lacks_capacity(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '80.000', '0.600', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '500.000', '0.800', 3),
            $this->createOrder(OrderSide::SELL, 'CHF', 'EUR', '5.000', '400.000', '0.920', 3),
            $this->createOrder(OrderSide::SELL, 'AUD', 'EUR', '5.000', '400.000', '0.700', 3),
            $this->createOrder(OrderSide::SELL, 'CAD', 'EUR', '5.000', '400.000', '0.750', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '500.000', '1.200', 3),
            $this->createOrder(OrderSide::BUY, 'CHF', 'USD', '5.000', '500.000', '1.050', 3),
            $this->createOrder(OrderSide::BUY, 'AUD', 'USD', '5.000', '500.000', '0.650', 3),
            $this->createOrder(OrderSide::BUY, 'CAD', 'USD', '5.000', '500.000', '0.730', 3),
            $this->createOrder(OrderSide::BUY, 'EUR', 'CHF', '5.000', '500.000', '1.100', 3),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.20)
            ->withHopLimits(1, 3)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'USD');

        self::assertNotNull($result);
        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('100.000', $result->totalSpent()->amount());
        self::assertSame('USD', $result->totalReceived()->currency());
        self::assertSame('150.000', $result->totalReceived()->amount());

        $legs = $result->legs();
        self::assertCount(2, $legs);
        self::assertSame('EUR', $legs[0]->from());
        self::assertSame('GBP', $legs[0]->to());
        self::assertSame('100.000', $legs[0]->spent()->amount());
        self::assertSame('125.000', $legs[0]->received()->amount());

        self::assertSame('GBP', $legs[1]->from());
        self::assertSame('USD', $legs[1]->to());
        self::assertSame('125.000', $legs[1]->spent()->amount());
        self::assertSame('150.000', $legs[1]->received()->amount());
    }

    public function test_it_prefers_best_rates_when_multiple_identical_pairs_exist(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '80.000', '0.680', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '500.000', '0.760', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '500.000', '0.780', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '500.000', '0.710', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '500.000', '0.700', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '80.000', '1.350', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '500.000', '1.220', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '500.000', '1.200', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '500.000', '1.180', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '500.000', '1.250', 3),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.15)
            ->withHopLimits(1, 3)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'USD');

        self::assertNotNull($result);
        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('100.000', $result->totalSpent()->amount());
        self::assertSame('USD', $result->totalReceived()->currency());
        self::assertSame('178.625', $result->totalReceived()->amount());

        $legs = $result->legs();
        self::assertCount(2, $legs);

        self::assertSame('EUR', $legs[0]->from());
        self::assertSame('GBP', $legs[0]->to());
        self::assertSame('100.000', $legs[0]->spent()->amount());
        self::assertSame('142.900', $legs[0]->received()->amount());

        self::assertSame('GBP', $legs[1]->from());
        self::assertSame('USD', $legs[1]->to());
        self::assertSame('142.900', $legs[1]->spent()->amount());
        self::assertSame('178.625', $legs[1]->received()->amount());
    }

    public function test_it_returns_null_when_tolerance_window_filters_out_orders(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
            $this->createOrder(OrderSide::SELL, 'JPY', 'EUR', '10.000', '20000.000', '0.007500', 6),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '5.00', 2))
            ->withToleranceBounds(0.0, 0.40)
            ->withHopLimits(1, 2)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'USD');

        self::assertNull($result);
    }

    public function test_it_enforces_minimum_hop_requirement(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3),
            $this->createOrder(OrderSide::SELL, 'JPY', 'EUR', '10.000', '20000.000', '0.007500', 6),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.25)
            ->withHopLimits(3, 3)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'JPY');

        self::assertNull($result);
    }

    public function test_it_handles_under_spend_within_tolerance_bounds(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '5.000', '8.000', '0.900', 3),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '8.00', 2))
            ->withToleranceBounds(0.25, 0.05)
            ->withHopLimits(1, 1)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'USD');

        self::assertNotNull($result);
        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('7.200', $result->totalSpent()->amount());
        self::assertSame('USD', $result->totalReceived()->currency());
        self::assertSame('7.999', $result->totalReceived()->amount());
        self::assertEqualsWithDelta(0.1, $result->residualTolerance(), 1e-9);
    }

    public function test_it_refines_sell_legs_until_effective_quote_matches(): void
    {
        $feePolicy = $this->tieredFeePolicy('310.000', '0.05', '0.35', '25.000');
        $order = $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '0.001', '1000.000', '0.400', 3, $feePolicy);

        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '220.000', 3))
            ->withToleranceBounds(0.0, 0.0)
            ->withHopLimits(1, 1)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'USD');

        self::assertNotNull($result);
        $legs = $result->legs();
        self::assertCount(1, $legs);

        $leg = $legs[0];

        $rawQuote = $order->calculateQuoteAmount($leg->received());
        $expectedFee = $feePolicy->calculate(OrderSide::SELL, $leg->received(), $rawQuote);
        $effectiveQuote = $order->calculateEffectiveQuoteAmount($leg->received());

        $comparisonScale = max($effectiveQuote->scale(), $leg->spent()->scale(), 6);
        $actualAmount = $effectiveQuote->withScale($comparisonScale)->amount();
        $reportedAmount = $leg->spent()->withScale($comparisonScale)->amount();
        $difference = BcMath::sub($actualAmount, $reportedAmount, $comparisonScale + 6);
        if ('-' === $difference[0]) {
            $difference = substr($difference, 1);
        }

        if ('' === $difference) {
            $difference = '0';
        }

        $difference = BcMath::normalize($difference, $comparisonScale + 6);
        $relativeDifference = BcMath::div($difference, $actualAmount, $comparisonScale + 6);

        self::assertTrue(
            BcMath::comp($relativeDifference, '0.000001', $comparisonScale + 6) <= 0,
            sprintf('Effective quote mismatch of %s exceeds tolerance.', $difference),
        );

        $feeScale = max($expectedFee->scale(), $leg->fee()->scale(), 6);
        self::assertSame(
            $expectedFee->withScale($feeScale)->amount(),
            $leg->fee()->withScale($feeScale)->amount(),
        );
    }

    public function test_it_returns_null_when_sell_leg_cannot_meet_target_after_refinement(): void
    {
        $feePolicy = $this->tieredFeePolicy('310.000', '0.05', '0.35', '25.000');
        $order = $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '0.001', '1000.000', '0.400', 3, $feePolicy);

        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '450.000', 3))
            ->withToleranceBounds(0.0, 0.0)
            ->withHopLimits(1, 1)
            ->build();

        $service = new PathFinderService(new GraphBuilder());

        self::assertNull($service->findBestPath($orderBook, $config, 'USD'));
    }

    private function createOrder(OrderSide $side, string $base, string $quote, string $min, string $max, string $rate, int $rateScale, ?FeePolicy $feePolicy = null): Order
    {
        $assetPair = AssetPair::fromString($base, $quote);
        $bounds = OrderBounds::from(
            Money::fromString($base, $min, 3),
            Money::fromString($base, $max, 3),
        );
        $exchangeRate = ExchangeRate::fromString($base, $quote, $rate, $rateScale);

        return new Order($side, $assetPair, $bounds, $exchangeRate, $feePolicy);
    }

    private function percentageFeePolicy(string $percentage): FeePolicy
    {
        return new class($percentage) implements FeePolicy {
            public function __construct(private readonly string $percentage)
            {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): Money
            {
                return $quoteAmount->multiply($this->percentage, $quoteAmount->scale());
            }
        };
    }

    private function tieredFeePolicy(string $threshold, string $lowPercentage, string $highPercentage, string $fixed): FeePolicy
    {
        return new class($threshold, $lowPercentage, $highPercentage, $fixed) implements FeePolicy {
            public function __construct(
                private readonly string $threshold,
                private readonly string $lowPercentage,
                private readonly string $highPercentage,
                private readonly string $fixed,
            ) {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): Money
            {
                $scale = max($quoteAmount->scale(), 6);
                $threshold = Money::fromString($quoteAmount->currency(), $this->threshold, $scale);

                if ($quoteAmount->greaterThan($threshold)) {
                    $percentageComponent = $quoteAmount->multiply($this->highPercentage, $scale);
                    $fixedComponent = Money::fromString($quoteAmount->currency(), $this->fixed, $scale);

                    return $percentageComponent->add($fixedComponent, $scale);
                }

                return $quoteAmount->multiply($this->lowPercentage, $scale);
            }
        };
    }
}
