<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Engine\State;

use Brick\Math\BigDecimal;
use InvalidArgumentException;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;

use function array_filter;
use function array_keys;
use function count;
use function implode;
use function spl_object_id;
use function sprintf;

/**
 * Immutable representation of a multi-currency portfolio state for split/merge execution planning.
 *
 * ## Purpose
 *
 * PortfolioState tracks balances across MULTIPLE currencies simultaneously, enabling:
 * - Split execution: Input can be SPLIT across multiple parallel routes (A→B and A→C simultaneously)
 * - Multiple orders: Multiple orders for same direction can fill capacity (two A→B orders)
 * - Merge execution: Routes MERGE at target currency (B→D and C→D converge)
 * - Backtracking prevention: Once all funds leave a currency, cannot receive back into it (A→B→A forbidden)
 *
 * ## Invariants
 *
 * - **Non-negative balances**: All balances must be >= 0
 * - **Visited marking**: A currency is marked visited when its balance becomes zero after spending
 * - **No backtracking**: Cannot receive funds into a visited currency (unless already has balance)
 * - **Order uniqueness**: Each order can only be used once per portfolio state
 * - **Immutability**: All operations return new instances
 *
 * @invariant forall currency: balance(currency) >= 0
 * @invariant visited(currency) implies balance was depleted through spending
 * @invariant canReceiveInto(currency) == !hasVisited(currency) || hasBalance(currency)
 * @invariant executeOrder returns new instance (immutable)
 *
 * @internal
 */
final class PortfolioState
{
    /**
     * @param array<string, Money> $balances   Currency code => balance mapping
     * @param array<string, true>  $visited    Currencies that were fully spent (no backtracking)
     * @param array<int, true>     $usedOrders Order object IDs that have been executed
     */
    private function __construct(
        private readonly array $balances,
        private readonly array $visited,
        private readonly array $usedOrders,
        private readonly BigDecimal $totalCost,
    ) {
    }

    /**
     * Creates a new portfolio state with a single starting balance.
     */
    public static function initial(Money $startingBalance): self
    {
        if ($startingBalance->isZero()) {
            throw new InvalidArgumentException('Initial portfolio balance cannot be zero.');
        }

        return new self(
            balances: [$startingBalance->currency() => $startingBalance],
            visited: [],
            usedOrders: [],
            totalCost: BigDecimal::zero(),
        );
    }

    /**
     * Returns the balance for a given currency, or zero if no balance exists.
     */
    public function balance(string $currency): Money
    {
        return $this->balances[$currency] ?? Money::zero($currency);
    }

    /**
     * Returns all balances with amount > 0.
     *
     * @return array<string, Money>
     */
    public function nonZeroBalances(): array
    {
        return array_filter(
            $this->balances,
            static fn (Money $money): bool => !$money->isZero(),
        );
    }

    /**
     * Checks if there is a positive balance in the given currency.
     */
    public function hasBalance(string $currency): bool
    {
        return isset($this->balances[$currency]) && !$this->balances[$currency]->isZero();
    }

    /**
     * Returns the total accumulated cost of all executed orders.
     */
    public function totalCost(): BigDecimal
    {
        return $this->totalCost;
    }

    /**
     * Checks if the given currency has been fully spent from (visited).
     */
    public function hasVisited(string $currency): bool
    {
        return isset($this->visited[$currency]);
    }

    /**
     * Checks if funds can be received into the given currency.
     *
     * Rule: Cannot receive into a currency that was fully spent from,
     * UNLESS there is already a balance there (from another route).
     */
    public function canReceiveInto(string $currency): bool
    {
        // Can receive if not visited OR if currently has balance there
        return !$this->hasVisited($currency) || $this->hasBalance($currency);
    }

    /**
     * Checks if the given order can be executed with the specified spend amount.
     *
     * Validates:
     * - Sufficient balance in source currency
     * - Order not already used
     * - Target currency is receivable (not visited unless has balance)
     */
    public function canExecuteOrder(Order $order, Money $spendAmount): bool
    {
        $sourceCurrency = $order->assetPair()->base();
        $targetCurrency = $order->assetPair()->quote();

        // Check sufficient balance
        if (!$this->hasBalance($sourceCurrency)) {
            return false;
        }

        $currentBalance = $this->balance($sourceCurrency);
        if ($currentBalance->lessThan($spendAmount)) {
            return false;
        }

        // Check order not already used
        if ($this->hasUsedOrder($order)) {
            return false;
        }

        // Check target currency is receivable
        if (!$this->canReceiveInto($targetCurrency)) {
            return false;
        }

        return true;
    }

