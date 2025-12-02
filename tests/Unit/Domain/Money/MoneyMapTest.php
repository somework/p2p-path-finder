<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Domain\Money;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

#[CoversClass(MoneyMap::class)]
final class MoneyMapTest extends TestCase
{
    public function test_it_allows_access_by_currency_code(): void
    {
        $map = MoneyMap::fromList([Money::fromString('USD', '1', 2)]);

        self::assertTrue($map->has('USD'));
        $usd = $map->get('USD');
        self::assertNotNull($usd);
        self::assertSame('USD', $usd->currency());
    }

    public function test_it_returns_null_for_unknown_currency(): void
    {
        $map = MoneyMap::fromList([Money::fromString('USD', '1', 2)]);

        self::assertFalse($map->has('EUR'));
        self::assertNull($map->get('EUR'));
    }

    public function test_json_serialization_preserves_normalized_strings(): void
    {
        $map = MoneyMap::fromList([
            Money::fromString('usd', '0.500', 3),
            Money::fromString('eur', '0.12345', 5),
            Money::fromString('usd', '1.25', 2),
            Money::fromString('eur', '0.87655', 5),
        ]);

        self::assertSame(
            [
                'EUR' => ['currency' => 'EUR', 'amount' => '1.00000', 'scale' => 5],
                'USD' => ['currency' => 'USD', 'amount' => '1.750', 'scale' => 3],
            ],
            $map->jsonSerialize(),
        );
    }

    public function test_empty_creates_empty_map(): void
    {
        $map = MoneyMap::empty();

        self::assertTrue($map->isEmpty());
        self::assertSame(0, $map->count());
        self::assertSame([], $map->toArray());
        self::assertSame([], $map->jsonSerialize());
    }

    public function test_from_list_with_empty_iterable(): void
    {
        $map = MoneyMap::fromList([]);

        self::assertTrue($map->isEmpty());
        self::assertSame(0, $map->count());
    }

    public function test_from_list_with_single_item(): void
    {
        $money = Money::fromString('USD', '100.00', 2);
        $map = MoneyMap::fromList([$money]);

        self::assertFalse($map->isEmpty());
        self::assertSame(1, $map->count());
        self::assertTrue($map->has('USD'));
        self::assertSame($money, $map->get('USD'));
    }

    public function test_from_list_merges_same_currency(): void
    {
        $money1 = Money::fromString('USD', '100.00', 2);
        $money2 = Money::fromString('USD', '50.00', 2);
        $map = MoneyMap::fromList([$money1, $money2]);

        self::assertSame(1, $map->count());
        self::assertTrue($map->has('USD'));
        $merged = $map->get('USD');
        self::assertNotNull($merged);
        self::assertSame('150.00', $merged->amount());
        self::assertSame('USD', $merged->currency());
        self::assertSame(2, $merged->scale());
    }

    public function test_from_list_preserves_different_currencies(): void
    {
        $usd = Money::fromString('USD', '100.00', 2);
        $eur = Money::fromString('EUR', '200.00', 2);
        $map = MoneyMap::fromList([$usd, $eur]);

        self::assertSame(2, $map->count());
        self::assertTrue($map->has('USD'));
        self::assertTrue($map->has('EUR'));
        self::assertSame($usd, $map->get('USD'));
        self::assertSame($eur, $map->get('EUR'));
    }

    public function test_from_list_with_iterator(): void
    {
        $moneys = [
            Money::fromString('USD', '100.00', 2),
            Money::fromString('EUR', '200.00', 2),
        ];

        $map = MoneyMap::fromList(new \ArrayIterator($moneys));

        self::assertSame(2, $map->count());
        self::assertTrue($map->has('USD'));
        self::assertTrue($map->has('EUR'));
    }

    public function test_from_list_with_generator(): void
    {
        $generator = static function () {
            yield Money::fromString('USD', '100.00', 2);
            yield Money::fromString('EUR', '200.00', 2);
        };

        $map = MoneyMap::fromList($generator());

        self::assertSame(2, $map->count());
        self::assertTrue($map->has('USD'));
        self::assertTrue($map->has('EUR'));
    }

