<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Service;

use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function strtoupper;

/**
 * Immutable request DTO carrying the dependencies required to run a path search.
 *
 * @psalm-immutable
 */
final class PathSearchRequest
{
    private readonly string $targetAsset;
    private readonly Money $spendAmount;
    private readonly SpendConstraints $spendConstraints;

    /**
     * @throws InvalidInput|PrecisionViolation when the configured spend boundaries are inconsistent
     */
    public function __construct(
        private readonly OrderBook $orderBook,
        private readonly PathSearchConfig $config,
        string $targetAsset,
    ) {
        $this->targetAsset = strtoupper($targetAsset);
        $this->spendAmount = $config->spendAmount();
        $this->spendConstraints = SpendConstraints::from(
            $config->minimumSpendAmount(),
            $config->maximumSpendAmount(),
            $this->spendAmount,
        );
    }

    public function orderBook(): OrderBook
    {
        return $this->orderBook;
    }

    public function config(): PathSearchConfig
    {
        return $this->config;
    }

    public function targetAsset(): string
    {
        return $this->targetAsset;
    }

    public function spendAmount(): Money
    {
        return $this->spendAmount;
    }

    public function sourceAsset(): string
    {
        return $this->spendAmount->currency();
    }

    public function minimumHops(): int
    {
        return $this->config->minimumHops();
    }

    public function maximumHops(): int
    {
        return $this->config->maximumHops();
    }

    public function spendConstraints(): SpendConstraints
    {
        return $this->spendConstraints;
    }
}
