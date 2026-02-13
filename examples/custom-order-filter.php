<?php

declare(strict_types=1);

/**
 * Example: Custom Order Filter Implementation.
 *
 * This example demonstrates how to implement custom OrderFilterInterface filters
 * to pre-filter orders before path finding. It shows best practices including:
 * - Implementing the OrderFilterInterface contract
 * - Creating focused, single-responsibility filters
 * - Composing multiple filters together
 * - Proper scale and currency handling
 * - Performance-conscious O(1) evaluation
 */

require __DIR__.'/../vendor/autoload.php';

use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Domain\Money\AssetPair;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Filter\OrderFilterInterface;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\OrderBounds;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;

// ============================================================================
// EXAMPLE 1: Liquidity Depth Filter
// ============================================================================

/**
 * Filters orders by their liquidity depth (max order size).
 *
 * This filter demonstrates:
 * - Simple, focused filter logic (single responsibility)
 * - Proper currency and scale handling
 * - O(1) evaluation time
 * - Immutable state after construction
 */
final class LiquidityDepthFilter implements OrderFilterInterface
{
    /**
     * @param Money $minimumDepth Minimum maximum order size required (in base asset)
     */
    public function __construct(private readonly Money $minimumDepth)
    {
    }

    /**
     * Accepts orders whose maximum fill amount meets or exceeds the threshold.
     *
     * Best Practice: Early return when currencies don't match to avoid
     * unnecessary scale normalization.
     */
    public function accepts(Order $order): bool
    {
        $orderMax = $order->bounds()->max();

        // Early return: Currency mismatch means we can't compare
        if ($orderMax->currency() !== $this->minimumDepth->currency()) {
            return true; // Don't filter out - different asset
        }

        // Normalize scales for accurate comparison
        $scale = max($orderMax->scale(), $this->minimumDepth->scale());
        $normalizedMax = $orderMax->withScale($scale);
        $normalizedThreshold = $this->minimumDepth->withScale($scale);

        // Accept if order maximum meets or exceeds our threshold
        return !$normalizedMax->lessThan($normalizedThreshold);
    }
}

// ============================================================================
// EXAMPLE 2: Fee-Free Orders Filter
// ============================================================================

/**
 * Filters orders to only accept those without fee policies.
 *
 * This filter demonstrates:
 * - Accessing order metadata (fee policy)
 * - Simple boolean logic
 * - Use case: Preferring direct peer-to-peer orders without intermediaries
 */
final class FeeFreeOrdersFilter implements OrderFilterInterface
{
    /**
     * Accepts only orders that have no fee policy attached.
     */
    public function accepts(Order $order): bool
    {
        // Simple check: null fee policy means no fees
        return null === $order->feePolicy();
    }
}

// ============================================================================
// EXAMPLE 3: Exchange Rate Range Filter
// ============================================================================

/**
 * Filters orders by their exchange rate staying within acceptable bounds.
 *
 * This filter demonstrates:
 * - Working with exchange rates
 * - Range validation
 * - Decimal string comparison for precision
 * - Asset pair awareness
 */
final class ExchangeRateRangeFilter implements OrderFilterInterface
{
    /**
     * @param AssetPair $pair    The asset pair to filter
     * @param string    $minRate Minimum acceptable rate (numeric-string)
     * @param string    $maxRate Maximum acceptable rate (numeric-string)
     */
    public function __construct(
        private readonly AssetPair $pair,
        private readonly string $minRate,
        private readonly string $maxRate,
    ) {
    }

    /**
     * Accepts orders whose exchange rate falls within the configured range.
     */
    public function accepts(Order $order): bool
    {
        $orderPair = $order->assetPair();

        // Early return: Only filter orders for our target pair
        if ($orderPair->base() !== $this->pair->base()
            || $orderPair->quote() !== $this->pair->quote()) {
            return true; // Different pair, don't filter
        }

        $rate = $order->effectiveRate();
        $rateValue = $rate->rate();

        // Use bccomp for precise decimal comparison
        // bccomp returns: -1 if left < right, 0 if equal, 1 if left > right
        $aboveMin = bccomp($rateValue, $this->minRate, 18) >= 0;
        $belowMax = bccomp($rateValue, $this->maxRate, 18) <= 0;

        return $aboveMin && $belowMax;
    }
}

