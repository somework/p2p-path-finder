<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Helpers\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Tests\Helpers\Generator\GraphScenarioGenerator;

final class GraphScenarioGeneratorTest extends TestCase
{
    public function test_orders_generation_is_reproducible_for_seeded_randomizer(): void
    {
        $generator = new GraphScenarioGenerator(new Randomizer(new Mt19937(123)));

        $orders = $generator->orders(4);

        self::assertCount(3, $orders);

        $first = $orders[0];
        self::assertSame(OrderSide::SELL, $first->side());
        self::assertSame('BUY', $first->assetPair()->base());
        self::assertSame('AYW', $first->assetPair()->quote());
        self::assertSame('2.193349', $first->bounds()->min()->amount());
        self::assertSame('3.100071', $first->bounds()->max()->amount());
        self::assertSame('447.321307', $first->effectiveRate()->rate());

        $last = $orders[2];
        self::assertSame(OrderSide::BUY, $last->side());
        self::assertSame('AXY', $last->assetPair()->base());
        self::assertSame('TVZ', $last->assetPair()->quote());
        self::assertSame('3.023', $last->bounds()->min()->amount());
        self::assertSame('9.036', $last->bounds()->max()->amount());
        self::assertSame('360.45730879', $last->effectiveRate()->rate());
    }

    public function test_orders_prefer_existing_currencies_when_available(): void
    {
        $generator = new GraphScenarioGenerator(new Randomizer(new Mt19937(321)));

        $orders = $generator->orders(8);

        $seen = [];
        $reused = false;
        foreach ($orders as $order) {
            $base = $order->assetPair()->base();
            $quote = $order->assetPair()->quote();
            if (isset($seen[$base]) || isset($seen[$quote])) {
                $reused = true;
            }
            $seen[$base] = true;
            $seen[$quote] = true;
        }

        self::assertTrue($reused, 'Generator should occasionally reuse previously seen currencies.');
    }

    public function test_fee_policy_generation_covers_all_variants(): void
    {
        $reflection = new ReflectionClass(GraphScenarioGenerator::class);
        $method = $reflection->getMethod('maybeFeePolicy');
        $method->setAccessible(true);

        $seeds = [1, 6, 11, 14];
        $expected = ['base', 'base_quote', 'quote_fixed', null];

        foreach ($seeds as $index => $seed) {
            $generator = new GraphScenarioGenerator(new Randomizer(new Mt19937($seed)));
            $policy = $method->invoke($generator);

            $label = $expected[$index];
            if (null === $label) {
                self::assertNull($policy);

                continue;
            }

            self::assertNotNull($policy);
            $breakdown = $policy->calculate(
                OrderSide::BUY,
                Money::fromString('AAA', '5.000', 3),
                Money::fromString('BBB', '7.500', 3),
            );

            switch ($label) {
                case 'base':
                    self::assertTrue($breakdown->hasBaseFee());
                    self::assertFalse($breakdown->hasQuoteFee());

                    break;

                case 'base_quote':
                    self::assertTrue($breakdown->hasBaseFee());
                    self::assertTrue($breakdown->hasQuoteFee());

                    break;

                case 'quote_fixed':
                    self::assertFalse($breakdown->hasBaseFee());
                    self::assertTrue($breakdown->hasQuoteFee());

                    break;

                default:
                    self::fail('Unexpected fee policy label encountered.');
            }
        }
    }

    public function test_numeric_helpers_handle_scale_and_bounds(): void
    {
        $generator = new GraphScenarioGenerator(new Randomizer(new Mt19937(7)));
        $reflection = new ReflectionClass(GraphScenarioGenerator::class);

        $randomCurrency = $reflection->getMethod('randomCurrencyCode');
        $randomCurrency->setAccessible(true);
        self::assertSame(1, preg_match('/^[A-Z]{3}$/', $randomCurrency->invoke($generator)));

        $randomAmount = $reflection->getMethod('randomAmount');
        $randomAmount->setAccessible(true);
        $amount = $randomAmount->invoke($generator, 4, true);
        self::assertSame(1, preg_match('/^\d+\.\d{4}$/', $amount));

        $greater = $reflection->getMethod('randomAmountGreaterThan');
        $greater->setAccessible(true);
        $largerAmount = $greater->invoke($generator, 3, '1.000');
        $parse = $reflection->getMethod('parseUnits');
        $parse->setAccessible(true);
        self::assertGreaterThan(
            $parse->invoke($generator, '1.000', 3),
            $parse->invoke($generator, $largerAmount, 3),
        );

        $positiveDecimal = $reflection->getMethod('randomPositiveDecimal');
        $positiveDecimal->setAccessible(true);
        self::assertSame(1, preg_match('/^\d+\.\d{5}$/', $positiveDecimal->invoke($generator, 5)));

        $powerOfTen = $reflection->getMethod('powerOfTen');
        $powerOfTen->setAccessible(true);
        self::assertSame(1, $powerOfTen->invoke($generator, 0));
        self::assertSame(1000, $powerOfTen->invoke($generator, 3));

        $formatUnits = $reflection->getMethod('formatUnits');
        $formatUnits->setAccessible(true);
        self::assertSame('42', $formatUnits->invoke($generator, 42, 0));
        self::assertSame('1.234', $formatUnits->invoke($generator, 1234, 3));
    }
}
