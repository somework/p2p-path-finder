<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Support\Generator;

use Random\Engine\Mt19937;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function array_fill;
use function array_map;
use function array_reduce;
use function array_values;
use function count;
use function explode;
use function max;
use function range;
use function str_pad;
use function substr;

use const STR_PAD_LEFT;

/**
 * Generates layered trading graphs to exercise PathFinder end-to-end behaviour.
 */
final class PathFinderScenarioGenerator
{
    private const AMOUNT_SCALE = 3;
    private const RATE_SCALE = 3;

    private Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer(new Mt19937());
    }

    /**
     * @return array{
     *     orders: list<Order>,
     *     source: non-empty-string,
     *     target: non-empty-string,
     *     maxHops: positive-int,
     *     topK: positive-int,
     *     tolerance: numeric-string,
     * }
     */
    public function scenario(): array
    {
        $depth = $this->randomizer->getInt(1, 4);
        $fanout = $this->randomizer->getInt(1, 3);

        $orders = $this->buildLayeredOrders($depth, $fanout);
        $maxPathLength = $depth + 1;

        $maxHops = $this->randomizer->getInt(1, $maxPathLength + 1);
        $maxPaths = max(1, $fanout ** $depth);
        $topK = $this->randomizer->getInt(1, min(5, $maxPaths));

        return [
            'orders' => $orders,
            'source' => 'SRC',
            'target' => 'DST',
            'maxHops' => $maxHops,
            'topK' => $topK,
            'tolerance' => $this->randomTolerance(),
        ];
    }

    /**
     * @return list<Order>
     */
    private function buildLayeredOrders(int $depth, int $fanout): array
    {
        $orders = [];
        $currentLayer = ['SRC'];
        $cursor = 0;

        for ($layer = 0; $layer < $depth; ++$layer) {
            $nextLayer = [];

            foreach ($currentLayer as $currency) {
                $branches = $this->randomizer->getInt(1, $fanout);

                for ($branch = 0; $branch < $branches; ++$branch) {
                    $nextCurrency = $this->syntheticCurrency($cursor++);
                    $minAmount = $this->randomAmount(self::AMOUNT_SCALE, false);
                    $maxAmount = $this->randomAmountGreaterThan(self::AMOUNT_SCALE, $minAmount);
                    $rate = $this->randomPositiveDecimal(self::RATE_SCALE);
                    $feePolicy = $this->maybeFeePolicy();

                    if (0 === $this->randomizer->getInt(0, 1)) {
                        $orders[] = OrderFactory::sell(
                            base: $nextCurrency,
                            quote: $currency,
                            minAmount: $minAmount,
                            maxAmount: $maxAmount,
                            rate: $rate,
                            amountScale: self::AMOUNT_SCALE,
                            rateScale: self::RATE_SCALE,
                            feePolicy: $feePolicy,
                        );
                    } else {
                        $orders[] = OrderFactory::buy(
                            base: $currency,
                            quote: $nextCurrency,
                            minAmount: $minAmount,
                            maxAmount: $maxAmount,
                            rate: $rate,
                            amountScale: self::AMOUNT_SCALE,
                            rateScale: self::RATE_SCALE,
                            feePolicy: $feePolicy,
                        );
                    }

                    $nextLayer[] = $nextCurrency;
                }
            }

            $currentLayer = array_values($nextLayer);
        }

        foreach ($currentLayer as $currency) {
            $minAmount = $this->randomAmount(self::AMOUNT_SCALE, false);
            $maxAmount = $this->randomAmountGreaterThan(self::AMOUNT_SCALE, $minAmount);
            $rate = $this->randomPositiveDecimal(self::RATE_SCALE);
            $feePolicy = $this->maybeFeePolicy();

            if (0 === $this->randomizer->getInt(0, 1)) {
                $orders[] = OrderFactory::sell(
                    base: 'DST',
                    quote: $currency,
                    minAmount: $minAmount,
                    maxAmount: $maxAmount,
                    rate: $rate,
                    amountScale: self::AMOUNT_SCALE,
                    rateScale: self::RATE_SCALE,
                    feePolicy: $feePolicy,
                );
            } else {
                $orders[] = OrderFactory::buy(
                    base: $currency,
                    quote: 'DST',
                    minAmount: $minAmount,
                    maxAmount: $maxAmount,
                    rate: $rate,
                    amountScale: self::AMOUNT_SCALE,
                    rateScale: self::RATE_SCALE,
                    feePolicy: $feePolicy,
                );
            }
        }

        return $orders;
    }

    /**
     * @return non-empty-string
     */
    private function randomTolerance(): string
    {
        $choices = ['0.0', '0.01', '0.05', '0.10', '0.20'];

        return $choices[$this->randomizer->getInt(0, count($choices) - 1)];
    }

    private function maybeFeePolicy(): ?FeePolicy
    {
        return match ($this->randomizer->getInt(0, 4)) {
            0 => null,
            1 => FeePolicyFactory::baseSurcharge('0.0005'),
            2 => FeePolicyFactory::baseAndQuoteSurcharge('0.0004', '0.0006'),
            3 => FeePolicyFactory::quotePercentageWithFixed('0.0005', '0.010'),
            default => FeePolicyFactory::baseAndQuoteSurcharge('0.0002', '0.0003'),
        };
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
        $extra = $this->randomizer->getInt(1, $multiplier * $this->randomizer->getInt(1, 5));
        $units = $lowerUnits + $extra;

        return $this->formatUnits($units, $scale);
    }

    /**
     * @return numeric-string
     */
    private function randomPositiveDecimal(int $scale): string
    {
        $multiplier = $this->powerOfTen($scale);
        $units = $this->randomizer->getInt($multiplier, $multiplier * 200);

        return $this->formatUnits($units, $scale);
    }

    private function powerOfTen(int $scale): int
    {
        if (0 === $scale) {
            return 1;
        }

        return array_reduce(
            array_fill(0, $scale, 10),
            static fn (int $carry, int $value): int => $carry * $value,
            1,
        );
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
        $parts = array_map('intval', explode('.', $value));
        $integer = $parts[0] ?? 0;
        $fraction = (string) ($parts[1] ?? 0);
        $fraction = str_pad($fraction, $scale, '0', STR_PAD_LEFT);

        return ($integer * $this->powerOfTen($scale)) + (int) substr($fraction, 0, $scale);
    }

    /**
     * @return non-empty-string
     */
    private function syntheticCurrency(int $index): string
    {
        $letters = range('A', 'Z');

        $first = intdiv($index, 26 * 26) % 26;
        $second = intdiv($index, 26) % 26;
        $third = $index % 26;

        return $letters[$first].$letters[$second].$letters[$third];
    }
}