// ============================================================================
// EXAMPLE 4: Composite Filter (Advanced)
// ============================================================================

/**
 * Combines multiple filters with AND logic.
 *
 * This demonstrates filter composition - a powerful pattern for building
 * complex filtering logic from simple, reusable components.
 *
 * Best Practice: Prefer composition over creating one monolithic filter.
 */
final class CompositeAndFilter implements OrderFilterInterface
{
    /** @var list<OrderFilterInterface> */
    private readonly array $filters;

    /**
     * @param OrderFilterInterface ...$filters Filters to combine (all must pass)
     */
    public function __construct(OrderFilterInterface ...$filters)
    {
        $this->filters = $filters;
    }

    /**
     * Accepts order only if ALL composed filters accept it.
     *
     * Performance: Early exit on first rejection for efficiency.
     */
    public function accepts(Order $order): bool
    {
        foreach ($this->filters as $filter) {
            if (!$filter->accepts($order)) {
                return false; // Early exit on first rejection
            }
        }

        return true;
    }
}

// ============================================================================
// DEMONSTRATION: Using Custom Filters
// ============================================================================

echo "=== Custom Order Filter Example ===\n\n";

// Create a simple fee policy for demonstration
$percentageFee = new class implements FeePolicy {
    public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
    {
        $fee = $quoteAmount->multiply('0.001', $quoteAmount->scale());

        return FeeBreakdown::forQuote($fee);
    }

    public function fingerprint(): string
    {
        return 'percentage-fee:0.001';
    }
};

// Build order book with diverse orders
$orders = [
    // USD -> USDT: Fee-free, large liquidity
    new Order(
        OrderSide::BUY,
        AssetPair::fromString('USD', 'USDT'),
        OrderBounds::from(
            Money::fromString('USD', '10.00', 2),
            Money::fromString('USD', '5000.00', 2), // High liquidity
        ),
        ExchangeRate::fromString('USD', 'USDT', '1.0001', 6),
        null, // No fee policy
    ),

    // USD -> USDT: With fees, small liquidity
    new Order(
        OrderSide::BUY,
        AssetPair::fromString('USD', 'USDT'),
        OrderBounds::from(
            Money::fromString('USD', '10.00', 2),
            Money::fromString('USD', '100.00', 2), // Low liquidity
        ),
        ExchangeRate::fromString('USD', 'USDT', '1.0002', 6),
        $percentageFee, // Has fees
    ),

    // USDT -> EUR: Fee-free, medium liquidity, good rate
    new Order(
        OrderSide::BUY,
        AssetPair::fromString('USDT', 'EUR'),
        OrderBounds::from(
            Money::fromString('USDT', '50.00', 2),
            Money::fromString('USDT', '1000.00', 2), // Medium liquidity
        ),
        ExchangeRate::fromString('USDT', 'EUR', '0.92', 6),
        null,
    ),

    // USDT -> EUR: Fee-free, high liquidity, poor rate
    new Order(
        OrderSide::BUY,
        AssetPair::fromString('USDT', 'EUR'),
        OrderBounds::from(
            Money::fromString('USDT', '100.00', 2),
            Money::fromString('USDT', '8000.00', 2), // High liquidity
        ),
        ExchangeRate::fromString('USDT', 'EUR', '0.85', 6), // Poor rate
        null,
    ),
];

// Create unfiltered order book
$unfilteredBook = new OrderBook($orders);

echo 'Initial order book: '.count($orders)." orders\n\n";

// ============================================================================
// SCENARIO 1: Filter by liquidity depth only
// ============================================================================

echo "--- Scenario 1: Minimum Liquidity Filter ---\n";
echo "Filtering for orders with at least 500 USD/USDT liquidity...\n\n";

$liquidityFilter = new LiquidityDepthFilter(
    Money::fromString('USD', '500.00', 2)
);

$filteredOrders1 = iterator_to_array($unfilteredBook->filter($liquidityFilter));
echo 'Orders passing liquidity filter: '.count($filteredOrders1)."\n";
echo "  (Excludes order with max 100 USD)\n\n";

// ============================================================================
// SCENARIO 2: Filter by fee-free orders only
// ============================================================================

echo "--- Scenario 2: Fee-Free Orders Filter ---\n";
echo "Filtering for orders without fee policies...\n\n";

