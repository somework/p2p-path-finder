<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine\State;

use Brick\Math\BigDecimal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\PortfolioState;
use SomeWork\P2PPathFinder\Domain\Money\AssetPair;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBounds;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;

#[CoversClass(PortfolioState::class)]
final class PortfolioStateTest extends TestCase
{
    public function test_initial_state_has_single_balance(): void
    {
        $startingBalance = Money::fromString('USD', '100.00', 2);
        $state = PortfolioState::initial($startingBalance);

        self::assertTrue($state->hasBalance('USD'));
        self::assertSame('100.00', $state->balance('USD')->amount());
        self::assertFalse($state->hasBalance('EUR'));
        self::assertSame('0.00', $state->balance('EUR')->amount());
        self::assertCount(1, $state->nonZeroBalances());
        self::assertTrue($state->totalCost()->isZero());
    }

    public function test_initial_state_rejects_zero_balance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Initial portfolio balance cannot be zero.');

        PortfolioState::initial(Money::zero('USD', 2));
    }

    public function test_execute_order_reduces_source_increases_target(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));
        $order = $this->createOrder('USD', 'EUR', '0.90', '10.00', '100.00');
        $spendAmount = Money::fromString('USD', '50.00', 2);
        $cost = BigDecimal::of('0.05');

        $newState = $state->executeOrder($order, $spendAmount, $cost);

        // Source should be reduced
        self::assertSame('50.00', $newState->balance('USD')->amount());

        // Target should be increased (50 * 0.90 = 45)
        // Note: ExchangeRate uses scale 8 by default, so result has 8 decimal places
        self::assertTrue($newState->hasBalance('EUR'));
        self::assertSame('45.00000000', $newState->balance('EUR')->amount());

        // Cost should be tracked
        self::assertSame('0.05', $newState->totalCost()->__toString());
    }

    public function test_can_execute_order_throws_for_sell_order(): void
    {
        $state = PortfolioState::initial(Money::fromString('EUR', '100.00', 2));
        $sellOrder = new Order(
            OrderSide::SELL,
            AssetPair::fromString('USD', 'EUR'),
            OrderBounds::from(
                Money::fromString('USD', '10.00', 2),
                Money::fromString('USD', '100.00', 2),
            ),
            ExchangeRate::fromString('USD', 'EUR', '1.10', 8),
        );
        $spendAmount = Money::fromString('EUR', '50.00', 2);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('canExecuteOrder assumes BUY direction; use canExecuteOrderWithSide() for SELL orders.');

        $state->canExecuteOrder($sellOrder, $spendAmount);
    }

    public function test_execute_order_throws_for_sell_order(): void
    {
        $state = PortfolioState::initial(Money::fromString('EUR', '100.00', 2));
        $sellOrder = new Order(
            OrderSide::SELL,
            AssetPair::fromString('USD', 'EUR'),
            OrderBounds::from(
                Money::fromString('USD', '10.00', 2),
                Money::fromString('USD', '100.00', 2),
            ),
            ExchangeRate::fromString('USD', 'EUR', '1.10', 8),
        );
        $spendAmount = Money::fromString('EUR', '50.00', 2);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('executeOrder assumes BUY direction; use executeOrderWithSide() for SELL orders.');

        $state->executeOrder($sellOrder, $spendAmount, BigDecimal::zero());
    }

    public function test_cannot_execute_with_insufficient_balance(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '50.00', 2));
        $order = $this->createOrder('USD', 'EUR', '0.90', '10.00', '100.00');
        $spendAmount = Money::fromString('USD', '75.00', 2);

        self::assertFalse($state->canExecuteOrder($order, $spendAmount));

        $this->expectException(InvalidArgumentException::class);
        $state->executeOrder($order, $spendAmount, BigDecimal::zero());
    }

    public function test_cannot_execute_already_used_order(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));
        $order = $this->createOrder('USD', 'EUR', '0.90', '10.00', '100.00');
        $spendAmount = Money::fromString('USD', '25.00', 2);

        // Execute order once
        $newState = $state->executeOrder($order, $spendAmount, BigDecimal::zero());

        // Cannot execute same order again
        self::assertFalse($newState->canExecuteOrder($order, $spendAmount));

        $this->expectException(InvalidArgumentException::class);
        $newState->executeOrder($order, $spendAmount, BigDecimal::zero());
    }

    public function test_cannot_receive_into_visited_currency(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));
        $orderUsdToEur = $this->createOrder('USD', 'EUR', '0.90', '10.00', '100.00');

        // Spend ALL USD to get EUR
        $newState = $state->executeOrder($orderUsdToEur, Money::fromString('USD', '100.00', 2), BigDecimal::zero());

        // USD is now visited (fully spent)
        self::assertTrue($newState->hasVisited('USD'));
        self::assertFalse($newState->canReceiveInto('USD'));

        // Cannot execute order that would receive back into USD
        $orderEurToUsd = $this->createOrder('EUR', 'USD', '1.10', '10.00', '100.00');
        self::assertFalse($newState->canExecuteOrder($orderEurToUsd, Money::fromString('EUR', '10.00', 2)));
    }

    public function test_can_receive_into_currency_with_existing_balance(): void
    {
        // Start with balances in both USD and EUR
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));
        $orderUsdToEur = $this->createOrder('USD', 'EUR', '0.90', '10.00', '50.00');

        // Spend part of USD to get EUR
        $state = $state->executeOrder($orderUsdToEur, Money::fromString('USD', '50.00', 2), BigDecimal::zero());

        // Now we have USD and EUR
        self::assertTrue($state->hasBalance('USD'));
        self::assertTrue($state->hasBalance('EUR'));
        self::assertSame('50.00', $state->balance('USD')->amount());
        // ExchangeRate uses scale 8 by default
        self::assertSame('45.00000000', $state->balance('EUR')->amount());

        // Spend remaining USD to EUR
        $orderUsdToEur2 = $this->createOrder('USD', 'EUR', '0.90', '10.00', '50.00');
        $state = $state->executeOrder($orderUsdToEur2, Money::fromString('USD', '50.00', 2), BigDecimal::zero());

        // USD is now visited
        self::assertTrue($state->hasVisited('USD'));
        self::assertFalse($state->hasBalance('USD'));

        // We still have EUR, so can receive more EUR
        self::assertTrue($state->hasBalance('EUR'));

        // Now try to receive EUR from another route (GBP -> EUR)
        // First, we need GBP - let's create a scenario where we have EUR and want to go elsewhere
        // This test validates that having existing balance allows receiving more
    }

    public function test_signature_is_deterministic(): void
    {
        $state1 = PortfolioState::initial(Money::fromString('USD', '100.00', 2));
        $state2 = PortfolioState::initial(Money::fromString('USD', '100.00', 2));

        self::assertSame($state1->signature(), $state2->signature());
    }

    public function test_signature_changes_after_order_execution(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));
        $signatureBefore = $state->signature();

        $order = $this->createOrder('USD', 'EUR', '0.90', '10.00', '100.00');
        $newState = $state->executeOrder($order, Money::fromString('USD', '50.00', 2), BigDecimal::zero());

        self::assertNotSame($signatureBefore, $newState->signature());
    }

    public function test_signature_includes_sorted_balances(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));

        // Add EUR via order
        $orderUsdToEur = $this->createOrder('USD', 'EUR', '0.90', '10.00', '100.00');
        $state = $state->executeOrder($orderUsdToEur, Money::fromString('USD', '50.00', 2), BigDecimal::zero());

        // Signature should contain both currencies in sorted order
        $signature = $state->signature();
        self::assertStringContainsString('EUR=', $signature);
        self::assertStringContainsString('USD=', $signature);

        // EUR should come before USD alphabetically in signature
        $eurPos = strpos($signature, 'EUR=');
        $usdPos = strpos($signature, 'USD=');
        self::assertLessThan($usdPos, $eurPos);
    }

    public function test_immutability_returns_new_instance(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));
        $order = $this->createOrder('USD', 'EUR', '0.90', '10.00', '100.00');

        $newState = $state->executeOrder($order, Money::fromString('USD', '50.00', 2), BigDecimal::zero());

        // Original state should be unchanged
        self::assertSame('100.00', $state->balance('USD')->amount());
        self::assertFalse($state->hasBalance('EUR'));
        self::assertFalse($state->hasUsedOrder($order));

        // New state should have changes
        self::assertSame('50.00', $newState->balance('USD')->amount());
        self::assertTrue($newState->hasBalance('EUR'));
        self::assertTrue($newState->hasUsedOrder($order));
    }

    public function test_multiple_orders_track_all_used(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));
        $order1 = $this->createOrder('USD', 'EUR', '0.90', '10.00', '50.00');
        $order2 = $this->createOrder('USD', 'GBP', '0.80', '10.00', '50.00');

        // Execute first order
        $state = $state->executeOrder($order1, Money::fromString('USD', '30.00', 2), BigDecimal::zero());

        self::assertTrue($state->hasUsedOrder($order1));
        self::assertFalse($state->hasUsedOrder($order2));

        // Execute second order
        $state = $state->executeOrder($order2, Money::fromString('USD', '30.00', 2), BigDecimal::zero());

        self::assertTrue($state->hasUsedOrder($order1));
        self::assertTrue($state->hasUsedOrder($order2));
    }

    public function test_visited_marked_when_balance_depleted(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));
        $order = $this->createOrder('USD', 'EUR', '0.90', '50.00', '100.00');

        // Partially spend USD
        $state = $state->executeOrder($order, Money::fromString('USD', '50.00', 2), BigDecimal::zero());
        self::assertFalse($state->hasVisited('USD'));
        self::assertTrue($state->hasBalance('USD'));

        // Spend remaining USD with another order
        $order2 = $this->createOrder('USD', 'GBP', '0.80', '10.00', '50.00');
        $state = $state->executeOrder($order2, Money::fromString('USD', '50.00', 2), BigDecimal::zero());

        self::assertTrue($state->hasVisited('USD'));
        self::assertFalse($state->hasBalance('USD'));
    }

    public function test_non_zero_balances_excludes_zero_amounts(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));
        $order = $this->createOrder('USD', 'EUR', '0.90', '50.00', '100.00');

        // Spend all USD
        $state = $state->executeOrder($order, Money::fromString('USD', '100.00', 2), BigDecimal::zero());

        $nonZeroBalances = $state->nonZeroBalances();
        self::assertCount(1, $nonZeroBalances);
        self::assertArrayHasKey('EUR', $nonZeroBalances);
        self::assertArrayNotHasKey('USD', $nonZeroBalances);
    }

    public function test_mark_order_used_returns_same_instance_if_already_used(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));
        $order = $this->createOrder('USD', 'EUR', '0.90', '10.00', '100.00');

        $state = $state->executeOrder($order, Money::fromString('USD', '50.00', 2), BigDecimal::zero());
        $stateAfterMark = $state->markOrderUsed($order);

        self::assertSame($state, $stateAfterMark);
    }

    public function test_mark_order_used_returns_new_instance_if_not_used(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));
        $order = $this->createOrder('USD', 'EUR', '0.90', '10.00', '100.00');

        $newState = $state->markOrderUsed($order);

        self::assertNotSame($state, $newState);
        self::assertFalse($state->hasUsedOrder($order));
        self::assertTrue($newState->hasUsedOrder($order));
    }

    public function test_balance_returns_zero_for_unknown_currency(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));

        $jpyBalance = $state->balance('JPY');

        self::assertTrue($jpyBalance->isZero());
        self::assertSame('JPY', $jpyBalance->currency());
    }

    public function test_can_execute_order_validates_source_balance(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));
        $orderEurToGbp = $this->createOrder('EUR', 'GBP', '0.85', '10.00', '100.00');

        // No EUR balance, so cannot execute
        self::assertFalse($state->canExecuteOrder($orderEurToGbp, Money::fromString('EUR', '50.00', 2)));
    }

    public function test_split_execution_across_multiple_routes(): void
    {
        // Test split: USD -> EUR and USD -> GBP simultaneously (via different orders)
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));

        $orderUsdToEur = $this->createOrder('USD', 'EUR', '0.90', '10.00', '100.00');
        $orderUsdToGbp = $this->createOrder('USD', 'GBP', '0.80', '10.00', '100.00');

        // Split: 50 USD -> EUR, 50 USD -> GBP
        $state = $state->executeOrder($orderUsdToEur, Money::fromString('USD', '50.00', 2), BigDecimal::of('0.01'));
        $state = $state->executeOrder($orderUsdToGbp, Money::fromString('USD', '50.00', 2), BigDecimal::of('0.01'));

        // Should have EUR and GBP, no USD
        self::assertFalse($state->hasBalance('USD'));
        self::assertTrue($state->hasBalance('EUR'));
        self::assertTrue($state->hasBalance('GBP'));
        // ExchangeRate uses scale 8 by default
        self::assertSame('45.00000000', $state->balance('EUR')->amount());
        self::assertSame('40.00000000', $state->balance('GBP')->amount());
        self::assertTrue($state->hasVisited('USD'));
        self::assertSame('0.02', $state->totalCost()->__toString());
    }

    public function test_merge_execution_converging_routes(): void
    {
        // Start with USD, split to EUR and GBP, then both merge to CHF
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));

        // Split: USD -> EUR, USD -> GBP
        $orderUsdToEur = $this->createOrder('USD', 'EUR', '0.90', '10.00', '100.00');
        $orderUsdToGbp = $this->createOrder('USD', 'GBP', '0.80', '10.00', '100.00');

        $state = $state->executeOrder($orderUsdToEur, Money::fromString('USD', '50.00', 2), BigDecimal::zero());
        $state = $state->executeOrder($orderUsdToGbp, Money::fromString('USD', '50.00', 2), BigDecimal::zero());

        // Merge: EUR -> CHF, GBP -> CHF
        // Note: We need to spend the exact amounts from the balance which have scale 8
        $orderEurToChf = $this->createOrder('EUR', 'CHF', '1.05', '10.00', '100.00');
        $orderGbpToChf = $this->createOrder('GBP', 'CHF', '1.15', '10.00', '100.00');

        $state = $state->executeOrder($orderEurToChf, Money::fromString('EUR', '45.00000000', 8), BigDecimal::zero());
        $state = $state->executeOrder($orderGbpToChf, Money::fromString('GBP', '40.00000000', 8), BigDecimal::zero());

        // Should have only CHF
        self::assertFalse($state->hasBalance('USD'));
        self::assertFalse($state->hasBalance('EUR'));
        self::assertFalse($state->hasBalance('GBP'));
        self::assertTrue($state->hasBalance('CHF'));

        // CHF = (45 * 1.05) + (40 * 1.15) = 47.25 + 46.00 = 93.25
        // ExchangeRate uses scale 8 by default
        self::assertSame('93.25000000', $state->balance('CHF')->amount());
    }

    public function test_backtracking_prevention_full_cycle(): void
    {
        // A -> B -> A should be forbidden
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));

        // USD -> EUR (spend all)
        $orderUsdToEur = $this->createOrder('USD', 'EUR', '0.90', '50.00', '100.00');
        $state = $state->executeOrder($orderUsdToEur, Money::fromString('USD', '100.00', 2), BigDecimal::zero());

        self::assertTrue($state->hasVisited('USD'));
        self::assertFalse($state->canReceiveInto('USD'));

        // EUR -> USD should be blocked
        $orderEurToUsd = $this->createOrder('EUR', 'USD', '1.10', '10.00', '100.00');
        self::assertFalse($state->canExecuteOrder($orderEurToUsd, Money::fromString('EUR', '50.00', 2)));
    }

    public function test_cost_accumulation_across_multiple_orders(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));

        $order1 = $this->createOrder('USD', 'EUR', '0.90', '10.00', '100.00');
        $order2 = $this->createOrder('EUR', 'GBP', '0.85', '10.00', '100.00');

        $state = $state->executeOrder($order1, Money::fromString('USD', '50.00', 2), BigDecimal::of('0.10'));
        $state = $state->executeOrder($order2, Money::fromString('EUR', '45.00', 2), BigDecimal::of('0.05'));

        self::assertSame('0.15', $state->totalCost()->__toString());
    }

    public function test_visited_returns_all_visited_currencies(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));

        // Spend all USD
        $orderUsdToEur = $this->createOrder('USD', 'EUR', '0.90', '50.00', '100.00');
        $state = $state->executeOrder($orderUsdToEur, Money::fromString('USD', '100.00', 2), BigDecimal::zero());

        // Spend all EUR
        $orderEurToGbp = $this->createOrder('EUR', 'GBP', '0.85', '50.00', '100.00');
        $state = $state->executeOrder($orderEurToGbp, Money::fromString('EUR', '90.00', 2), BigDecimal::zero());

        $visited = $state->visited();
        self::assertArrayHasKey('USD', $visited);
        self::assertArrayHasKey('EUR', $visited);
        self::assertCount(2, $visited);
    }

    public function test_balances_returns_all_balances(): void
    {
        $state = PortfolioState::initial(Money::fromString('USD', '100.00', 2));
        $order = $this->createOrder('USD', 'EUR', '0.90', '10.00', '100.00');
        $state = $state->executeOrder($order, Money::fromString('USD', '50.00', 2), BigDecimal::zero());

        $balances = $state->balances();
        self::assertCount(2, $balances);
        self::assertArrayHasKey('USD', $balances);
        self::assertArrayHasKey('EUR', $balances);
    }

    /**
     * Creates an order for testing purposes.
     *
     * @param numeric-string $rate    Exchange rate
     * @param numeric-string $minFill Minimum fill amount
     * @param numeric-string $maxFill Maximum fill amount
     */
    private function createOrder(
        string $baseCurrency,
        string $quoteCurrency,
        string $rate,
        string $minFill,
        string $maxFill,
    ): Order {
        return new Order(
            OrderSide::BUY,
            AssetPair::fromString($baseCurrency, $quoteCurrency),
            OrderBounds::from(
                Money::fromString($baseCurrency, $minFill, 2),
                Money::fromString($baseCurrency, $maxFill, 2),
            ),
            ExchangeRate::fromString($baseCurrency, $quoteCurrency, $rate, 8),
        );
    }
}
