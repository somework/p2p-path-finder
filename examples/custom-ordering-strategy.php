<?php

declare(strict_types=1);

/**
 * Example: Custom Path Ordering Strategies
 *
 * This example demonstrates how to implement and use custom PathOrderStrategy
 * implementations to control how paths are prioritized during search.
 *
 * The PathOrderStrategy interface allows you to define custom logic for comparing
 * and ranking paths based on cost, hop count, route signature, or any custom criteria.
 *
 * Run: php examples/custom-ordering-strategy.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Brick\Math\BigDecimal;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderStrategy;
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

// ============================================================================
// Example 1: Minimize Hops First (Simplest Path)
// ============================================================================

/**
 * Custom strategy that prioritizes paths with fewer hops (simpler routes).
 *
 * This strategy is useful when:
 * - Transaction fees are proportional to route complexity
 * - Simpler paths are more reliable or easier to audit
 * - Lower latency is critical (fewer hops = faster execution)
 *
 * Ordering criteria (in priority order):
 * 1. Fewer hops (lower is better)
 * 2. Lower cost
 * 3. Route signature (lexicographic)
 * 4. Insertion order (for stability)
 */
class MinimizeHopsStrategy implements PathOrderStrategy
{
    public function __construct(private readonly int $costScale = 6)
    {
    }

    #[Override]
    public function compare(PathOrderKey $left, PathOrderKey $right): int
    {
        // Priority 1: Minimize hops (fewer hops = better path)
        $hopComparison = $left->hops() <=> $right->hops();
        if (0 !== $hopComparison) {
            return $hopComparison;
        }

        // Priority 2: Minimize cost (when hops are equal)
        $costComparison = $left->cost()->compare($right->cost(), $this->costScale);
        if (0 !== $costComparison) {
            return $costComparison;
        }

        // Priority 3: Route signature (for consistency)
        $signatureComparison = $left->routeSignature()->compare($right->routeSignature());
        if (0 !== $signatureComparison) {
            return $signatureComparison;
        }

        // Priority 4: Insertion order (ensures stable sorting)
        return $left->insertionOrder() <=> $right->insertionOrder();
    }
}

// ============================================================================
// Example 2: Weighted Scoring Strategy
// ============================================================================

/**
 * Custom strategy that uses a weighted score combining cost and hops.
 *
 * This strategy is useful when:
 * - You want to balance cost and complexity
 * - Neither cost nor hops alone is the dominant factor
 * - You need fine-tuned control over the cost/complexity tradeoff
 *
 * The score is calculated as: (normalized_cost * costWeight) + (hops * hopWeight)
 * Lower scores are better.
 */
class WeightedScoringStrategy implements PathOrderStrategy
{
    public function __construct(
        private readonly float $costWeight = 1.0,
        private readonly float $hopWeight = 0.5,
        private readonly int $costScale = 6,
    ) {
    }

    #[Override]
    public function compare(PathOrderKey $left, PathOrderKey $right): int
    {
        // Calculate weighted scores for both paths
        $leftScore = $this->calculateScore($left);
        $rightScore = $this->calculateScore($right);

        // Compare scores (lower is better)
        $scoreComparison = $leftScore <=> $rightScore;
        if (0 !== $scoreComparison) {
            return $scoreComparison;
        }

        // Tie-breaker 1: Route signature
        $signatureComparison = $left->routeSignature()->compare($right->routeSignature());
        if (0 !== $signatureComparison) {
            return $signatureComparison;
        }

        // Tie-breaker 2: Insertion order (ensures stable sorting)
        return $left->insertionOrder() <=> $right->insertionOrder();
    }

    private function calculateScore(PathOrderKey $key): float
    {
        // Normalize cost to a float for scoring
        $costValue = (float) $key->cost()->value();

        // Calculate weighted score: lower is better
        return ($costValue * $this->costWeight) + ($key->hops() * $this->hopWeight);
    }
}

// ============================================================================
// Example 3: Route Preference Strategy
// ============================================================================

/**
 * Custom strategy that prefers paths containing specific currencies.
 *
 * This strategy is useful when:
 * - Certain currencies have better liquidity or stability
 * - Regulatory requirements favor specific currencies
 * - Business relationships make certain routes more desirable
 *
 * Ordering criteria:
 * 1. Paths containing preferred currencies rank higher
 * 2. Then by cost
 * 3. Then by hops
 * 4. Then by route signature
 * 5. Finally by insertion order
 */
class RoutePreferenceStrategy implements PathOrderStrategy
{
    /**
     * @param list<string> $preferredCurrencies List of currency codes to prefer (e.g., ['USD', 'EUR'])
     */
    public function __construct(
        private readonly array $preferredCurrencies,
        private readonly int $costScale = 6,
    ) {
    }

