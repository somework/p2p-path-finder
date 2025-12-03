<?php

declare(strict_types=1);

/**
 * Example: Custom Fee Policy Implementations.
 *
 * This example demonstrates how to implement and use custom FeePolicy implementations
 * to define fee calculation logic for orders. It shows realistic fee scenarios including:
 * - Percentage-based fees
 * - Fixed fees
 * - Tiered fees based on volume
 * - Maker/Taker fee models
 * - Combined percentage + fixed fees
 *
 * Run: php examples/custom-fee-policy.php
 */

require __DIR__.'/../vendor/autoload.php';

use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;
use SomeWork\P2PPathFinder\Domain\Money\AssetPair;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\OrderBounds;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;

// ============================================================================
// Example 1: Percentage Fee Policy (Most Common)
// ============================================================================

/**
 * Fee policy that charges a percentage of the quote amount.
 *
 * This is the most common fee model used by exchanges. The fee is calculated
 * as a percentage of the traded quote amount (the amount you receive or pay).
 *
 * Example: 0.5% fee means you pay $0.50 for every $100 traded.
 */
final class PercentageFeePolicy implements FeePolicy
{
    /**
     * @param string $rate  The fee rate as a decimal string (e.g., "0.005" for 0.5%)
     * @param int    $scale The decimal scale for calculations
     */
    public function __construct(
        private readonly string $rate,
        private readonly int $scale = 6,
    ) {
    }

    public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
    {
        // Calculate fee as percentage of quote amount
        $feeAmount = bcmul($quoteAmount->amount(), $this->rate, $this->scale);

        // Fee is charged in the quote currency
        $fee = Money::fromString(
            $quoteAmount->currency(),
            $feeAmount,
            $this->scale
        );

        return FeeBreakdown::forQuote($fee);
    }

    public function fingerprint(): string
    {
        return sprintf('percentage-quote:%s:%d', $this->rate, $this->scale);
    }
}

// ============================================================================
// Example 2: Fixed Fee Policy
// ============================================================================

/**
 * Fee policy that charges a fixed amount per transaction.
 *
 * Useful for:
 * - Flat-rate transaction fees
 * - Network/processing fees
 * - Minimum fee requirements
 *
 * The fee is constant regardless of trade size.
 */
final class FixedFeePolicy implements FeePolicy
{
    private readonly Money $fixedFee;

    /**
     * @param string $currency The currency for the fee
     * @param string $amount   The fixed fee amount
     * @param int    $scale    The decimal scale
     */
    public function __construct(
        string $currency,
        string $amount,
        int $scale = 2,
    ) {
        $this->fixedFee = Money::fromString($currency, $amount, $scale);
    }

    public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
    {
        // Check which currency matches our fixed fee
        if ($this->fixedFee->currency() === $baseAmount->currency()) {
            return FeeBreakdown::forBase($this->fixedFee);
        }

        if ($this->fixedFee->currency() === $quoteAmount->currency()) {
            return FeeBreakdown::forQuote($this->fixedFee);
        }

        // If currency doesn't match either side, no fee can be applied
        // In practice, this should be caught during order validation
        return FeeBreakdown::none();
    }

    public function fingerprint(): string
    {
        return sprintf(
            'fixed:%s:%s:%d',
            $this->fixedFee->currency(),
            $this->fixedFee->amount(),
            2 // Scale is standardized in fingerprint
        );
    }
}

// ============================================================================
// Example 3: Tiered Fee Policy (Volume-Based)
// ============================================================================

/**
 * Fee policy with tiered rates based on transaction volume.
 *
 * This model is common on exchanges that reward larger trades with lower fees.
 * Different fee rates apply depending on the trade size.
 *
 * Example:
 * - Trades under $1,000: 0.5% fee
 * - Trades $1,000 to $10,000: 0.3% fee
 * - Trades over $10,000: 0.1% fee
 */
final class TieredFeePolicy implements FeePolicy
{
    /**
     * @param string                                       $currency Reference currency for tier thresholds
     * @param list<array{threshold: string, rate: string}> $tiers    Ascending tier definitions
     * @param int                                          $scale    Decimal scale for calculations
     */
    public function __construct(
        private readonly string $currency,
        private readonly array $tiers,
        private readonly int $scale = 6,
    ) {
    }

