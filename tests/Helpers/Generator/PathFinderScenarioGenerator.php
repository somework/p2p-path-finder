<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Helpers\Generator;

use Random\Engine\Mt19937;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeePolicy;
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
use function min;
use function range;
use function str_pad;
use function substr;

use const STR_PAD_LEFT;

/**
 * Generates layered trading graphs to exercise PathFinder end-to-end behaviour.
 *
 * The generator favours dense graphs with tight tolerance windows and explicit
 * mandatory minima so that property tests continuously exercise the guard rails
 * around minimum fills and branching heuristics.
 */
final class PathFinderScenarioGenerator
{
    private const AMOUNT_SCALE = 3;
    private const RATE_SCALE = 3;

    /**
     * @var non-empty-list<numeric-string>
     */
    private const TOLERANCE_CHOICES = ['0.0', '0.005', '0.010', '0.020', '0.050'];

    private const HEADROOM_DIVISOR = 12;

    /**
     * Scenario templates used by property tests to guarantee coverage of
     * mandatory minima, tight tolerances and wide branching.
     *
     * @var non-empty-list<array{
     *     label: non-empty-string,
     *     depth: positive-int,
     *     branching: array{min: positive-int, max: positive-int},
     *     mandatoryCount: positive-int,
     *     toleranceChoices: non-empty-list<numeric-string>,
     *     topKRange: array{min: positive-int, max: positive-int},
     *     seed: positive-int,
     * }>
     */
    private const SCENARIO_TEMPLATES = [
        [
            'label' => 'fanout-4-hop-3',
            'depth' => 3,
            'branching' => ['min' => 3, 'max' => 4],
            'mandatoryCount' => 3,
            'toleranceChoices' => ['0.0', '0.005', '0.010'],
            'topKRange' => ['min' => 2, 'max' => 4],
            'seed' => 17,
        ],
        [
            'label' => 'mandatory-hop-4',
            'depth' => 4,
            'branching' => ['min' => 3, 'max' => 3],
            'mandatoryCount' => 4,
            'toleranceChoices' => ['0.0', '0.005', '0.010', '0.015'],
            'topKRange' => ['min' => 2, 'max' => 5],
            'seed' => 29,
        ],
        [
            'label' => 'wide-fanout-bounded-headroom',
            'depth' => 3,
            'branching' => ['min' => 3, 'max' => 5],
            'mandatoryCount' => 2,
            'toleranceChoices' => ['0.0', '0.010', '0.020', '0.050'],
            'topKRange' => ['min' => 3, 'max' => 5],
            'seed' => 41,
        ],
    ];