    #[Override]
    public function compare(PathOrderKey $left, PathOrderKey $right): int
    {
        // Priority 1: Prefer paths with preferred currencies
        $leftHasPreferred = $this->hasPreferredCurrency($left);
        $rightHasPreferred = $this->hasPreferredCurrency($right);

        if ($leftHasPreferred !== $rightHasPreferred) {
            // Paths with preferred currencies rank higher (return negative to prioritize left)
            return $rightHasPreferred <=> $leftHasPreferred;
        }

        // Priority 2: Minimize cost
        $costComparison = $left->cost()->compare($right->cost(), $this->costScale);
        if (0 !== $costComparison) {
            return $costComparison;
        }

        // Priority 3: Minimize hops
        $hopComparison = $left->hops() <=> $right->hops();
        if (0 !== $hopComparison) {
            return $hopComparison;
        }

        // Priority 4: Route signature
        $signatureComparison = $left->routeSignature()->compare($right->routeSignature());
        if (0 !== $signatureComparison) {
            return $signatureComparison;
        }

        // Priority 5: Insertion order (stable sort)
        return $left->insertionOrder() <=> $right->insertionOrder();
    }

    private function hasPreferredCurrency(PathOrderKey $key): bool
    {
        $signature = $key->routeSignature()->value();

        foreach ($this->preferredCurrencies as $currency) {
            if (str_contains($signature, $currency)) {
                return true;
            }
        }

        return false;
    }
}

// ============================================================================
// Demo: Compare Default vs Custom Strategies
// ============================================================================

function createSampleOrderBook(): OrderBook
{
    // Fee policy: 0.5% percentage fee on quote amount
    $lowFeePolicy = new class implements FeePolicy {
        public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
        {
            $feeAmount = Money::fromString(
                $quoteAmount->currency(),
                bcmul($quoteAmount->amount(), '0.005', 6),
                6
            );
            return FeeBreakdown::forQuote($feeAmount);
        }

        public function fingerprint(): string
        {
            return 'quote-percentage:0.005:6';
        }
    };

    // Higher fee policy: 1% percentage fee on quote amount
    $highFeePolicy = new class implements FeePolicy {
        public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
        {
            $feeAmount = Money::fromString(
                $quoteAmount->currency(),
                bcmul($quoteAmount->amount(), '0.01', 6),
                6
            );
            return FeeBreakdown::forQuote($feeAmount);
        }

        public function fingerprint(): string
        {
            return 'quote-percentage:0.01:6';
        }
    };

    // Create a diverse set of orders with different characteristics
    $orders = [
        // Direct USD -> EUR orders (1 hop)
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('USD', 'EUR'),
            OrderBounds::from(
                Money::fromString('USD', '10.00', 2),
                Money::fromString('USD', '1000.00', 2),
            ),
            ExchangeRate::fromString('USD', 'EUR', '0.92', 6),
            $lowFeePolicy, // Low fee
        ),
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('USD', 'EUR'),
            OrderBounds::from(
                Money::fromString('USD', '10.00', 2),
                Money::fromString('USD', '1000.00', 2),
            ),
            ExchangeRate::fromString('USD', 'EUR', '0.93', 6),
            $highFeePolicy, // Higher fee, worse cost
        ),

        // USD -> GBP -> EUR path (2 hops)
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('USD', 'GBP'),
            OrderBounds::from(
                Money::fromString('USD', '10.00', 2),
                Money::fromString('USD', '1000.00', 2),
            ),
            ExchangeRate::fromString('USD', 'GBP', '0.80', 6),
            $lowFeePolicy,
        ),
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('GBP', 'EUR'),
            OrderBounds::from(
                Money::fromString('GBP', '10.00', 2),
                Money::fromString('GBP', '800.00', 2),
            ),
            ExchangeRate::fromString('GBP', 'EUR', '1.17', 6),
            $lowFeePolicy,
        ),

        // USD -> JPY -> EUR path (2 hops, different currencies)
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('USD', 'JPY'),
            OrderBounds::from(
                Money::fromString('USD', '10.00', 2),
                Money::fromString('USD', '1000.00', 2),
            ),
            ExchangeRate::fromString('USD', 'JPY', '150.00', 6),
            $lowFeePolicy,
        ),
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('JPY', 'EUR'),
            OrderBounds::from(
                Money::fromString('JPY', '1000.00', 2),
                Money::fromString('JPY', '150000.00', 2),
            ),
            ExchangeRate::fromString('JPY', 'EUR', '0.0063', 6),
            $lowFeePolicy,
        ),

        // USD -> CHF -> GBP -> EUR path (3 hops)
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('USD', 'CHF'),
            OrderBounds::from(
                Money::fromString('USD', '10.00', 2),
                Money::fromString('USD', '1000.00', 2),
            ),
            ExchangeRate::fromString('USD', 'CHF', '0.88', 6),
            $lowFeePolicy,
        ),
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('CHF', 'GBP'),
            OrderBounds::from(
                Money::fromString('CHF', '10.00', 2),
                Money::fromString('CHF', '880.00', 2),
            ),
            ExchangeRate::fromString('CHF', 'GBP', '0.91', 6),
            $lowFeePolicy,
        ),
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('GBP', 'EUR'),
            OrderBounds::from(
                Money::fromString('GBP', '10.00', 2),
                Money::fromString('GBP', '800.00', 2),
            ),
            ExchangeRate::fromString('GBP', 'EUR', '1.17', 6),
            $lowFeePolicy,
        ),
    ];

    return new OrderBook($orders);
}