    /**
     * Checks if the given order can be executed with the specified spend amount,
     * taking order side into account.
     *
     * For BUY orders: source = base, target = quote (taker spends base)
     * For SELL orders: source = quote, target = base (taker spends quote)
     *
     * Validates:
     * - Sufficient balance in source currency
     * - Order not already used
     * - Target currency is receivable (not visited unless has balance)
     */
    public function canExecuteOrderWithSide(Order $order, OrderSide $side, Money $spendAmount): bool
    {
        [$sourceCurrency, $targetCurrency] = $this->resolveOrderDirection($order, $side);

        // Check sufficient balance
        if (!$this->hasBalance($sourceCurrency)) {
            return false;
        }

        $currentBalance = $this->balance($sourceCurrency);
        if ($currentBalance->lessThan($spendAmount)) {
            return false;
        }

        // Check order not already used
        if ($this->hasUsedOrder($order)) {
            return false;
        }

        // Check target currency is receivable
        if (!$this->canReceiveInto($targetCurrency)) {
            return false;
        }

        return true;
    }

    /**
     * Resolves the source and target currencies based on order side.
     *
     * @return array{0: string, 1: string} [sourceCurrency, targetCurrency]
     */
    private function resolveOrderDirection(Order $order, OrderSide $side): array
    {
        if (OrderSide::BUY === $side) {
            // BUY: taker spends base, receives quote
            return [$order->assetPair()->base(), $order->assetPair()->quote()];
        }

        // SELL: taker spends quote, receives base
        return [$order->assetPair()->quote(), $order->assetPair()->base()];
    }

    /**
     * Executes an order, returning a new portfolio state with updated balances.
     *
     * Operations:
     * 1. Deducts spendAmount from source currency
     * 2. Adds received amount to target currency
     * 3. Marks order as used
     * 4. If source balance becomes zero, marks currency as visited
     * 5. Adds order cost to total cost
     *
     * @throws InvalidArgumentException when the order cannot be executed
     */
    public function executeOrder(Order $order, Money $spendAmount, BigDecimal $cost): self
    {
        if (!$this->canExecuteOrder($order, $spendAmount)) {
            throw new InvalidArgumentException('Cannot execute order: insufficient balance, order already used, or target currency not receivable.');
        }

        $sourceCurrency = $order->assetPair()->base();
        $targetCurrency = $order->assetPair()->quote();

        // Validate spend amount currency matches order base currency
        if ($spendAmount->currency() !== $sourceCurrency) {
            throw new InvalidArgumentException(sprintf('Spend amount currency "%s" must match order base currency "%s".', $spendAmount->currency(), $sourceCurrency));
        }

        // Calculate received amount using order's effective rate
        $receivedAmount = $order->calculateEffectiveQuoteAmount($spendAmount);

        // Update balances
        $newBalances = $this->balances;

        // Deduct from source
        $newSourceBalance = $this->balance($sourceCurrency)->subtract($spendAmount);
        if ($newSourceBalance->isZero()) {
            unset($newBalances[$sourceCurrency]);
        } else {
            $newBalances[$sourceCurrency] = $newSourceBalance;
        }

        // Add to target
        if (isset($newBalances[$targetCurrency])) {
            $newBalances[$targetCurrency] = $newBalances[$targetCurrency]->add($receivedAmount);
        } else {
            $newBalances[$targetCurrency] = $receivedAmount;
        }

        // Mark source as visited if balance depleted
        $newVisited = $this->visited;
        if ($newSourceBalance->isZero()) {
            $newVisited[$sourceCurrency] = true;
        }

        // Mark order as used
        $newUsedOrders = $this->usedOrders;
        $newUsedOrders[self::orderFingerprint($order)] = true;

        // Update total cost
        $newTotalCost = $this->totalCost->plus($cost);

        return new self($newBalances, $newVisited, $newUsedOrders, $newTotalCost);
    }

