<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service\PathFinder;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Application\Service\PathSearchRequest;
use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

use function implode;
use function max;
use function sprintf;

abstract class PathFinderServiceTestCase extends TestCase
{
    protected function makeService(): PathFinderService
    {
        return new PathFinderService(new GraphBuilder());
    }

    protected function makeServiceWithFactory(Closure $factory): PathFinderService
    {
        return PathFinderService::withRunnerFactory(new GraphBuilder(), orderingStrategy: null, pathFinderFactory: $factory);
    }

    protected function makeRequest(OrderBook $orderBook, PathSearchConfig $config, string $targetAsset): PathSearchRequest
    {
        return new PathSearchRequest($orderBook, $config, $targetAsset);
    }

    protected function createOrder(
        OrderSide $side,
        string $base,
        string $quote,
        string $min,
        string $max,
        string $rate,
        int $rateScale,
        ?FeePolicy $feePolicy = null,
    ): Order {
        $assetPair = AssetPair::fromString($base, $quote);
        $bounds = OrderBounds::from(
            Money::fromString($base, $min, 3),
            Money::fromString($base, $max, 3),
        );
        $exchangeRate = ExchangeRate::fromString($base, $quote, $rate, $rateScale);

        return new Order($side, $assetPair, $bounds, $exchangeRate, $feePolicy);
    }

    protected function orderBook(Order ...$orders): OrderBook
    {
        return new OrderBook($orders);
    }

    /**
     * @param list<Order> $orders
     */
    protected function orderBookFromArray(array $orders): OrderBook
    {
        return new OrderBook($orders);
    }

    protected function assertGrossWithinTolerance(Money $requested, Money $actual, string $maximumTolerance, string $message): void
    {
        $grossScale = max($requested->scale(), $actual->scale());
        $requestedGross = $requested->withScale($grossScale)->amount();
        $actualGross = $actual->withScale($grossScale)->amount();

        $comparisonScale = $grossScale + 6;
        $requestedDecimal = BigDecimal::of($requestedGross)->toScale($comparisonScale, RoundingMode::HALF_UP);
        $actualDecimal = BigDecimal::of($actualGross)->toScale($comparisonScale, RoundingMode::HALF_UP);

        $difference = $actualDecimal->minus($requestedDecimal)->abs();
        $relativeDifference = $requestedDecimal->isZero()
            ? BigDecimal::zero()->toScale($comparisonScale, RoundingMode::HALF_UP)
            : $difference->dividedBy($requestedDecimal, $comparisonScale, RoundingMode::HALF_UP);

        $threshold = BigDecimal::of($maximumTolerance)->toScale($comparisonScale, RoundingMode::HALF_UP);

        self::assertTrue(
            $relativeDifference->compareTo($threshold) <= 0,
            sprintf($message, $difference->__toString()),
        );
    }

    protected function assertSellLegRefinementMatches(
        Order $order,
        FeePolicy $feePolicy,
        Money $target,
        Money $grossSpent,
        Money $baseReceived,
        FeeBreakdown $fees,
    ): void {
        $baseFill = $baseReceived;
        $baseFee = $fees->baseFee();
        if (null !== $baseFee && !$baseFee->isZero()) {
            $baseFill = $baseReceived->add($baseFee);
        }

        $rawQuote = $order->calculateQuoteAmount($baseFill);
        $expectedBreakdown = $feePolicy->calculate(OrderSide::SELL, $baseFill, $rawQuote);
        $expectedFee = $expectedBreakdown->quoteFee();
        self::assertNotNull($expectedFee);
        $effectiveQuote = $order->calculateEffectiveQuoteAmount($baseFill);

        $comparisonScale = max($effectiveQuote->scale(), $target->scale(), 6);
        $actualAmount = $effectiveQuote->withScale($comparisonScale)->amount();
        $targetAmount = $target->withScale($comparisonScale)->amount();
        $scale = $comparisonScale + 6;
        $actualDecimal = BigDecimal::of($actualAmount)->toScale($scale, RoundingMode::HALF_UP);
        $targetDecimal = BigDecimal::of($targetAmount)->toScale($scale, RoundingMode::HALF_UP);

        $difference = $actualDecimal->minus($targetDecimal)->abs();
        $relativeDifference = $targetDecimal->isZero()
            ? BigDecimal::zero()->toScale($scale, RoundingMode::HALF_UP)
            : $difference->dividedBy($targetDecimal, $scale, RoundingMode::HALF_UP);

        $threshold = BigDecimal::of('0.00001')->toScale($scale, RoundingMode::HALF_UP);

        self::assertTrue(
            $relativeDifference->compareTo($threshold) <= 0,
            sprintf('Effective quote mismatch of %s exceeds tolerance.', $difference->__toString()),
        );

        $grossComparisonScale = max($rawQuote->scale(), $expectedFee->scale(), $grossSpent->scale(), 6);
        $expectedGross = $rawQuote->add($expectedFee, $grossComparisonScale);

        self::assertSame(
            $expectedGross->withScale($grossComparisonScale)->amount(),
            $grossSpent->withScale($grossComparisonScale)->amount(),
        );

        $actualFee = $fees->quoteFee();
        self::assertNotNull($actualFee);

        $feeScale = max($expectedFee->scale(), $actualFee->scale(), 6);
        self::assertSame(
            $expectedFee->withScale($feeScale)->amount(),
            $actualFee->withScale($feeScale)->amount(),
        );
    }

    protected function basePercentageFeePolicy(string $percentage): FeePolicy
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

    protected function percentageFeePolicy(string $percentage): FeePolicy
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

    protected function mixedPercentageFeePolicy(string $basePercentage, string $quotePercentage): FeePolicy
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

    protected function tieredFeePolicy(string $threshold, string $lowPercentage, string $highPercentage, string $fixed): FeePolicy
    {
        return new class($threshold, $lowPercentage, $highPercentage, $fixed) implements FeePolicy {
            public function __construct(
                private readonly string $threshold,
                private readonly string $lowPercentage,
                private readonly string $highPercentage,
                private readonly string $fixed,
            ) {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $scale = max($quoteAmount->scale(), 6);
                $threshold = Money::fromString($quoteAmount->currency(), $this->threshold, $scale);

                if ($quoteAmount->greaterThan($threshold)) {
                    $percentageComponent = $quoteAmount->multiply($this->highPercentage, $scale);
                    $fixedComponent = Money::fromString($quoteAmount->currency(), $this->fixed, $scale);

                    $fee = $percentageComponent->add($fixedComponent, $scale);

                    return FeeBreakdown::forQuote($fee);
                }

                $fee = $quoteAmount->multiply($this->lowPercentage, $scale);

                return FeeBreakdown::forQuote($fee);
            }

            public function fingerprint(): string
            {
                return implode(':', ['tiered', $this->threshold, $this->lowPercentage, $this->highPercentage, $this->fixed]);
            }
        };
    }
}