function demonstrateStrategy(string $name, PathOrderStrategy $strategy): void
{
    echo "\n";
    echo str_repeat('=', 80) . "\n";
    echo "Strategy: {$name}\n";
    echo str_repeat('=', 80) . "\n\n";

    $orderBook = createSampleOrderBook();
    $graphBuilder = new GraphBuilder();
    $service = new PathFinderService($graphBuilder, $strategy);

    $config = PathSearchConfig::builder()
        ->withSpendAmount(Money::fromString('USD', '100.00', 2))
        ->withToleranceBounds('0.00', '0.05') // 5% tolerance
        ->withHopLimits(1, 5)
        ->withResultLimit(5)
        ->build();

    $request = new PathSearchRequest($orderBook, $config, 'EUR');

    $resultSet = $service->findBestPaths($request);

    if (!$resultSet->hasPaths()) {
        echo "No paths found.\n";
        return;
    }

    $pathResultSet = $resultSet->paths();
    echo "Found " . count($pathResultSet) . " path(s):\n\n";

    $position = 1;
    foreach ($pathResultSet as $path) {
        $legs = $path->legs();
        $hopCount = count($legs);
        
        if (0 === $hopCount) {
            continue;
        }

        // Build signature from legs
        $legArray = iterator_to_array($legs);
        $firstLeg = $legArray[0];
        $fullSignature = $firstLeg->from();
        foreach ($legArray as $leg) {
            $fullSignature .= ' -> ' . $leg->to();
        }
        
        $totalSpent = $path->totalSpent();
        $totalReceived = $path->totalReceived();
        $residualTolerance = $path->residualTolerancePercentage(4);

        echo "  [{$position}] Route: {$fullSignature}\n";
        echo "      - Hops: {$hopCount}\n";
        echo "      - Spent: {$totalSpent->amount()} {$totalSpent->currency()}\n";
        echo "      - Received: {$totalReceived->amount()} {$totalReceived->currency()}\n";
        echo "      - Residual Tolerance: {$residualTolerance}%\n";
        echo "\n";

        ++$position;
    }
}

// ============================================================================
// Run Demonstrations
// ============================================================================

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                    Custom Path Ordering Strategy Demo                     ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";

echo "\n";
echo "This example demonstrates how different ordering strategies affect path ranking.\n";
echo "We'll use the same order book but apply different strategies to see how the\n";
echo "results change based on different prioritization criteria.\n";

// Demo 1: Default Strategy (Cost-first)
demonstrateStrategy(
    'Default (Cost, Hops, Signature)',
    new \SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\CostHopsSignatureOrderingStrategy(6)
);

// Demo 2: Minimize Hops Strategy
demonstrateStrategy(
    'Minimize Hops First',
    new MinimizeHopsStrategy(costScale: 6)
);

// Demo 3: Weighted Scoring Strategy
demonstrateStrategy(
    'Weighted Scoring (Cost: 1.0, Hops: 0.5)',
    new WeightedScoringStrategy(costWeight: 1.0, hopWeight: 0.5, costScale: 6)
);

// Demo 4: Route Preference Strategy (prefer GBP routes)
demonstrateStrategy(
    'Route Preference (prefer GBP)',
    new RoutePreferenceStrategy(preferredCurrencies: ['GBP'], costScale: 6)
);

// ============================================================================
// Determinism Test
// ============================================================================

echo "\n";
echo str_repeat('=', 80) . "\n";
echo "Determinism Test: Running same search 3 times\n";
echo str_repeat('=', 80) . "\n\n";

$orderBook = createSampleOrderBook();
$graphBuilder = new GraphBuilder();
$strategy = new MinimizeHopsStrategy(6);
$service = new PathFinderService($graphBuilder, $strategy);

$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds('0.00', '0.05')
    ->withHopLimits(1, 5)
    ->withResultLimit(3)
    ->build();

$results = [];
for ($run = 1; $run <= 3; ++$run) {
    $request = new PathSearchRequest($orderBook, $config, 'EUR');
    $resultSet = $service->findBestPaths($request);
    $signatures = [];
    $pathResultSet = $resultSet->paths();
    foreach ($pathResultSet as $path) {
        $legs = $path->legs();
        if (count($legs) > 0) {
            $legArray = iterator_to_array($legs);
            $firstLeg = $legArray[0];
            $signature = $firstLeg->from();
            foreach ($legArray as $leg) {
                $signature .= ' -> ' . $leg->to();
            }
            $signatures[] = $signature;
        }
    }
    $results[$run] = $signatures;

    echo "Run {$run}: " . implode(', ', $signatures) . "\n";
}

// Verify all runs produced the same results
$allSame = ($results[1] === $results[2]) && ($results[2] === $results[3]);

echo "\n";
if ($allSame) {
    echo "✓ Determinism verified: All runs produced identical results\n";
} else {
    echo "✗ Determinism violation: Results differ across runs\n";
}

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                              Demo Complete                                 ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