    public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
    {
        // Determine the trade amount in our reference currency
        $tradeAmount = match ($this->currency) {
            $baseAmount->currency() => $baseAmount->amount(),
            $quoteAmount->currency() => $quoteAmount->amount(),
            default => '0', // Currency mismatch, default to lowest tier
        };

        // Find applicable tier rate
        $applicableRate = $this->tiers[0]['rate']; // Default to first tier
        foreach ($this->tiers as $tier) {
            if (bccomp($tradeAmount, $tier['threshold'], $this->scale) >= 0) {
                $applicableRate = $tier['rate'];
            } else {
                break; // Tiers are ascending, so stop at first non-matching
            }
        }

        // Calculate fee using the applicable rate on quote amount
        $feeAmount = bcmul($quoteAmount->amount(), $applicableRate, $this->scale);
        $fee = Money::fromString($quoteAmount->currency(), $feeAmount, $this->scale);

        return FeeBreakdown::forQuote($fee);
    }

    public function fingerprint(): string
    {
        // Include all tier definitions in fingerprint
        $tierStrings = [];
        foreach ($this->tiers as $tier) {
            $tierStrings[] = sprintf('%s:%s', $tier['threshold'], $tier['rate']);
        }

        return sprintf(
            'tiered:%s:%s:%d',
            $this->currency,
            implode('|', $tierStrings),
            $this->scale
        );
    }
}

// ============================================================================
// Example 4: Maker/Taker Fee Policy
// ============================================================================

/**
 * Fee policy with different rates for maker (SELL) vs taker (BUY) orders.
 *
 * Common in order book exchanges where:
 * - Makers (liquidity providers) pay lower fees or get rebates
 * - Takers (liquidity consumers) pay higher fees
 *
 * In our model:
 * - SELL orders are makers (adding liquidity)
 * - BUY orders are takers (taking liquidity)
 */
final class MakerTakerFeePolicy implements FeePolicy
{
    /**
     * @param string $makerRate Fee rate for maker orders (SELL)
     * @param string $takerRate Fee rate for taker orders (BUY)
     * @param int    $scale     Decimal scale for calculations
     */
    public function __construct(
        private readonly string $makerRate,
        private readonly string $takerRate,
        private readonly int $scale = 6,
    ) {
    }

    public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
    {
        // Select rate based on order side
        $rate = match ($side) {
            OrderSide::SELL => $this->makerRate,
            OrderSide::BUY => $this->takerRate,
        };

        // Calculate fee on quote amount
        $feeAmount = bcmul($quoteAmount->amount(), $rate, $this->scale);
        $fee = Money::fromString($quoteAmount->currency(), $feeAmount, $this->scale);

        return FeeBreakdown::forQuote($fee);
    }

    public function fingerprint(): string
    {
        return sprintf(
            'maker-taker:%s:%s:%d',
            $this->makerRate,
            $this->takerRate,
            $this->scale
        );
    }
}

// ============================================================================
// Example 5: Combined Percentage + Fixed Fee Policy
// ============================================================================

/**
 * Fee policy that combines a percentage fee with a fixed minimum/maximum.
 *
 * Common models:
 * - Percentage + minimum (e.g., "0.5% or $2.50, whichever is greater")
 * - Percentage + maximum (e.g., "0.5% capped at $50")
 * - Percentage + both (e.g., "0.5% with $2.50 min and $50 max")
 */
final class CombinedFeePolicy implements FeePolicy
{
    private readonly ?Money $minimumFee;
    private readonly ?Money $maximumFee;

    /**
     * @param string      $percentageRate The percentage fee rate
     * @param string|null $minAmount      Minimum fee amount (null for no minimum)
     * @param string|null $maxAmount      Maximum fee amount (null for no cap)
     * @param string      $currency       Currency for min/max fees
     * @param int         $scale          Decimal scale
     */
    public function __construct(
        private readonly string $percentageRate,
        ?string $minAmount,
        ?string $maxAmount,
        string $currency,
        private readonly int $scale = 6,
    ) {
        $this->minimumFee = null !== $minAmount
            ? Money::fromString($currency, $minAmount, $scale)
            : null;

        $this->maximumFee = null !== $maxAmount
            ? Money::fromString($currency, $maxAmount, $scale)
            : null;
    }