    public function test_from_list_skip_zero_values(): void
    {
        $zero = Money::fromString('USD', '0.00', 2);
        $nonZero = Money::fromString('EUR', '100.00', 2);
        $map = MoneyMap::fromList([$zero, $nonZero], true);

        self::assertSame(1, $map->count());
        self::assertFalse($map->has('USD'));
        self::assertTrue($map->has('EUR'));
    }

    public function test_from_list_includes_zero_values_by_default(): void
    {
        $zero = Money::fromString('USD', '0.00', 2);
        $nonZero = Money::fromString('EUR', '100.00', 2);
        $map = MoneyMap::fromList([$zero, $nonZero]);

        self::assertSame(2, $map->count());
        self::assertTrue($map->has('USD'));
        self::assertTrue($map->has('EUR'));
    }

    public function test_from_associative_alias(): void
    {
        $usd = Money::fromString('USD', '100.00', 2);
        $eur = Money::fromString('EUR', '200.00', 2);

        $map1 = MoneyMap::fromList([$usd, $eur]);
        $map2 = MoneyMap::fromAssociative([$usd, $eur]);

        self::assertSame($map1->toArray(), $map2->toArray());
    }

    public function test_from_list_rejects_non_money_objects(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money map entries must be instances of Money.');

        MoneyMap::fromList(['not a money object']);
    }

    public function test_currency_case_sensitivity(): void
    {
        $usdLower = Money::fromString('usd', '100.00', 2);
        $usdUpper = Money::fromString('USD', '50.00', 2);
        $map = MoneyMap::fromList([$usdLower, $usdUpper]);

        // Money objects are normalized to uppercase currencies
        self::assertTrue($map->has('USD'));
        self::assertFalse($map->has('usd'));
        self::assertSame('150.00', $map->get('USD')?->amount());
    }

    public function test_iterator_functionality(): void
    {
        $usd = Money::fromString('USD', '100.00', 2);
        $eur = Money::fromString('EUR', '200.00', 2);
        $map = MoneyMap::fromList([$usd, $eur]);

        $currencies = [];
        $amounts = [];

        foreach ($map as $currency => $money) {
            $currencies[] = $currency;
            $amounts[] = $money->amount();
        }

        // Should be sorted by currency code
        self::assertSame(['EUR', 'USD'], $currencies);
        self::assertSame(['200.00', '100.00'], $amounts);
    }

    public function test_to_array_returns_internal_structure(): void
    {
        $usd = Money::fromString('USD', '100.00', 2);
        $eur = Money::fromString('EUR', '200.00', 2);
        $map = MoneyMap::fromList([$eur, $usd]); // Insert in reverse order

        $array = $map->toArray();

        // Should be sorted by currency
        self::assertSame(['EUR', 'USD'], array_keys($array));
        self::assertSame($eur, $array['EUR']);
        self::assertSame($usd, $array['USD']);
    }

    public function test_with_adds_new_currency(): void
    {
        $map = MoneyMap::empty();
        $usd = Money::fromString('USD', '100.00', 2);

        $newMap = $map->with($usd);

        self::assertTrue($map->isEmpty());
        self::assertFalse($newMap->isEmpty());
        self::assertSame(1, $newMap->count());
        self::assertTrue($newMap->has('USD'));
        self::assertSame($usd, $newMap->get('USD'));
    }

    public function test_with_merges_existing_currency(): void
    {
        $usd1 = Money::fromString('USD', '100.00', 2);
        $map = MoneyMap::fromList([$usd1]);

        $usd2 = Money::fromString('USD', '50.00', 2);
        $newMap = $map->with($usd2);

        self::assertSame(1, $newMap->count());
        $merged = $newMap->get('USD');
        self::assertNotNull($merged);
        self::assertSame('150.00', $merged->amount());
    }

    public function test_with_skip_zero_value(): void
    {
        $map = MoneyMap::empty();
        $zero = Money::fromString('USD', '0.00', 2);

        $newMap = $map->with($zero, true);

        self::assertTrue($newMap->isEmpty());
        self::assertFalse($newMap->has('USD'));
    }

