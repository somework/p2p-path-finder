<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service\PathFinder;

use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\PathFinderEdgeCaseFixtures;

use function sprintf;

final class PathFinderServiceEdgeCaseTest extends PathFinderServiceTestCase
{
    /**
     * @param non-empty-string $targetAsset
     *
     * @dataProvider \SomeWork\P2PPathFinder\Tests\Fixture\PathFinderEdgeCaseFixtures::unresolvedSearches
     */
    public function test_it_returns_empty_paths_without_triggering_guards(
        OrderBook $orderBook,
        Money $spendAmount,
        string $targetAsset,
    ): void {
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 6)
            ->build();

        $result = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, $targetAsset));

        self::assertFalse($result->hasPaths(), 'Edge-case fixtures should not leak partial paths.');
        self::assertSame([], $result->paths()->toArray());

        $guardLimits = $result->guardLimits();
        self::assertFalse($guardLimits->expansionsReached());
        self::assertFalse($guardLimits->visitedStatesReached());
        self::assertFalse($guardLimits->timeBudgetReached());
        self::assertFalse($guardLimits->anyLimitReached());
        self::assertSame($config->pathFinderMaxExpansions(), $guardLimits->expansionLimit());
        self::assertSame($config->pathFinderMaxVisitedStates(), $guardLimits->visitedStateLimit());
        self::assertSame($config->pathFinderTimeBudgetMs(), $guardLimits->timeBudgetLimit());
        self::assertSame(0, $guardLimits->expansions());
        self::assertSame(0, $guardLimits->visitedStates());
        self::assertSame(0.0, $guardLimits->elapsedMilliseconds());
    }

    /**
     * @param non-empty-string $targetAsset
     *
     * @dataProvider \SomeWork\P2PPathFinder\Tests\Fixture\PathFinderEdgeCaseFixtures::guardLimitedSearches
     */
    public function test_it_reports_guard_metadata_when_limits_triggered(
        OrderBook $orderBook,
        Money $spendAmount,
        string $targetAsset,
        int $expansionLimit,
        int $visitedLimit,
    ): void {
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 64)
            ->withSearchGuards($visitedLimit, $expansionLimit)
            ->build();

        $result = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, $targetAsset));

        self::assertFalse($result->hasPaths(), 'Guard breaches should never surface partial paths.');
        self::assertSame([], $result->paths()->toArray());

        $guardLimits = $result->guardLimits();
        self::assertTrue($guardLimits->expansionsReached());
        self::assertFalse($guardLimits->visitedStatesReached());
        self::assertFalse($guardLimits->timeBudgetReached());
        self::assertTrue($guardLimits->anyLimitReached());
        self::assertSame($expansionLimit, $guardLimits->expansionLimit());
        self::assertSame($visitedLimit, $guardLimits->visitedStateLimit());
        self::assertSame($expansionLimit, $guardLimits->expansions());
    }

    /**
     * @param non-empty-string $targetAsset
     *
     * @dataProvider \SomeWork\P2PPathFinder\Tests\Fixture\PathFinderEdgeCaseFixtures::guardLimitedSearches
     */
    public function test_it_throws_guard_limit_exception_when_configured(
        OrderBook $orderBook,
        Money $spendAmount,
        string $targetAsset,
        int $expansionLimit,
        int $visitedLimit,
    ): void {
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 64)
            ->withSearchGuards($visitedLimit, $expansionLimit)
            ->withGuardLimitException()
            ->build();

        $service = $this->makeService();

        $this->expectException(GuardLimitExceeded::class);

        try {
            $service->findBestPaths($this->makeRequest($orderBook, $config, $targetAsset));
        } catch (GuardLimitExceeded $exception) {
            self::assertStringContainsString('Search guard limit exceeded', $exception->getMessage());
            self::assertStringContainsString(
                sprintf('expansions %d/%d', $expansionLimit, $expansionLimit),
                $exception->getMessage(),
            );

            throw $exception;
        }
    }

    public function test_guard_limited_chain_resolves_when_limits_relaxed(): void
    {
        $orderBook = PathFinderEdgeCaseFixtures::longGuardLimitedChain();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(CurrencyScenarioFactory::money('SRC', '1.000', 3))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 64)
            ->withSearchGuards(2048, 2048)
            ->build();

        $result = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'DST'));

        self::assertTrue($result->hasPaths());
        $path = $result->paths()->toArray()[0];
        self::assertCount(PathFinderEdgeCaseFixtures::LONG_CHAIN_SEGMENTS, $path->legs());
        self::assertFalse($result->guardLimits()->anyLimitReached());
    }
}