$feeFreeFilter = new FeeFreeOrdersFilter();

$filteredOrders2 = iterator_to_array($unfilteredBook->filter($feeFreeFilter));
echo 'Fee-free orders: '.count($filteredOrders2)."\n";
echo "  (Excludes 1 order with 0.1% fee)\n\n";

// ============================================================================
// SCENARIO 3: Filter by exchange rate range
// ============================================================================

echo "--- Scenario 3: Exchange Rate Range Filter ---\n";
echo "Filtering USDT->EUR orders for rates between 0.90 and 0.95...\n\n";

$rateFilter = new ExchangeRateRangeFilter(
    AssetPair::fromString('USDT', 'EUR'),
    '0.90', // Minimum rate
    '0.95', // Maximum rate
);

$filteredOrders3 = iterator_to_array($unfilteredBook->filter($rateFilter));
echo 'Orders in rate range: '.count($filteredOrders3)."\n";
echo "  (Keeps 0.92 rate, excludes 0.85 rate)\n\n";

// ============================================================================
// SCENARIO 4: Composite filter (AND logic)
// ============================================================================

echo "--- Scenario 4: Composite Filter (Multiple Criteria) ---\n";
echo "Combining: fee-free AND high liquidity AND good rates...\n\n";

$compositeFilter = new CompositeAndFilter(
    $feeFreeFilter,
    $liquidityFilter,
    $rateFilter,
);

$filteredOrders4 = iterator_to_array($unfilteredBook->filter($compositeFilter));
echo 'Orders passing all filters: '.count($filteredOrders4)."\n";
echo "  (Only orders meeting ALL criteria)\n\n";

// ============================================================================
// SCENARIO 5: Using filters with ExecutionPlanService
// ============================================================================

echo "--- Scenario 5: Integration with ExecutionPlanService ---\n";
echo "Finding best execution plan using fee-free orders only...\n\n";

// Pre-filter the order book
$filteredForPathFinding = iterator_to_array($unfilteredBook->filter($feeFreeFilter));
$filteredBook = new OrderBook($filteredForPathFinding);

$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds('0.00', '0.05')
    ->withHopLimits(1, 3)
    ->build();

$service = new ExecutionPlanService(new GraphBuilder());
$request = new PathSearchRequest($filteredBook, $config, 'EUR');

try {
    $outcome = $service->findBestPlans($request);

    if ($outcome->hasPaths()) {
        $plans = $outcome->paths();
        echo 'Found '.$plans->count()." execution plan(s) using fee-free orders\n";

        foreach ($plans as $idx => $plan) {
            $num = $idx + 1;
            echo "\nPlan #{$num}:\n";
            echo "  Spent: {$plan->totalSpent()->amount()} {$plan->totalSpent()->currency()}\n";
            echo "  Received: {$plan->totalReceived()->amount()} {$plan->totalReceived()->currency()}\n";
            echo "  Steps: {$plan->stepCount()}\n";

            foreach ($plan->steps() as $stepIdx => $step) {
                $stepNum = $stepIdx + 1;
                echo "    Step #{$stepNum}: {$step->from()} -> {$step->to()}\n";
            }
        }
    } else {
        echo "No execution plans found with current filters.\n";
    }

    $guardReport = $outcome->guardLimits();
    echo "\nSearch metrics:\n";
    echo "  Expansions: {$guardReport->expansions()}\n";
    echo "  Visited states: {$guardReport->visitedStates()}\n";
} catch (Throwable $e) {
    fwrite(\STDERR, "\n✗ Example failed with unexpected error:\n");
    fwrite(\STDERR, '  '.$e::class.': '.$e->getMessage()."\n");
    fwrite(\STDERR, '  at '.$e->getFile().':'.$e->getLine()."\n");
    exit(1); // Failure
}

echo "\n=== Example Complete ===\n";
echo "\nKey Takeaways:\n";
echo "1. Filters should be simple, focused, and stateless\n";
echo "2. Always handle currency and scale normalization properly\n";
echo "3. Use early returns for performance and clarity\n";
echo "4. Compose multiple simple filters rather than one complex filter\n";
echo "5. Filters integrate seamlessly with OrderBook and ExecutionPlanService\n";
echo "6. Pre-filtering reduces graph size and improves search performance\n";

exit(0); // Success