    /**
     * Executes an order with explicit order side, returning a new portfolio state.
     *
     * This method handles both BUY and SELL orders correctly:
     * - BUY: taker spends base, receives quote
     * - SELL: taker spends quote, receives base
     *
     * Operations:
     * 1. Deducts spendAmount from source currency
     * 2. Adds receivedAmount to target currency
     * 3. Marks order as used
     * 4. If source balance becomes zero, marks currency as visited
     * 5. Adds order cost to total cost
     *
     * @throws InvalidArgumentException when the order cannot be executed
     */
    public function executeOrderWithSide(
        Order $order,
        OrderSide $side,
        Money $spendAmount,
        Money $receivedAmount,
        BigDecimal $cost,
    ): self {
        if (!$this->canExecuteOrderWithSide($order, $side, $spendAmount)) {
            throw new InvalidArgumentException('Cannot execute order: insufficient balance, order already used, or target currency not receivable.');
        }

        [$sourceCurrency, $targetCurrency] = $this->resolveOrderDirection($order, $side);

        // Validate spend amount currency matches source currency
        if ($spendAmount->currency() !== $sourceCurrency) {
            throw new InvalidArgumentException(sprintf('Spend amount currency "%s" must match source currency "%s".', $spendAmount->currency(), $sourceCurrency));
        }

        // Validate received amount currency matches target currency
        if ($receivedAmount->currency() !== $targetCurrency) {
            throw new InvalidArgumentException(sprintf('Received amount currency "%s" must match target currency "%s".', $receivedAmount->currency(), $targetCurrency));
        }

        // Update balances
        $newBalances = $this->balances;

        // Deduct from source
        $newSourceBalance = $this->balance($sourceCurrency)->subtract($spendAmount);
        if ($newSourceBalance->isZero()) {
            unset($newBalances[$sourceCurrency]);
        } else {
            $newBalances[$sourceCurrency] = $newSourceBalance;
        }

        // Add to target
        if (isset($newBalances[$targetCurrency])) {
            $newBalances[$targetCurrency] = $newBalances[$targetCurrency]->add($receivedAmount);
        } else {
            $newBalances[$targetCurrency] = $receivedAmount;
        }

        // Mark source as visited if balance depleted
        $newVisited = $this->visited;
        if ($newSourceBalance->isZero()) {
            $newVisited[$sourceCurrency] = true;
        }

        // Mark order as used
        $newUsedOrders = $this->usedOrders;
        $newUsedOrders[self::orderFingerprint($order)] = true;

        // Update total cost
        $newTotalCost = $this->totalCost->plus($cost);

        return new self($newBalances, $newVisited, $newUsedOrders, $newTotalCost);
    }

    /**
     * Checks if the given order has already been used.
     */
    public function hasUsedOrder(Order $order): bool
    {
        return isset($this->usedOrders[self::orderFingerprint($order)]);
    }

    /**
     * Returns a new portfolio state with the order marked as used.
     */
    public function markOrderUsed(Order $order): self
    {
        if ($this->hasUsedOrder($order)) {
            return $this;
        }

        $newUsedOrders = $this->usedOrders;
        $newUsedOrders[self::orderFingerprint($order)] = true;

        return new self($this->balances, $this->visited, $newUsedOrders, $this->totalCost);
    }

    /**
     * Generates a deterministic fingerprint for the order.
     *
     * Uses object ID for identity tracking within a single execution context.
     */
    private static function orderFingerprint(Order $order): int
    {
        return spl_object_id($order);
    }

    /**
     * Generates a deterministic signature for state registry deduplication.
     *
     * Format: balances:CURRENCY=AMOUNT,...|visited:CURRENCY,...|orders:COUNT
     *
     * Sorted alphabetically for determinism.
     */
    public function signature(): string
    {
        // Build sorted balance signature
        $balancesParts = [];
        $balanceKeys = array_keys($this->balances);
        sort($balanceKeys);
        foreach ($balanceKeys as $currency) {
            if (!$this->balances[$currency]->isZero()) {
                $balancesParts[] = $currency.'='.$this->balances[$currency]->amount();
            }
        }

        // Build sorted visited signature
        $visitedKeys = array_keys($this->visited);
        sort($visitedKeys);

        // Build sorted used orders signature (count only for performance)
        $usedOrdersCount = count($this->usedOrders);

        $segments = [
            'balances:'.implode(',', $balancesParts),
            'visited:'.implode(',', $visitedKeys),
            'orders:'.$usedOrdersCount,
            'cost:'.$this->totalCost->__toString(),
        ];

        return implode('|', $segments);
    }

    /**
     * Returns all currencies that have been visited (fully spent from).
     *
     * @return array<string, true>
     */
    public function visited(): array
    {
        return $this->visited;
    }

    /**
     * Returns all balances (including zero balances if they exist in the map).
     *
     * @return array<string, Money>
     */
    public function balances(): array
    {
        return $this->balances;
    }
}