    public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
    {
        // Calculate percentage fee
        $percentageFeeAmount = bcmul($quoteAmount->amount(), $this->percentageRate, $this->scale);

        // Apply minimum constraint
        if (null !== $this->minimumFee && $this->minimumFee->currency() === $quoteAmount->currency()) {
            if (bccomp($percentageFeeAmount, $this->minimumFee->amount(), $this->scale) < 0) {
                $percentageFeeAmount = $this->minimumFee->amount();
            }
        }

        // Apply maximum constraint
        if (null !== $this->maximumFee && $this->maximumFee->currency() === $quoteAmount->currency()) {
            if (bccomp($percentageFeeAmount, $this->maximumFee->amount(), $this->scale) > 0) {
                $percentageFeeAmount = $this->maximumFee->amount();
            }
        }

        $fee = Money::fromString($quoteAmount->currency(), $percentageFeeAmount, $this->scale);

        return FeeBreakdown::forQuote($fee);
    }

    public function fingerprint(): string
    {
        $minPart = null !== $this->minimumFee
            ? sprintf('%s:%s', $this->minimumFee->currency(), $this->minimumFee->amount())
            : 'none';

        $maxPart = null !== $this->maximumFee
            ? sprintf('%s:%s', $this->maximumFee->currency(), $this->maximumFee->amount())
            : 'none';

        return sprintf(
            'combined:%s:%s:%s:%d',
            $this->percentageRate,
            $minPart,
            $maxPart,
            $this->scale
        );
    }
}

// ============================================================================
// Demonstration: Compare Fee Models
// ============================================================================

function createOrderBookWithFees(FeePolicy $feePolicy): OrderBook
{
    $orders = [
        // USD -> EUR (direct path)
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('USD', 'EUR'),
            OrderBounds::from(
                Money::fromString('USD', '10.00', 2),
                Money::fromString('USD', '1000.00', 2),
            ),
            ExchangeRate::fromString('USD', 'EUR', '0.92', 6),
            $feePolicy,
        ),

        // USD -> GBP -> EUR (2-hop path)
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('USD', 'GBP'),
            OrderBounds::from(
                Money::fromString('USD', '10.00', 2),
                Money::fromString('USD', '1000.00', 2),
            ),
            ExchangeRate::fromString('USD', 'GBP', '0.80', 6),
            $feePolicy,
        ),
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('GBP', 'EUR'),
            OrderBounds::from(
                Money::fromString('GBP', '10.00', 2),
                Money::fromString('GBP', '800.00', 2),
            ),
            ExchangeRate::fromString('GBP', 'EUR', '1.17', 6),
            $feePolicy,
        ),
    ];

    return new OrderBook($orders);
}

function demonstrateFeePolicy(string $name, FeePolicy $feePolicy): void
{
    echo "\n";
    echo str_repeat('=', 80)."\n";
    echo "Fee Policy: {$name}\n";
    echo "Fingerprint: {$feePolicy->fingerprint()}\n";
    echo str_repeat('=', 80)."\n\n";

    $orderBook = createOrderBookWithFees($feePolicy);
    $graphBuilder = new GraphBuilder();
    $service = new PathSearchService($graphBuilder);

    $config = PathSearchConfig::builder()
        ->withSpendAmount(Money::fromString('USD', '100.00', 2))
        ->withToleranceBounds('0.00', '0.05')
        ->withHopLimits(1, 3)
        ->withResultLimit(3)
        ->build();

    $request = new PathSearchRequest($orderBook, $config, 'EUR');
    $resultSet = $service->findBestPaths($request);

    if (!$resultSet->hasPaths()) {
        echo "No paths found.\n";

        return;
    }

    $pathResultSet = $resultSet->paths();
    echo 'Found '.count($pathResultSet)." path(s):\n\n";

    $position = 1;
    foreach ($pathResultSet as $path) {
        $hopArray = $path->hopsAsArray();

        if (empty($hopArray)) {
            continue;
        }

        // Build route signature
        $firstHop = $hopArray[0];
        $signature = $firstHop->from();
        foreach ($hopArray as $hop) {
            $signature .= ' -> '.$hop->to();
        }

        // Calculate total fees
        $totalFees = $path->feeBreakdownAsArray();
        $feeStrings = [];
        foreach ($totalFees as $currency => $moneyObject) {
            if (!$moneyObject->isZero()) {
                $feeStrings[] = sprintf('%s %s', $moneyObject->amount(), $currency);
            }
        }

        echo "  [{$position}] Route: {$signature}\n";
        echo '      - Hops: '.count($hopArray)."\n";
        echo "      - Spent: {$path->totalSpent()->amount()} {$path->totalSpent()->currency()}\n";
        echo "      - Received: {$path->totalReceived()->amount()} {$path->totalReceived()->currency()}\n";
        echo '      - Total Fees: '.(empty($feeStrings) ? 'None' : implode(', ', $feeStrings))."\n";
        echo "\n";

        ++$position;
    }
}