    /**
     * @var non-empty-list<numeric-string>
     */
    private const SCALE_CHOICES = [
        '1',
        '10',
        '1000',
        '1000000',
        '1000000000000000000000000000000000000',
    ];

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
     *     scaleBy: numeric-string,
     * }
     */
    public function scenario(): array
    {
        $template = self::SCENARIO_TEMPLATES[
            $this->randomizer->getInt(0, count(self::SCENARIO_TEMPLATES) - 1)
        ];

        return $this->scenarioFromTemplate($template);
    }

    /**
     * @return list<array{
     *     orders: list<Order>,
     *     source: non-empty-string,
     *     target: non-empty-string,
     *     maxHops: positive-int,
     *     topK: positive-int,
     *     tolerance: numeric-string,
     *     scaleBy: numeric-string,
     * }>
     */
    public static function dataset(): array
    {
        $scenarios = [];

        foreach (self::SCENARIO_TEMPLATES as $template) {
            $generator = new self(new Randomizer(new Mt19937($template['seed'])));
            $scenarios[] = $generator->scenarioFromTemplate($template);
        }

        return $scenarios;
    }

    /**
     * @return numeric-string
     */
    public function toleranceChoice(int $choice): string
    {
        $index = $choice % count(self::TOLERANCE_CHOICES);

        return self::TOLERANCE_CHOICES[$index];
    }

    public function feePolicyForChoice(int $choice): ?FeePolicy
    {
        return match ($choice) {
            0 => null,
            1 => FeePolicyFactory::baseSurcharge('0.0005'),
            2 => FeePolicyFactory::baseAndQuoteSurcharge('0.0004', '0.0006'),
            3 => FeePolicyFactory::quotePercentageWithFixed('0.0005', '0.010'),
            default => FeePolicyFactory::baseAndQuoteSurcharge('0.0002', '0.0003'),
        };
    }

    /**
     * @return list<Order>
     */
    private function buildLayeredOrders(int $depth, array $branching, int $mandatoryQuota): array
    {
        $orders = [];
        $currentLayer = ['SRC'];
        $cursor = 0;

        for ($layer = 0; $layer < $depth; ++$layer) {
            $nextLayer = [];

            foreach ($currentLayer as $currency) {
                $branches = $this->randomizer->getInt($branching['min'], $branching['max']);

                for ($branch = 0; $branch < $branches; ++$branch) {
                    $nextCurrency = $this->syntheticCurrency($cursor++);
                    [$minAmount, $maxAmount, $mandatoryQuota] = $this->boundsForEdge($mandatoryQuota);
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
            [$minAmount, $maxAmount, $mandatoryQuota] = $this->boundsForEdge($mandatoryQuota);
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
     * @param array{
     *     label: non-empty-string,
     *     depth: positive-int,
     *     branching: array{min: positive-int, max: positive-int},
     *     mandatoryCount: positive-int,
     *     toleranceChoices: non-empty-list<numeric-string>,
     *     topKRange: array{min: positive-int, max: positive-int},
     *     seed: positive-int,
     * } $template
     *
     * @return array{
     *     orders: list<Order>,
     *     source: non-empty-string,
     *     target: non-empty-string,
     *     maxHops: positive-int,
     *     topK: positive-int,
     *     tolerance: numeric-string,
     *     scaleBy: numeric-string,
     * }
     */
    private function scenarioFromTemplate(array $template): array
    {
        $orders = $this->buildLayeredOrders(
            $template['depth'],
            $template['branching'],
            $template['mandatoryCount'],
        );

        $maxPathLength = $template['depth'] + 1;
        $upperHopLimit = max($maxPathLength + 1, 2);
        $lowerHopLimit = min(2, $upperHopLimit);
        $maxHops = $this->randomizer->getInt($lowerHopLimit, $upperHopLimit);

        $maxPaths = max(1, $template['branching']['max'] ** $template['depth']);
        $topKUpperBound = min($template['topKRange']['max'], $maxPaths);
        $topKLowerBound = min($template['topKRange']['min'], $topKUpperBound);
        $topK = $this->randomizer->getInt($topKLowerBound, $topKUpperBound);

        $tolerance = $this->randomTolerance($template['toleranceChoices']);

        return [
            'orders' => $orders,
            'source' => 'SRC',
            'target' => 'DST',
            'maxHops' => $maxHops,
            'topK' => $topK,
            'tolerance' => $tolerance,
            'scaleBy' => $this->deterministicScaleFactor($orders, $maxHops, $topK),
        ];
    }

    /**
     * @return numeric-string
     */
    private function randomTolerance(array $choices): string
    {
        $index = $this->randomizer->getInt(0, count($choices) - 1);

        return $choices[$index];
    }

    /**
     * @param list<Order> $orders
     *
     * @return numeric-string
     */
    private function deterministicScaleFactor(array $orders, int $maxHops, int $topK): string
    {
        $indexSeed = count($orders) + $maxHops + $topK;
        $index = $indexSeed % count(self::SCALE_CHOICES);

        return self::SCALE_CHOICES[$index];
    }

    private function maybeFeePolicy(): ?FeePolicy
    {
        return $this->feePolicyForChoice($this->randomizer->getInt(0, 4));
    }

    /**
     * @return array{0: numeric-string, 1: numeric-string, 2: int}
     */
    private function boundsForEdge(int $mandatoryQuota): array
    {
        $forceMandatory = $mandatoryQuota > 0;
        [$minAmount, $maxAmount] = $this->randomBounds(self::AMOUNT_SCALE, $forceMandatory);

        if ($forceMandatory) {
            --$mandatoryQuota;
        }

        return [$minAmount, $maxAmount, $mandatoryQuota];
    }

    /**
     * @return array{0: numeric-string, 1: numeric-string}
     */
    private function randomBounds(int $scale, bool $forceMandatory): array
    {
        $minAmount = $this->randomAmount($scale, false);

        if ($forceMandatory) {
            return [$minAmount, $minAmount];
        }

        $minUnits = $this->parseUnits($minAmount, $scale);
        $headroomUnits = $this->randomizer->getInt(1, max(1, intdiv($minUnits, self::HEADROOM_DIVISOR)));
        $maxAmount = $this->formatUnits($minUnits + $headroomUnits, $scale);

        return [$minAmount, $maxAmount];
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
