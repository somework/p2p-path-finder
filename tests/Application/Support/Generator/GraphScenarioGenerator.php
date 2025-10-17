<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Support\Generator;

use Random\Engine\Mt19937;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function array_unique;
use function array_values;
use function chr;
use function count;
use function explode;
use function ord;
use function str_pad;
use function strlen;
use function substr;

use const STR_PAD_LEFT;

/**
 * Generates pseudo-random order collections to drive property-based tests for the graph builder.
 */
final class GraphScenarioGenerator
{
    private Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer(new Mt19937());
    }

    /**
     * @return list<Order>
     */
    public function orders(int $maxOrders = 12): array
    {
        $orderCount = $this->randomizer->getInt(1, $maxOrders);

        $orders = [];
        $currencies = [];

        for ($index = 0; $index < $orderCount; ++$index) {
            [$base, $quote] = $this->currencyPair($currencies);

            $side = 0 === $this->randomizer->getInt(0, 1) ? OrderSide::BUY : OrderSide::SELL;
            $amountScale = $this->randomizer->getInt(2, 6);
            $rateScale = $this->randomizer->getInt(2, 8);

            $minAmount = $this->randomAmount($amountScale, true);
            $maxAmount = $this->randomAmountGreaterThan($amountScale, $minAmount);
            $rate = $this->randomPositiveDecimal($rateScale);

            $feePolicy = $this->maybeFeePolicy();

            $orders[] = OrderFactory::createOrder(
                $side,
                $base,
                $quote,
                $minAmount,
                $maxAmount,
                $rate,
                $amountScale,
                $rateScale,
                $feePolicy,
            );

            $currencies = array_values(array_unique([...$currencies, $base, $quote]));
        }

        return $orders;
    }

    /**
     * @param list<non-empty-string> $knownCurrencies
     *
     * @return array{non-empty-string, non-empty-string}
     */
    private function currencyPair(array $knownCurrencies): array
    {
        $base = $this->chooseCurrency($knownCurrencies);
        $quote = $this->chooseCurrency($knownCurrencies, $base);

        return [$base, $quote];
    }

    /**
     * @param list<non-empty-string> $knownCurrencies
     *
     * @return non-empty-string
     */
    private function chooseCurrency(array $knownCurrencies, ?string $exclude = null): string
    {
        if ([] !== $knownCurrencies && $this->randomizer->getInt(0, 100) < 60) {
            $choice = $knownCurrencies[$this->randomizer->getInt(0, count($knownCurrencies) - 1)];
            if (null === $exclude || $choice !== $exclude) {
                return $choice;
            }
        }

        do {
            $currency = $this->randomCurrencyCode();
        } while (null !== $exclude && $currency === $exclude);

        return $currency;
    }

    /**
     * @return non-empty-string
     */
    private function randomCurrencyCode(): string
    {
        $code = '';
        for ($index = 0; $index < 3; ++$index) {
            $code .= chr($this->randomizer->getInt(ord('A'), ord('Z')));
        }

        return $code;
    }

    /**
     * @return numeric-string
     */
    private function randomAmount(int $scale, bool $allowZero): string
    {
        $multiplier = $this->powerOfTen($scale);
        $minUnits = $allowZero ? 0 : $multiplier;
        $maxUnits = $multiplier * $this->randomizer->getInt(1, 9);

        $units = $this->randomizer->getInt($minUnits, $maxUnits);

        return $this->formatUnits($units, $scale);
    }

    /**
     * @param numeric-string $lowerBound
     *
     * @return numeric-string
     */
    private function randomAmountGreaterThan(int $scale, string $lowerBound): string
    {
        $lowerUnits = $this->parseUnits($lowerBound, $scale);
        $multiplier = $this->powerOfTen($scale);
        $extra = $this->randomizer->getInt(1, $multiplier * $this->randomizer->getInt(1, 9));
        $units = $lowerUnits + $extra;

        return $this->formatUnits($units, $scale);
    }

    /**
     * @return numeric-string
     */
    private function randomPositiveDecimal(int $scale): string
    {
        $multiplier = $this->powerOfTen($scale);
        $units = $this->randomizer->getInt($multiplier, $multiplier * 500);

        return $this->formatUnits($units, $scale);
    }

    private function powerOfTen(int $scale): int
    {
        $value = 1;
        for ($index = 0; $index < $scale; ++$index) {
            $value *= 10;
        }

        return $value;
    }

    /**
     * @return numeric-string
     */
    private function formatUnits(int $units, int $scale): string
    {
        if (0 === $scale) {
            return (string) $units;
        }

        $divisor = $this->powerOfTen($scale);
        $integer = intdiv($units, $divisor);
        $fraction = $units % $divisor;

        /** @var numeric-string $formatted */
        $formatted = $integer.'.'.str_pad((string) $fraction, $scale, '0', STR_PAD_LEFT);

        return $formatted;
    }

    /**
     * @param numeric-string $value
     */
    private function parseUnits(string $value, int $scale): int
    {
        if (0 === $scale) {
            return (int) $value;
        }

        $parts = explode('.', $value, 2);
        $integer = (int) $parts[0];
        $fraction = $parts[1] ?? '';
        if (strlen($fraction) < $scale) {
            $fraction = str_pad($fraction, $scale, '0');
        }

        return $integer * $this->powerOfTen($scale) + (int) substr($fraction, 0, $scale);
    }

    private function maybeFeePolicy(): ?FeePolicy
    {
        $roll = $this->randomizer->getInt(0, 100);
        if ($roll < 35) {
            $ratio = $this->randomRatio();

            return FeePolicyFactory::baseSurcharge($ratio);
        }

        if ($roll < 65) {
            $baseRatio = $this->randomRatio();
            $quoteRatio = $this->randomRatio();

            return FeePolicyFactory::baseAndQuoteSurcharge($baseRatio, $quoteRatio);
        }

        if ($roll < 85) {
            $percentage = $this->randomRatio(5);
            $fixed = $this->randomFixedComponent(5);

            return FeePolicyFactory::quotePercentageWithFixed($percentage, $fixed, 5);
        }

        return null;
    }

    /**
     * @return numeric-string
     */
    private function randomRatio(int $scale = 6): string
    {
        $multiplier = $this->powerOfTen($scale);
        $units = $this->randomizer->getInt(1, (int) ($multiplier * 0.05));

        return $this->formatUnits($units, $scale);
    }

    /**
     * @return numeric-string
     */
    private function randomFixedComponent(int $scale): string
    {
        $multiplier = $this->powerOfTen($scale);
        $units = $this->randomizer->getInt(1, $multiplier * 5);

        return $this->formatUnits($units, $scale);
    }
}
