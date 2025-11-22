<?php

declare(strict_types=1);

/**
 * Example: Comprehensive Error Handling Patterns
 *
 * This example demonstrates best practices for handling errors when using the
 * P2P Path Finder library. It covers:
 * - All exception types and when they occur
 * - Guard limit handling (metadata vs exception modes)
 * - Empty result handling (not an error)
 * - Input validation error handling
 * - Recovery strategies for production systems
 *
 * Run: php examples/error-handling.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Application\Service\PathSearchRequest;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;
use SomeWork\P2PPathFinder\Domain\ValueObject\ToleranceWindow;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Exception\InfeasiblePath;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                     Error Handling Patterns Example                        ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// ============================================================================
// Scenario 1: InvalidInput - Domain Object Construction
// ============================================================================

echo str_repeat('=', 80) . "\n";
echo "Scenario 1: InvalidInput - Domain Object Construction\n";
echo str_repeat('=', 80) . "\n\n";

echo "InvalidInput is thrown when domain invariants are violated during object\n";
echo "construction. This represents programmer errors that should be caught during\n";
echo "development and testing.\n\n";

// Example 1a: Invalid currency code
echo "Example 1a: Invalid currency code (too short)\n";
echo "Attempting: Money::fromString('US', '100.00', 2)\n";
try {
    $invalid = Money::fromString('US', '100.00', 2); // Currency must be 3-12 letters
    echo "✗ Unexpectedly succeeded\n";
} catch (InvalidInput $e) {
    echo "✓ Caught InvalidInput: {$e->getMessage()}\n";
}
echo "\n";

// Example 1b: Negative money amount
echo "Example 1b: Negative money amount\n";
echo "Attempting: Money::fromString('USD', '-100.00', 2)\n";
try {
    $negative = Money::fromString('USD', '-100.00', 2); // Amount must be >= 0
    echo "✗ Unexpectedly succeeded\n";
} catch (InvalidInput $e) {
    echo "✓ Caught InvalidInput: {$e->getMessage()}\n";
}
echo "\n";

// Example 1c: Invalid tolerance window
echo "Example 1c: Invalid tolerance window (min > max)\n";
echo "Attempting: ToleranceWindow::fromScalars('0.10', '0.05', 2)\n";
try {
    $invalidTolerance = ToleranceWindow::fromScalars('0.10', '0.05', 2); // min must be <= max
    echo "✗ Unexpectedly succeeded\n";
} catch (InvalidInput $e) {
    echo "✓ Caught InvalidInput: {$e->getMessage()}\n";
}
echo "\n";

// Example 1d: Invalid order bounds
echo "Example 1d: Invalid order bounds (min > max)\n";
try {
    $invalidBounds = OrderBounds::from(
        Money::fromString('USD', '1000.00', 2), // min
        Money::fromString('USD', '100.00', 2)   // max (smaller than min!)
    );
    echo "✗ Unexpectedly succeeded\n";
} catch (InvalidInput $e) {
    echo "✓ Caught InvalidInput: {$e->getMessage()}\n";
}
echo "\n";

// Best Practice: Validate user input early
echo "✓ Best Practice: Validate user input early before constructing domain objects\n\n";

// ============================================================================
// Scenario 2: InvalidInput - Configuration Errors
// ============================================================================

echo str_repeat('=', 80) . "\n";
echo "Scenario 2: InvalidInput - Configuration Errors\n";
echo str_repeat('=', 80) . "\n\n";

echo "InvalidInput is also thrown for invalid configuration values during\n";
echo "PathSearchConfig construction.\n\n";

// Example 2a: Invalid hop limits
echo "Example 2a: Invalid hop limits (min > max)\n";
try {
    $config = PathSearchConfig::builder()
        ->withSpendAmount(Money::fromString('USD', '100.00', 2))
        ->withToleranceBounds('0.00', '0.05')
        ->withHopLimits(5, 3) // min > max!
        ->build();
    echo "✗ Unexpectedly succeeded\n";
} catch (InvalidInput $e) {
    echo "✓ Caught InvalidInput: {$e->getMessage()}\n";
}
echo "\n";

// Example 2b: Empty target asset
echo "Example 2b: Empty target asset identifier\n";
$validOrderBook = new OrderBook([
    new Order(
        OrderSide::BUY,
        AssetPair::fromString('USD', 'EUR'),
        OrderBounds::from(
            Money::fromString('USD', '10.00', 2),
            Money::fromString('USD', '1000.00', 2)
        ),
        ExchangeRate::fromString('USD', 'EUR', '0.92', 6)
    ),
]);

$validConfig = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds('0.00', '0.05')
    ->withHopLimits(1, 3)
    ->build();

try {
    $request = new PathSearchRequest($validOrderBook, $validConfig, ''); // Empty target!
    $service = new PathFinderService(new GraphBuilder());
    $outcome = $service->findBestPaths($request);
    echo "✗ Unexpectedly succeeded\n";
} catch (InvalidInput $e) {
    echo "✓ Caught InvalidInput: {$e->getMessage()}\n";
}
echo "\n";

// ============================================================================
// Scenario 3: GuardLimitExceeded - Resource Exhaustion
// ============================================================================

echo str_repeat('=', 80) . "\n";
echo "Scenario 3: GuardLimitExceeded - Resource Exhaustion (Exception Mode)\n";
echo str_repeat('=', 80) . "\n\n";

echo "GuardLimitExceeded is thrown when search guard limits are hit AND the\n";
echo "config is set to throw exceptions on guard breaches.\n\n";

// Create a complex order book that will hit guard limits
$complexOrders = [];
for ($i = 0; $i < 50; $i++) {
    $baseCurrency = 'C' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $quoteCurrency = 'C' . str_pad((string) (($i + 1) % 50), 2, '0', STR_PAD_LEFT);
    
    $complexOrders[] = new Order(
        OrderSide::BUY,
        AssetPair::fromString($baseCurrency, $quoteCurrency),
        OrderBounds::from(
            Money::fromString($baseCurrency, '10.00', 2),
            Money::fromString($baseCurrency, '1000.00', 2)
        ),
        ExchangeRate::fromString($baseCurrency, $quoteCurrency, '1.00', 6)
    );
}

$complexOrderBook = new OrderBook($complexOrders);

// Configure with VERY tight guard limits and exception mode
$tightConfig = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('C00', '100.00', 2))
    ->withToleranceBounds('0.00', '0.10')
    ->withHopLimits(1, 10)
    ->withSearchGuards(100, 200) // Very low limits!
    ->withGuardLimitException() // Throw exception on breach
    ->build();

echo "Attempting search with tight guard limits (100 states, 200 expansions)...\n";
try {
    $service = new PathFinderService(new GraphBuilder());
    $request = new PathSearchRequest($complexOrderBook, $tightConfig, 'C10');
    $outcome = $service->findBestPaths($request);
    echo "✗ Search completed without hitting guards (increase complexity or lower limits)\n";
} catch (GuardLimitExceeded $e) {
    echo "✓ Caught GuardLimitExceeded: {$e->getMessage()}\n";
    echo "  This indicates the search was aborted to prevent resource exhaustion.\n";
}
echo "\n";

// Best Practice: Handle gracefully in production
echo "✓ Best Practice: Catch GuardLimitExceeded and handle gracefully\n";
echo "  - Log the event for monitoring\n";
echo "  - Return a user-friendly error message\n";
echo "  - Consider increasing limits or pre-filtering orders\n\n";

// ============================================================================
// Scenario 4: Guard Limits Hit (Metadata Mode - NOT an Exception)
// ============================================================================

echo str_repeat('=', 80) . "\n";
echo "Scenario 4: Guard Limits Hit (Metadata Mode - Partial Results)\n";
echo str_repeat('=', 80) . "\n\n";

echo "When guard limits are hit in metadata mode (default), NO exception is thrown.\n";
echo "Instead, the search returns partial results with guard limit metrics.\n\n";

// Same tight config but WITHOUT exception mode (default behavior)
$metadataConfig = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('C00', '100.00', 2))
    ->withToleranceBounds('0.00', '0.10')
    ->withHopLimits(1, 10)
    ->withSearchGuards(100, 200) // Very low limits
    // No withGuardLimitException() call = metadata mode (default)
    ->build();

echo "Running search with guard limits in metadata mode...\n";
$service = new PathFinderService(new GraphBuilder());
$request = new PathSearchRequest($complexOrderBook, $metadataConfig, 'C10');
$outcome = $service->findBestPaths($request);

echo "✓ Search completed (no exception thrown)\n";
echo "  Paths found: " . ($outcome->hasPaths() ? count($outcome->paths()) : 0) . "\n";

$guardReport = $outcome->guardLimits();
echo "  Guard metrics:\n";
echo "    - Expansions: {$guardReport->expansions()} / {$guardReport->expansionLimit()}\n";
echo "    - Visited states: {$guardReport->visitedStates()} / {$guardReport->visitedStateLimit()}\n";
echo "    - Any limit reached: " . ($guardReport->anyLimitReached() ? 'YES' : 'NO') . "\n";

if ($guardReport->anyLimitReached()) {
    echo "\n";
    echo "  ⚠ Guard limits were hit - results may be incomplete!\n";
    echo "  Consider:\n";
    echo "    1. Increasing guard limits\n";
    echo "    2. Pre-filtering the order book\n";
    echo "    3. Reducing hop limits\n";
}
echo "\n";

// Best Practice for production
echo "✓ Best Practice: Always check guard report in production\n";
echo "  - Monitor guard breach frequency\n";
echo "  - Alert when breaches exceed threshold (e.g., > 10%)\n";
echo "  - Tune limits based on actual usage patterns\n\n";

// ============================================================================
// Scenario 5: Empty Results (NOT an Error)
// ============================================================================

echo str_repeat('=', 80) . "\n";
echo "Scenario 5: Empty Results - Valid Business Outcome\n";
echo str_repeat('=', 80) . "\n\n";

echo "When no paths are found, this is NOT an error - it's a valid business outcome.\n";
echo "The library returns an empty SearchOutcome instead of throwing an exception.\n\n";

// Create order book with no path to target
$disconnectedOrders = [
    new Order(
        OrderSide::BUY,
        AssetPair::fromString('USD', 'EUR'),
        OrderBounds::from(
            Money::fromString('USD', '10.00', 2),
            Money::fromString('USD', '1000.00', 2)
        ),
        ExchangeRate::fromString('USD', 'EUR', '0.92', 6)
    ),
    // No orders connecting EUR to GBP!
];

$disconnectedBook = new OrderBook($disconnectedOrders);

$searchConfig = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds('0.00', '0.05')
    ->withHopLimits(1, 3)
    ->build();

echo "Searching for USD -> GBP path (no such path exists)...\n";
$service = new PathFinderService(new GraphBuilder());
$request = new PathSearchRequest($disconnectedBook, $searchConfig, 'GBP');
$outcome = $service->findBestPaths($request);

echo "✓ Search completed successfully (no exception)\n";
echo "  Paths found: " . ($outcome->hasPaths() ? 'YES' : 'NO') . "\n";

if (!$outcome->hasPaths()) {
    echo "  No paths to target currency - this is a valid business outcome.\n";
    echo "\n";
    echo "  Possible reasons:\n";
    echo "    1. No liquidity available for the target currency\n";
    echo "    2. Spend amount outside all order bounds\n";
    echo "    3. Tolerance window too narrow\n";
    echo "    4. Hop limit too restrictive\n";
}
echo "\n";

// Best Practice
echo "✓ Best Practice: Check hasPaths() before accessing results\n";
echo "  - Display user-friendly message when no paths found\n";
echo "  - Don't treat empty results as an application error\n";
echo "  - Consider suggesting alternative search parameters\n\n";

// ============================================================================
// Scenario 6: PrecisionViolation (Rare)
// ============================================================================

echo str_repeat('=', 80) . "\n";
echo "Scenario 6: PrecisionViolation - Arithmetic Precision Loss\n";
echo str_repeat('=', 80) . "\n\n";

echo "PrecisionViolation is thrown when arbitrary precision arithmetic operations\n";
echo "cannot maintain required precision guarantees.\n\n";

echo "This is EXTREMELY RARE in practice because:\n";
echo "  - The library uses BigDecimal for all financial arithmetic\n";
echo "  - Scale is capped at 30 decimals (more than sufficient)\n";
echo "  - All operations are designed to preserve precision\n\n";

echo "Example scenario that COULD trigger PrecisionViolation:\n";
echo "  - Extremely large scale values (> 30)\n";
echo "  - Division operations that produce non-terminating decimals\n";
echo "  - Overflow in internal cost calculations\n\n";

try {
    // Attempting to create Money with scale > 30 (maximum allowed)
    $tooMuchPrecision = Money::fromString('USD', '100.123456789012345678901234567890123', 35);
    echo "✗ Unexpectedly succeeded\n";
} catch (PrecisionViolation $e) {
    echo "✓ Caught PrecisionViolation: {$e->getMessage()}\n";
}
echo "\n";

echo "✓ Best Practice: Stick to reasonable scales\n";
echo "  - Fiat currencies: scale 2 (cents)\n";
echo "  - Cryptocurrencies: scale 8-18 (satoshis, wei)\n";
echo "  - Exchange rates: scale 6-12\n";
echo "  - Never exceed scale 30\n\n";

// ============================================================================
// Scenario 7: Production Error Handling Pattern
// ============================================================================

echo str_repeat('=', 80) . "\n";
echo "Scenario 7: Complete Production Error Handling Pattern\n";
echo str_repeat('=', 80) . "\n\n";

echo "This demonstrates a complete, production-ready error handling pattern\n";
echo "that covers all exception types and empty results.\n\n";

function findBestPathsWithErrorHandling(
    OrderBook $orderBook,
    PathSearchConfig $config,
    string $targetAsset
): void {
    $service = new PathFinderService(new GraphBuilder());
    
    try {
        $request = new PathSearchRequest($orderBook, $config, $targetAsset);
        $outcome = $service->findBestPaths($request);
        
        // Check for empty results (NOT an error)
        if (!$outcome->hasPaths()) {
            echo "ℹ No paths found to {$targetAsset}\n";
            echo "  - Check if liquidity is available\n";
            echo "  - Try widening tolerance window\n";
            echo "  - Try increasing hop limits\n";
            return;
        }
        
        // Check guard limits (warning, not error)
        $guardReport = $outcome->guardLimits();
        if ($guardReport->anyLimitReached()) {
            echo "⚠ Warning: Search guard limits were hit\n";
            echo "  - Expansions: {$guardReport->expansions()} / {$guardReport->expansionLimit()}\n";
            echo "  - States: {$guardReport->visitedStates()} / {$guardReport->visitedStateLimit()}\n";
            echo "  - Results may be incomplete\n";
            // In production: Log this for monitoring
        }
        
        // Process results
        $paths = $outcome->paths();
        echo "✓ Found " . count($paths) . " path(s)\n";
        
        foreach ($paths as $idx => $path) {
            $num = $idx + 1;
            echo "  Path #{$num}: {$path->totalSpent()->amount()} {$path->totalSpent()->currency()} " .
                 "→ {$path->totalReceived()->amount()} {$path->totalReceived()->currency()}\n";
        }
        
    } catch (InvalidInput $e) {
        // Programmer error - should not happen in production if inputs are validated
        echo "✗ Invalid Input Error: {$e->getMessage()}\n";
        echo "  Action: Validate user input before constructing domain objects\n";
        // In production: Log as ERROR, return 400 Bad Request
        
    } catch (GuardLimitExceeded $e) {
        // Resource exhaustion - recoverable
        echo "✗ Guard Limit Exceeded: {$e->getMessage()}\n";
        echo "  Action: Increase guard limits or pre-filter order book\n";
        // In production: Log as WARNING, return 503 Service Unavailable or partial results
        
    } catch (PrecisionViolation $e) {
        // Arithmetic precision loss - very rare
        echo "✗ Precision Violation: {$e->getMessage()}\n";
        echo "  Action: Check scale values and input data\n";
        // In production: Log as ERROR, return 500 Internal Server Error
        
    } catch (InfeasiblePath $e) {
        // Path constraints cannot be satisfied - business logic error
        echo "✗ Infeasible Path: {$e->getMessage()}\n";
        echo "  Action: Relax search constraints\n";
        // In production: Log as INFO, return empty results with explanation
        
    } catch (\Exception $e) {
        // Unexpected error - catch-all
        echo "✗ Unexpected Error: {$e->getMessage()}\n";
        echo "  Type: " . get_class($e) . "\n";
        // In production: Log as CRITICAL, return 500 Internal Server Error
    }
}

echo "Example: Production error handling with valid inputs\n";
findBestPathsWithErrorHandling(
    $validOrderBook,
    $validConfig,
    'EUR'
);

echo "\n";

// ============================================================================
// Summary and Best Practices
// ============================================================================

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                        Error Handling Summary                              ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

echo "Exception Hierarchy:\n";
echo "  ExceptionInterface (marker interface)\n";
echo "    ├─ InvalidInput         - Domain invariant violations, config errors\n";
echo "    ├─ GuardLimitExceeded   - Search resource exhaustion\n";
echo "    ├─ PrecisionViolation   - Arithmetic precision loss (very rare)\n";
echo "    └─ InfeasiblePath       - Path constraints cannot be satisfied (reserved)\n";
echo "\n";

echo "When to Use Each Exception:\n";
echo "  • InvalidInput: Catch during input validation, return 400 Bad Request\n";
echo "  • GuardLimitExceeded: Catch for monitoring, consider 503 or partial results\n";
echo "  • PrecisionViolation: Log as critical error, return 500\n";
echo "  • InfeasiblePath: Log as info, return empty results with explanation\n";
echo "\n";

echo "Not-an-Error Scenarios:\n";
echo "  • Empty results: Valid business outcome, check hasPaths()\n";
echo "  • Guard limits hit (metadata mode): Check guardReport.anyLimitReached()\n";
echo "\n";

echo "Production Checklist:\n";
echo "  ✓ Validate user input before constructing domain objects\n";
echo "  ✓ Use try-catch blocks for all PathFinderService calls\n";
echo "  ✓ Always check outcome.hasPaths() before accessing results\n";
echo "  ✓ Always check outcome.guardLimits() for limit breaches\n";
echo "  ✓ Log all exceptions with context for monitoring\n";
echo "  ✓ Return user-friendly error messages (don't expose internals)\n";
echo "  ✓ Monitor guard breach rates to tune limits\n";
echo "  ✓ Have fallback behavior for GuardLimitExceeded\n";
echo "\n";

echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                          Example Complete                                  ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