    public function test_with_includes_zero_value_by_default(): void
    {
        $map = MoneyMap::empty();
        $zero = Money::fromString('USD', '0.00', 2);

        $newMap = $map->with($zero);

        self::assertFalse($newMap->isEmpty());
        self::assertTrue($newMap->has('USD'));
        self::assertSame($zero, $newMap->get('USD'));
    }

    public function test_merge_with_empty_map(): void
    {
        $usd = Money::fromString('USD', '100.00', 2);
        $map1 = MoneyMap::fromList([$usd]);
        $map2 = MoneyMap::empty();

        $merged = $map1->merge($map2);

        self::assertSame($map1, $merged);
        self::assertSame($usd, $merged->get('USD'));
    }

    public function test_merge_empty_map_with_populated(): void
    {
        $map1 = MoneyMap::empty();
        $usd = Money::fromString('USD', '100.00', 2);
        $map2 = MoneyMap::fromList([$usd]);

        $merged = $map1->merge($map2);

        // Compare by properties, not object identity
        self::assertSame($map2->count(), $merged->count());
        self::assertTrue($merged->has('USD'));
        $mergedUsd = $merged->get('USD');
        self::assertNotNull($mergedUsd);
        self::assertSame($usd->amount(), $mergedUsd->amount());
        self::assertSame($usd->currency(), $mergedUsd->currency());
        self::assertSame($usd->scale(), $mergedUsd->scale());
    }

    public function test_merge_non_overlapping_currencies(): void
    {
        $usd = Money::fromString('USD', '100.00', 2);
        $eur = Money::fromString('EUR', '200.00', 2);

        $map1 = MoneyMap::fromList([$usd]);
        $map2 = MoneyMap::fromList([$eur]);

        $merged = $map1->merge($map2);

        self::assertSame(2, $merged->count());
        self::assertTrue($merged->has('USD'));
        self::assertTrue($merged->has('EUR'));
        self::assertSame($usd, $merged->get('USD'));
        self::assertSame($eur, $merged->get('EUR'));
    }

    public function test_merge_overlapping_currencies(): void
    {
        $usd1 = Money::fromString('USD', '100.00', 2);
        $usd2 = Money::fromString('USD', '50.00', 2);
        $eur = Money::fromString('EUR', '200.00', 2);

        $map1 = MoneyMap::fromList([$usd1, $eur]);
        $map2 = MoneyMap::fromList([$usd2]);

        $merged = $map1->merge($map2);

        self::assertSame(2, $merged->count());
        self::assertTrue($merged->has('USD'));
        self::assertTrue($merged->has('EUR'));

        $mergedUsd = $merged->get('USD');
        self::assertNotNull($mergedUsd);
        self::assertSame('150.00', $mergedUsd->amount());

        self::assertSame($eur, $merged->get('EUR'));
    }

    public function test_merge_preserves_sorting(): void
    {
        $btc = Money::fromString('BTC', '1.00000000', 8);
        $usd = Money::fromString('USD', '100.00', 2);
        $eur = Money::fromString('EUR', '200.00', 2);

        $map1 = MoneyMap::fromList([$usd]);
        $map2 = MoneyMap::fromList([$eur, $btc]);

        $merged = $map1->merge($map2);

        $array = $merged->toArray();
        $keys = array_keys($array);

        // Should be sorted: BTC, EUR, USD
        self::assertSame(['BTC', 'EUR', 'USD'], $keys);
    }

    public function test_complex_merging_scenario(): void
    {
        // Create initial map
        $initial = MoneyMap::fromList([
            Money::fromString('USD', '100.00', 2),
            Money::fromString('EUR', '50.00', 2),
        ]);

        // Add more currencies
        $step1 = $initial->with(Money::fromString('GBP', '75.00', 2));
        $step2 = $step1->with(Money::fromString('USD', '25.00', 2)); // Merge USD

        // Merge with another map
        $other = MoneyMap::fromList([
            Money::fromString('EUR', '25.00', 2), // Merge EUR
            Money::fromString('JPY', '1000', 0),  // New currency
        ]);

        $final = $step2->merge($other);

        self::assertSame(4, $final->count());
        self::assertSame('125.00', $final->get('USD')?->amount()); // 100 + 25
        self::assertSame('75.00', $final->get('EUR')?->amount());  // 50 + 25
        self::assertSame('75.00', $final->get('GBP')?->amount());  // 75
        self::assertSame('1000', $final->get('JPY')?->amount());   // 1000
    }

