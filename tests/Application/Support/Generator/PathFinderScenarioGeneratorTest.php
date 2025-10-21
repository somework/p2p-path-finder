<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Support\Generator;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Support\InfectionIterationLimiter;

use function preg_match;

final class PathFinderScenarioGeneratorTest extends TestCase
{
    use InfectionIterationLimiter;

    public function test_scenario_generation_is_reproducible_for_seeded_randomizer(): void
    {
        $generator = new PathFinderScenarioGenerator(new Randomizer(new Mt19937(1234)));

        $scenario = $generator->scenario();

        self::assertSame('SRC', $scenario['source']);
        self::assertSame('DST', $scenario['target']);
        self::assertCount(109, $scenario['orders']);
        self::assertSame(5, $scenario['maxHops']);
        self::assertSame(3, $scenario['topK']);
        self::assertSame('0.0', $scenario['tolerance']);

        $firstOrder = $scenario['orders'][0];
        self::assertSame(OrderSide::SELL, $firstOrder->side());
        self::assertSame('AAA', $firstOrder->assetPair()->base());
        self::assertSame('SRC', $firstOrder->assetPair()->quote());
        self::assertSame('3.893', $firstOrder->bounds()->min()->amount());
        self::assertSame('3.893', $firstOrder->bounds()->max()->amount());
        self::assertSame('64.869', $firstOrder->effectiveRate()->rate());
        self::assertNotNull($firstOrder->feePolicy());

        $lastOrder = $scenario['orders'][108];
        self::assertSame(OrderSide::BUY, $lastOrder->side());
        self::assertSame('ACK', $lastOrder->assetPair()->base());
        self::assertSame('DST', $lastOrder->assetPair()->quote());
        self::assertSame('1.628', $lastOrder->bounds()->min()->amount());
        self::assertSame('1.694', $lastOrder->bounds()->max()->amount());
        self::assertSame('116.408', $lastOrder->effectiveRate()->rate());
        self::assertNotNull($lastOrder->feePolicy());
    }

    public function test_fee_policy_choice_covers_all_variants(): void
    {
        $generator = new PathFinderScenarioGenerator();

        self::assertNull($generator->feePolicyForChoice(0));

        $baseOnly = $generator->feePolicyForChoice(1);
        $baseOnlyBreakdown = $baseOnly?->calculate(
            OrderSide::SELL,
            Money::fromString('AAA', '10.000', 3),
            Money::fromString('BBB', '20.000', 3),
        );
        self::assertNotNull($baseOnlyBreakdown);
        self::assertTrue($baseOnlyBreakdown->hasBaseFee());
        self::assertFalse($baseOnlyBreakdown->hasQuoteFee());

        $baseAndQuote = $generator->feePolicyForChoice(2);
        $baseAndQuoteBreakdown = $baseAndQuote?->calculate(
            OrderSide::BUY,
            Money::fromString('AAA', '5.000', 3),
            Money::fromString('BBB', '2.500', 3),
        );
        self::assertNotNull($baseAndQuoteBreakdown);
        self::assertTrue($baseAndQuoteBreakdown->hasBaseFee());
        self::assertTrue($baseAndQuoteBreakdown->hasQuoteFee());

        $quoteHeavy = $generator->feePolicyForChoice(3);
        $quoteHeavyBreakdown = $quoteHeavy?->calculate(
            OrderSide::SELL,
            Money::fromString('AAA', '5.000', 3),
            Money::fromString('BBB', '2.500', 3),
        );
        self::assertNotNull($quoteHeavyBreakdown);
        self::assertFalse($quoteHeavyBreakdown->hasBaseFee());
        self::assertTrue($quoteHeavyBreakdown->hasQuoteFee());

        $fallback = $generator->feePolicyForChoice(4);
        $fallbackBreakdown = $fallback?->calculate(
            OrderSide::BUY,
            Money::fromString('AAA', '8.000', 3),
            Money::fromString('BBB', '3.750', 3),
        );
        self::assertNotNull($fallbackBreakdown);
        self::assertTrue($fallbackBreakdown->hasBaseFee());
        self::assertTrue($fallbackBreakdown->hasQuoteFee());
    }

    public function test_tolerance_choice_is_stable(): void
    {
        $generator = new PathFinderScenarioGenerator();

        self::assertSame('0.0', $generator->toleranceChoice(0));
        self::assertSame('0.050', $generator->toleranceChoice(4));
        self::assertSame('0.010', $generator->toleranceChoice(7));
    }

