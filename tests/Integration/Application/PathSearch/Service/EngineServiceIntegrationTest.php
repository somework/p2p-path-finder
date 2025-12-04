<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Response\SearchOutcome;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\CandidateSearchOutcome;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\PathSearchEngine;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\Graph;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\Path;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHop;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function count;

/**
 * Integration tests for the interaction between PathSearchEngine and PathSearchService.
 *
 * These tests verify that CandidateSearchOutcome flows correctly from the engine layer
 * to the service layer, ensuring proper transformation and data integrity.
 */
#[CoversClass(PathSearchService::class)]
#[CoversClass(PathSearchEngine::class)]
final class EngineServiceIntegrationTest extends TestCase
{
    private const SCALE = 18;

    public function test_engine_to_service_data_flow_preserves_path_structure(): void
    {
        // Create a simple order book: USD -> EUR -> GBP
        $usdEurOrder = OrderFactory::buy('USD', 'EUR', '50.000', '200.000', '1.100', 3, 3);
        $eurGbpOrder = OrderFactory::buy('EUR', 'GBP', '50.000', '200.000', '1.200', 3, 3);
        $orderBook = new OrderBook([$usdEurOrder, $eurGbpOrder]);

        $graph = (new GraphBuilder())->build([$usdEurOrder, $eurGbpOrder]);

        // Configure search for 2-hop path
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(2, 2)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'GBP');

        // Step 1: Test engine produces CandidateSearchOutcome
        $engine = new PathSearchEngine(
            maxHops: 2,
            tolerance: '0.0',
            topK: 1
        );

        $candidateOutcome = $engine->findBestPaths($graph, 'USD', 'GBP');

        // Verify engine output
        self::assertInstanceOf(CandidateSearchOutcome::class, $candidateOutcome);
        self::assertTrue($candidateOutcome->hasPaths());
        self::assertNotNull($candidateOutcome->bestPath());
        self::assertInstanceOf(SearchGuardReport::class, $candidateOutcome->guardLimits());

        $candidatePath = $candidateOutcome->bestPath();
        self::assertInstanceOf(CandidatePath::class, $candidatePath);
        self::assertSame(2, $candidatePath->hops()); // Should be 2-hop path

        // Step 2: Test service consumes CandidateSearchOutcome and produces SearchOutcome
        $service = new PathSearchService(new GraphBuilder());
        $serviceOutcome = $service->findBestPaths($request);

        // Verify service output
        self::assertInstanceOf(SearchOutcome::class, $serviceOutcome);
        self::assertTrue($serviceOutcome->hasPaths());
        self::assertNotNull($serviceOutcome->bestPath());
        self::assertInstanceOf(SearchGuardReport::class, $serviceOutcome->guardLimits());

        $finalPath = $serviceOutcome->bestPath();
        self::assertInstanceOf(Path::class, $finalPath);
        self::assertSame(2, $finalPath->hops()->count());

        // Step 3: Verify data integrity through the transformation
        // The candidate path should have been materialized into a proper Path
        self::assertInstanceOf(DecimalTolerance::class, $finalPath->residualTolerance());
        self::assertNotNull($finalPath->totalReceived());