    public function test_json_serialize_empty_map(): void
    {
        $map = MoneyMap::empty();

        self::assertSame([], $map->jsonSerialize());
    }

    public function test_json_serialize_single_currency(): void
    {
        $map = MoneyMap::fromList([Money::fromString('USD', '123.45', 2)]);

        $expected = [
            'USD' => [
                'currency' => 'USD',
                'amount' => '123.45',
                'scale' => 2,
            ],
        ];

        self::assertSame($expected, $map->jsonSerialize());
    }

    public function test_json_serialize_multiple_currencies(): void
    {
        $map = MoneyMap::fromList([
            Money::fromString('EUR', '100.50', 2),
            Money::fromString('USD', '200.75', 2),
            Money::fromString('GBP', '50.25', 2),
        ]);

        $serialized = $map->jsonSerialize();

        self::assertCount(3, $serialized);
        self::assertArrayHasKey('EUR', $serialized);
        self::assertArrayHasKey('USD', $serialized);
        self::assertArrayHasKey('GBP', $serialized);

        // Check each currency serialization
        self::assertSame([
            'currency' => 'EUR',
            'amount' => '100.50',
            'scale' => 2,
        ], $serialized['EUR']);

        self::assertSame([
            'currency' => 'USD',
            'amount' => '200.75',
            'scale' => 2,
        ], $serialized['USD']);

        self::assertSame([
            'currency' => 'GBP',
            'amount' => '50.25',
            'scale' => 2,
        ], $serialized['GBP']);
    }

    public function test_json_serialize_with_merging(): void
    {
        $map = MoneyMap::fromList([
            Money::fromString('USD', '100.00', 2),
            Money::fromString('USD', '50.00', 2), // Should merge to 150.00
            Money::fromString('EUR', '75.00', 2),
        ]);

        $serialized = $map->jsonSerialize();

        self::assertSame([
            'EUR' => [
                'currency' => 'EUR',
                'amount' => '75.00',
                'scale' => 2,
            ],
            'USD' => [
                'currency' => 'USD',
                'amount' => '150.00',
                'scale' => 2,
            ],
        ], $serialized);
    }

    public function test_very_large_amounts(): void
    {
        $largeAmount = '999999999999999999.99';
        $map = MoneyMap::fromList([
            Money::fromString('USD', $largeAmount, 2),
        ]);

        self::assertSame(1, $map->count());
        self::assertTrue($map->has('USD'));
        $money = $map->get('USD');
        self::assertNotNull($money);
        self::assertSame($largeAmount, $money->amount());
    }

    public function test_very_small_amounts(): void
    {
        $smallAmount = '0.000000000000000001';
        $map = MoneyMap::fromList([
            Money::fromString('BTC', $smallAmount, 18),
        ]);

        self::assertSame(1, $map->count());
        self::assertTrue($map->has('BTC'));
        $money = $map->get('BTC');
        self::assertNotNull($money);
        self::assertSame($smallAmount, $money->amount());
        self::assertSame(18, $money->scale());
    }

    public function test_different_scales_same_currency(): void
    {
        // Test merging Money objects with different scales
        $usd1 = Money::fromString('USD', '100.00', 2);
        $usd2 = Money::fromString('USD', '50.000', 3);

        $map = MoneyMap::fromList([$usd1, $usd2]);

        self::assertSame(1, $map->count());
        $merged = $map->get('USD');
        self::assertNotNull($merged);
        self::assertSame('150.000', $merged->amount()); // Should normalize to higher precision with trailing zeros
        self::assertSame(3, $merged->scale());
    }
}