// ============================================================================
// Run Demonstrations
// ============================================================================

try {
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║                       Custom Fee Policy Demo                               ║\n";
    echo "╚════════════════════════════════════════════════════════════════════════════╝\n";

    echo "\n";
    echo "This example demonstrates different fee policy implementations and how they\n";
    echo "affect path costs during search. All scenarios use the same order book but\n";
    echo "apply different fee models.\n";

    // Demo 1: No Fees (baseline)
    echo "\n";
    echo str_repeat('=', 80)."\n";
    echo "Baseline: No Fees\n";
    echo str_repeat('=', 80)."\n\n";

    $orderBookNoFees = new OrderBook([
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('USD', 'EUR'),
            OrderBounds::from(
                Money::fromString('USD', '10.00', 2),
                Money::fromString('USD', '1000.00', 2),
            ),
            ExchangeRate::fromString('USD', 'EUR', '0.92', 6),
            null, // No fee policy
        ),
    ]);

    $service = new PathSearchService(new GraphBuilder());
    $config = PathSearchConfig::builder()
        ->withSpendAmount(Money::fromString('USD', '100.00', 2))
        ->withToleranceBounds('0.00', '0.05')
        ->withHopLimits(1, 1)
        ->withResultLimit(1)
        ->build();

    $request = new PathSearchRequest($orderBookNoFees, $config, 'EUR');
    $resultSet = $service->findBestPaths($request);

    if ($resultSet->hasPaths()) {
        $path = iterator_to_array($resultSet->paths())[0];
        echo "Received: {$path->totalReceived()->amount()} {$path->totalReceived()->currency()}\n";
        echo "(This is the baseline amount without any fees)\n";
    }

    // Demo 2: Percentage Fee
    demonstrateFeePolicy(
        'Percentage Fee (0.5%)',
        new PercentageFeePolicy(rate: '0.005', scale: 6)
    );

    // Demo 3: Fixed Fee
    demonstrateFeePolicy(
        'Fixed Fee ($2.50 in EUR)',
        new FixedFeePolicy(currency: 'EUR', amount: '2.50', scale: 2)
    );

    // Demo 4: Tiered Fee
    demonstrateFeePolicy(
        'Tiered Fee (0.5% under $500, 0.3% over)',
        new TieredFeePolicy(
            currency: 'USD',
            tiers: [
                ['threshold' => '0', 'rate' => '0.005'],      // 0.5% default
                ['threshold' => '500', 'rate' => '0.003'],    // 0.3% over $500
            ],
            scale: 6
        )
    );

    // Demo 5: Maker/Taker Fee
    demonstrateFeePolicy(
        'Maker/Taker Fee (0.2% maker / 0.4% taker)',
        new MakerTakerFeePolicy(
            makerRate: '0.002',
            takerRate: '0.004',
            scale: 6
        )
    );

    // Demo 6: Combined Fee
    demonstrateFeePolicy(
        'Combined Fee (0.5% with $1.00 min, $10.00 max in EUR)',
        new CombinedFeePolicy(
            percentageRate: '0.005',
            minAmount: '1.00',
            maxAmount: '10.00',
            currency: 'EUR',
            scale: 6
        )
    );

    echo "\n";
    echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║                              Demo Complete                                 ║\n";
    echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "Key Takeaways:\n";
    echo "  1. Fees directly impact the amount received at the end of a path\n";
    echo "  2. Higher fees increase path cost (used for ranking paths)\n";
    echo "  3. Fees must match the currency of the corresponding Money object\n";
    echo "  4. Different fee models suit different business requirements\n";
    echo "  5. The fingerprint must uniquely identify the policy configuration\n";
    echo "\n";
} catch (Throwable $e) {
    fwrite(\STDERR, "\n✗ Example failed with unexpected error:\n");
    fwrite(\STDERR, '  '.$e::class.': '.$e->getMessage()."\n");
    fwrite(\STDERR, '  at '.$e->getFile().':'.$e->getLine()."\n");
    exit(1); // Failure
}

exit(0); // Success