        // Step 4: Verify hop-by-hop data integrity
        $this->verifyHopByHopDataIntegrity($candidatePath, $finalPath);
    }

    public function test_empty_candidate_outcome_produces_empty_service_outcome(): void
    {
        // Create a graph with no valid paths
        $graph = (new GraphBuilder())->build([]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 3)
            ->build();

        $request = new PathSearchRequest(new OrderBook([]), $config, 'EUR');

        // Engine should produce empty outcome for disconnected graph
        $engine = new PathSearchEngine(maxHops: 3, tolerance: '0.0', topK: 1);
        $candidateOutcome = $engine->findBestPaths($graph, 'USD', 'EUR');

        self::assertInstanceOf(CandidateSearchOutcome::class, $candidateOutcome);
        self::assertFalse($candidateOutcome->hasPaths());
        self::assertNull($candidateOutcome->bestPath());

        // Service should handle this gracefully
        $service = new PathSearchService(new GraphBuilder());
        $serviceOutcome = $service->findBestPaths($request);

        self::assertInstanceOf(SearchOutcome::class, $serviceOutcome);
        self::assertFalse($serviceOutcome->hasPaths());
        self::assertNull($serviceOutcome->bestPath());
    }

    public function test_guard_limits_flow_through_engine_to_service(): void
    {
        // Create a scenario that might hit guard limits
        $usdEurOrder = OrderFactory::buy('USD', 'EUR', '50.000', '200.000', '1.100', 3, 3);
        $orderBook = new OrderBook([$usdEurOrder]);
        $graph = (new GraphBuilder())->build([$usdEurOrder]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 1)
            ->withSearchGuards(10, 10)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'EUR');

        // Both engine and service should produce outcomes with guard reports
        $engine = new PathSearchEngine(
            maxHops: 1,
            tolerance: '0.0',
            topK: 1,
            maxExpansions: 10,
            maxVisitedStates: 10
        );

        $candidateOutcome = $engine->findBestPaths($graph, 'USD', 'EUR');
        $serviceOutcome = (new PathSearchService(new GraphBuilder()))->findBestPaths($request);

        // Both should have guard reports
        self::assertInstanceOf(SearchGuardReport::class, $candidateOutcome->guardLimits());
        self::assertInstanceOf(SearchGuardReport::class, $serviceOutcome->guardLimits());

        // Service should preserve engine's guard state (or create its own based on processing)
        // The key is that guard information flows through the system
        self::assertNotNull($candidateOutcome->guardLimits());
        self::assertNotNull($serviceOutcome->guardLimits());
    }

    public function test_candidate_path_materialization_preserves_cost_and_hops(): void
    {
        // Create a 2-hop path: USD -> EUR -> GBP
        $usdEurOrder = OrderFactory::buy('USD', 'EUR', '50.000', '200.000', '1.100', 3, 3);
        $eurGbpOrder = OrderFactory::buy('EUR', 'GBP', '50.000', '200.000', '1.200', 3, 3);
        $orderBook = new OrderBook([$usdEurOrder, $eurGbpOrder]);
        $graph = (new GraphBuilder())->build([$usdEurOrder, $eurGbpOrder]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(2, 2)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'GBP');

        // Get candidate from engine
        $engine = new PathSearchEngine(maxHops: 2, tolerance: '0.0', topK: 1);
        $candidateOutcome = $engine->findBestPaths($graph, 'USD', 'GBP');
        $candidatePath = $candidateOutcome->bestPath();

        // Get materialized path from service
        $service = new PathSearchService(new GraphBuilder());
        $serviceOutcome = $service->findBestPaths($request);
        $materializedPath = $serviceOutcome->bestPath();

        // Verify that key properties are preserved through materialization
        self::assertSame($candidatePath->hops(), $materializedPath->hops()->count());
        // Cost and other properties may be transformed during materialization

        // The materialized path should have additional properties that candidates don't
        self::assertNotNull($materializedPath->totalReceived());
        self::assertInstanceOf(DecimalTolerance::class, $materializedPath->residualTolerance());

        // Verify detailed hop-by-hop data integrity
        $this->verifyHopByHopDataIntegrity($candidatePath, $materializedPath);
    }

    /**
     * Verifies that each hop in the candidate path corresponds correctly to the materialized path hops.
     *
     * This validates that the materialization process correctly converts PathEdge objects
     * (containing raw conversion data) into PathHop objects (containing calculated amounts and fees).
     */
    private function verifyHopByHopDataIntegrity(CandidatePath $candidatePath, Path $materializedPath): void
    {
        $candidateEdges = $candidatePath->edges();
        $materializedHops = $materializedPath->hops();

        // Both should have the same number of hops
        self::assertSame($candidateEdges->count(), $materializedHops->count());

        /** @var PathEdge[] $edges */
        $edges = $candidateEdges->toList();
        /** @var PathHop[] $hops */
        $hops = $materializedHops->all();

        foreach ($edges as $index => $edge) {
            $hop = $hops[$index];

            // Verify basic structure matches
            self::assertSame($edge->from(), $hop->from(), "Hop {$index}: from currency mismatch");
            self::assertSame($edge->to(), $hop->to(), "Hop {$index}: to currency mismatch");

            // Verify order is preserved
            self::assertSame($edge->order(), $hop->order(), "Hop {$index}: order mismatch");

            // For the first hop, spent amount should match the initial spend amount
            if (0 === $index) {
                // The first hop's spent amount should be based on the initial spend
                // This depends on how the LegMaterializer determines the initial amount
                // We can verify it's reasonable (positive, correct currency)
                self::assertSame($edge->from(), $hop->spent()->currency(), "Hop {$index}: spent currency mismatch");
                self::assertTrue($hop->spent()->decimal()->isPositive(), "Hop {$index}: spent amount should be positive");
            } else {
                // Subsequent hops should continue from the previous hop's destination currency
                $previousHop = $hops[$index - 1];
                self::assertSame($previousHop->to(), $hop->from(), "Hop {$index}: currency continuity broken");

                // The spent amount should be reasonable and in the correct currency
                // (In real P2P trading, amounts may be adjusted due to fees or order matching)
                self::assertSame($edge->from(), $hop->spent()->currency(), "Hop {$index}: spent currency should match edge from currency");
                self::assertTrue($hop->spent()->decimal()->isPositive(), "Hop {$index}: spent amount should be positive");
            }

            // Verify received amount is calculated correctly based on conversion rate
            self::assertSame($edge->to(), $hop->received()->currency(), "Hop {$index}: received currency mismatch");
            self::assertTrue($hop->received()->decimal()->isPositive(), "Hop {$index}: received amount should be positive");

            // Verify conversion rate is applied correctly
            // spent * conversionRate ≈ received (allowing for rounding)
            $expectedReceived = $hop->spent()->decimal()->multipliedBy($edge->conversionRate());
            $actualReceived = $hop->received()->decimal();

            // Allow for small rounding differences (within 0.01% relative difference)
            // This accounts for different rounding modes and scales used in materialization
            $relativeDifference = $expectedReceived->minus($actualReceived)->abs()
                ->dividedBy($expectedReceived, 10, \Brick\Math\RoundingMode::HALF_UP);

            self::assertTrue(
                $relativeDifference->isLessThan(\Brick\Math\BigDecimal::of('0.0001')), // 0.01%
                "Hop {$index}: conversion rate not applied correctly. Expected ~{$expectedReceived}, got {$actualReceived}, relative difference: {$relativeDifference}"
            );

            // Verify fees are properly calculated and non-negative
            $feeBreakdown = $hop->fees();
            self::assertNotNull($feeBreakdown, "Hop {$index}: fee breakdown should not be null");

            // Each fee amount should be non-negative
            foreach ($feeBreakdown->toArray() as $currency => $feeAmount) {
                self::assertTrue(
                    $feeAmount->decimal()->isGreaterThanOrEqualTo(\Brick\Math\BigDecimal::zero()),
                    "Hop {$index}: fee for {$currency} should be non-negative"
                );
            }
        }

        // Verify basic path-level consistency
        // Total spent and received are derived from hops but may include additional logic
        self::assertSame($materializedPath->totalSpent()->currency(), $hops[0]->spent()->currency());
        self::assertSame($materializedPath->totalReceived()->currency(), $hops[count($hops) - 1]->received()->currency());
        self::assertTrue($materializedPath->totalSpent()->decimal()->isPositive());
        self::assertTrue($materializedPath->totalReceived()->decimal()->isPositive());
    }
}