    public function test_numeric_helpers_cover_edge_cases(): void
    {
        $generator = new PathFinderScenarioGenerator(new Randomizer(new Mt19937(1)));

        self::assertSame(1, $this->invokePrivate($generator, 'powerOfTen', 0));
        self::assertSame(1000, $this->invokePrivate($generator, 'powerOfTen', 3));

        self::assertSame('42', $this->invokePrivate($generator, 'formatUnits', 42, 0));
        self::assertSame('1.234', $this->invokePrivate($generator, 'formatUnits', 1234, 3));

        self::assertSame(1234, $this->invokePrivate($generator, 'parseUnits', '1.2345', 3));

        $randomAmount = $this->invokePrivate($generator, 'randomAmount', 0, true);
        self::assertSame(1, preg_match('/^\\d+$/', $randomAmount));

        $greaterAmount = $this->invokePrivate($generator, 'randomAmountGreaterThan', 3, '1.000');
        $lowerUnits = $this->invokePrivate($generator, 'parseUnits', '1.000', 3);
        $greaterUnits = $this->invokePrivate($generator, 'parseUnits', $greaterAmount, 3);
        self::assertGreaterThan($lowerUnits, $greaterUnits);

        $positiveDecimal = $this->invokePrivate($generator, 'randomPositiveDecimal', 3);
        self::assertSame(1, preg_match('/^\\d+\\.\\d{3}$/', $positiveDecimal));
    }

    public function test_generated_scenarios_respect_structural_invariants(): void
    {
        $generator = new PathFinderScenarioGenerator(new Randomizer(new Mt19937(42)));

        $limit = $this->iterationLimit(18, 4, 'P2P_SCENARIO_GENERATOR_ITERATIONS');

        for ($iteration = 0; $iteration < $limit; ++$iteration) {
            $scenario = $generator->scenario();

            self::assertNotEmpty($scenario['orders']);
            self::assertGreaterThanOrEqual(1, $scenario['maxHops']);
            self::assertLessThanOrEqual(6, $scenario['maxHops']);
            self::assertGreaterThanOrEqual(1, $scenario['topK']);
            self::assertLessThanOrEqual(5, $scenario['topK']);

            self::assertLessThanOrEqual(
                0,
                BcMath::comp($scenario['tolerance'], '0.050', 3),
                'Tolerance budget should remain tight to stress guard rails.',
            );

            $referencesSource = false;
            $referencesTarget = false;
            $sourceEdgeCount = 0;
            $mandatoryCount = 0;

            foreach ($scenario['orders'] as $order) {
                if ('SRC' === $order->assetPair()->base() || 'SRC' === $order->assetPair()->quote()) {
                    $referencesSource = true;
                    ++$sourceEdgeCount;
                }

                if ('DST' === $order->assetPair()->base() || 'DST' === $order->assetPair()->quote()) {
                    $referencesTarget = true;
                }

                if ($order->bounds()->min()->equals($order->bounds()->max())) {
                    ++$mandatoryCount;
                }
            }

            self::assertTrue($referencesSource, 'Scenario must connect to the source asset.');
            self::assertTrue($referencesTarget, 'Scenario must connect to the target asset.');
            self::assertGreaterThanOrEqual(3, $sourceEdgeCount, 'Scenarios should expose wide branching from the source.');
            self::assertGreaterThanOrEqual(1, $mandatoryCount, 'Scenarios should include mandatory minimum fills.');
        }
    }

    public function test_dataset_covers_each_template_deterministically(): void
    {
        $scenarios = PathFinderScenarioGenerator::dataset();

        self::assertCount(3, $scenarios);

        foreach ($scenarios as $scenario) {
            $mandatoryOrders = 0;

            foreach ($scenario['orders'] as $order) {
                if ($order->bounds()->min()->equals($order->bounds()->max())) {
                    ++$mandatoryOrders;
                }
            }

            self::assertGreaterThanOrEqual(1, $mandatoryOrders);
        }
    }

    private function invokePrivate(object $object, string $method, mixed ...$arguments)
    {
        $reflection = new ReflectionClass($object);
        $target = $reflection->getMethod($method);
        $target->setAccessible(true);

        return $target->invoke($object, ...$arguments);
    }
}
