<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Support\Generator;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function preg_match;

final class PathFinderScenarioGeneratorTest extends TestCase
{
    public function test_scenario_generation_is_reproducible_for_seeded_randomizer(): void
    {
        $generator = new PathFinderScenarioGenerator(new Randomizer(new Mt19937(1234)));

        $scenario = $generator->scenario();

        self::assertSame('SRC', $scenario['source']);
        self::assertSame('DST', $scenario['target']);
        self::assertCount(5, $scenario['orders']);
        self::assertSame(4, $scenario['maxHops']);
        self::assertSame(1, $scenario['topK']);
        self::assertSame('0.01', $scenario['tolerance']);

        $firstOrder = $scenario['orders'][0];
        self::assertSame(OrderSide::BUY, $firstOrder->side());
        self::assertSame('SRC', $firstOrder->assetPair()->base());
        self::assertSame('AAA', $firstOrder->assetPair()->quote());
        self::assertSame('1.773', $firstOrder->bounds()->min()->amount());
        self::assertSame('6.206', $firstOrder->bounds()->max()->amount());
        self::assertSame('196.978', $firstOrder->effectiveRate()->rate());
        self::assertNull($firstOrder->feePolicy());

        $lastOrder = $scenario['orders'][4];
        self::assertSame(OrderSide::BUY, $lastOrder->side());
        self::assertSame('AAD', $lastOrder->assetPair()->base());
        self::assertSame('DST', $lastOrder->assetPair()->quote());
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
        self::assertSame('0.20', $generator->toleranceChoice(4));
        self::assertSame('0.05', $generator->toleranceChoice(7));
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

        for ($iteration = 0; $iteration < 25; ++$iteration) {
            $scenario = $generator->scenario();

            self::assertNotEmpty($scenario['orders']);
            self::assertGreaterThanOrEqual(1, $scenario['maxHops']);
            self::assertLessThanOrEqual(6, $scenario['maxHops']);
            self::assertGreaterThanOrEqual(1, $scenario['topK']);
            self::assertLessThanOrEqual(5, $scenario['topK']);

            $referencesSource = false;
            $referencesTarget = false;

            foreach ($scenario['orders'] as $order) {
                if ('SRC' === $order->assetPair()->base() || 'SRC' === $order->assetPair()->quote()) {
                    $referencesSource = true;
                }

                if ('DST' === $order->assetPair()->base() || 'DST' === $order->assetPair()->quote()) {
                    $referencesTarget = true;
                }
            }

            self::assertTrue($referencesSource, 'Scenario must connect to the source asset.');
            self::assertTrue($referencesTarget, 'Scenario must connect to the target asset.');
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
