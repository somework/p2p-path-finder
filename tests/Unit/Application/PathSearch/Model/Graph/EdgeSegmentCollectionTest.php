<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Model\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeSegmentCollection;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function iterator_to_array;

#[CoversClass(EdgeSegmentCollection::class)]
final class EdgeSegmentCollectionTest extends TestCase
{
    public function test_it_knows_when_it_is_empty(): void
    {
        $collection = EdgeSegmentCollection::empty();

        self::assertTrue($collection->isEmpty());
        self::assertSame(0, $collection->count());
        self::assertSame([], $collection->toArray());
        self::assertSame([], iterator_to_array($collection));
    }

    public function test_it_can_be_created_from_a_list_of_segments(): void
    {
        $first = $this->createSegment(true, '1', '2');
        $second = $this->createSegment(false, '2', '3');

        $collection = EdgeSegmentCollection::fromArray([$first, $second]);

        self::assertFalse($collection->isEmpty());
        self::assertCount(2, $collection);
        self::assertSame([$first, $second], $collection->toArray());
        self::assertSame([$first, $second], iterator_to_array($collection));
    }

    public function test_capacity_totals_returns_zero_totals_when_no_mandatory_segments(): void
    {
        $optionalSegment = $this->createSegment(false, '1', '2');
        $collection = EdgeSegmentCollection::fromArray([$optionalSegment]);

        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_BASE, 2);

        self::assertNotNull($totals);
        self::assertTrue($totals->mandatory()->equals(Money::zero('USD', 2))); // No mandatory capacity
        self::assertTrue(
            $totals->maximum()->equals(Money::fromString('USD', '2.00', 2))
        ); // But maximum includes optional
    }

    public function test_capacity_totals_returns_zero_totals_when_no_maximum_capacity(): void
    {
        $segment = $this->createSegment(true, '0', '0'); // zero capacity
        $collection = EdgeSegmentCollection::fromArray([$segment]);

        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_BASE, 2);

        self::assertNotNull($totals);
        self::assertTrue($totals->mandatory()->equals(Money::zero('USD', 2))); // No actual capacity
        self::assertTrue($totals->maximum()->equals(Money::zero('USD', 2))); // No actual capacity
    }

    public function test_capacity_totals_calculates_correct_totals_for_base_measure(): void
    {
        $mandatorySegment = $this->createSegment(true, '1', '3'); // min: 1, max: 3
        $optionalSegment = $this->createSegment(false, '2', '4'); // min: 2, max: 4

        $collection = EdgeSegmentCollection::fromArray([$mandatorySegment, $optionalSegment]);

        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_BASE, 2);

        self::assertNotNull($totals);
        self::assertTrue($totals->mandatory()->equals(Money::fromString('USD', '1.00', 2)));
        self::assertTrue($totals->maximum()->equals(Money::fromString('USD', '7.00', 2))); // 3 + 4
    }

    public function test_capacity_totals_calculates_correct_totals_for_quote_measure(): void
    {
        $mandatorySegment = $this->createSegment(true, '1', '3');
        $optionalSegment = $this->createSegment(false, '2', '4');

        $collection = EdgeSegmentCollection::fromArray([$mandatorySegment, $optionalSegment]);

        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_QUOTE, 2);

        self::assertNotNull($totals);
        self::assertTrue($totals->mandatory()->equals(Money::fromString('EUR', '1.00', 2)));
        self::assertTrue($totals->maximum()->equals(Money::fromString('EUR', '7.00', 2)));
    }

    public function test_capacity_totals_calculates_correct_totals_for_gross_base_measure(): void
    {
        $mandatorySegment = $this->createSegment(true, '1', '3');
        $optionalSegment = $this->createSegment(false, '2', '4');

        $collection = EdgeSegmentCollection::fromArray([$mandatorySegment, $optionalSegment]);

        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_GROSS_BASE, 2);

        self::assertNotNull($totals);
        self::assertTrue($totals->mandatory()->equals(Money::fromString('USD', '1.00', 2)));
        self::assertTrue($totals->maximum()->equals(Money::fromString('USD', '7.00', 2)));
    }

    public function test_capacity_totals_handles_multiple_mandatory_segments(): void
    {
        $firstMandatory = $this->createSegment(true, '1', '3');
        $secondMandatory = $this->createSegment(true, '2', '4');
        $optional = $this->createSegment(false, '1', '2');

        $collection = EdgeSegmentCollection::fromArray([$firstMandatory, $secondMandatory, $optional]);

        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_BASE, 2);

        self::assertNotNull($totals);
        self::assertTrue($totals->mandatory()->equals(Money::fromString('USD', '3.00', 2))); // 1 + 2
        self::assertTrue($totals->maximum()->equals(Money::fromString('USD', '9.00', 2))); // 3 + 4 + 2
    }

    public function test_capacity_totals_returns_correct_scale(): void
    {
        $segment = $this->createSegment(true, '1', '3');
        $collection = EdgeSegmentCollection::fromArray([$segment]);

        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_BASE, 4); // request higher scale

        self::assertNotNull($totals);
        self::assertSame(4, $totals->mandatory()->scale());
        self::assertSame(4, $totals->maximum()->scale());
    }

    public function test_capacity_scale_returns_correct_scale(): void
    {
        $segment = $this->createSegment(true, '1', '3'); // scale 0 in createSegment
        $collection = EdgeSegmentCollection::fromArray([$segment]);

        self::assertSame(0, $collection->capacityScale(EdgeSegmentCollection::MEASURE_BASE));
        self::assertSame(0, $collection->capacityScale(EdgeSegmentCollection::MEASURE_QUOTE));
        self::assertSame(0, $collection->capacityScale(EdgeSegmentCollection::MEASURE_GROSS_BASE));
    }

    public function test_capacity_scale_handles_different_scales(): void
    {
        $segment1 = $this->createSegmentWithScale(true, '1', '3', 2);
        $segment2 = $this->createSegmentWithScale(false, '2', '4', 4);

        $collection = EdgeSegmentCollection::fromArray([$segment1, $segment2]);

        // Should use the maximum scale (4)
        self::assertSame(4, $collection->capacityScale(EdgeSegmentCollection::MEASURE_BASE));
    }

    public function test_capacity_totals_rejects_unsupported_measure(): void
    {
        $collection = EdgeSegmentCollection::empty();

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Unsupported segment capacity measure "invalid".');

        $collection->capacityTotals('invalid', 2);
    }

    public function test_capacity_scale_rejects_unsupported_measure(): void
    {
        $collection = EdgeSegmentCollection::empty();

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Unsupported segment capacity measure "invalid".');

        $collection->capacityScale('invalid');
    }

    public function test_empty_collection_capacity_operations(): void
    {
        $collection = EdgeSegmentCollection::empty();

        foreach (EdgeSegmentCollection::MEASURES as $measure) {
            self::assertNull($collection->capacityTotals($measure, 2));
            self::assertSame(0, $collection->capacityScale($measure));
        }
    }

    public function test_capacity_totals_with_only_optional_segments(): void
    {
        $segment1 = $this->createSegment(false, '1', '3');
        $segment2 = $this->createSegment(false, '2', '4');

        $collection = EdgeSegmentCollection::fromArray([$segment1, $segment2]);

        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_BASE, 2);

        self::assertNotNull($totals);
        self::assertTrue($totals->mandatory()->equals(Money::zero('USD', 2))); // No mandatory capacity
        self::assertTrue(
            $totals->maximum()->equals(Money::fromString('USD', '7.00', 2))
        ); // Maximum includes optional: 3 + 4
    }

    public function test_constants_are_defined_correctly(): void
    {
        self::assertSame(['base', 'quote', 'grossBase'], EdgeSegmentCollection::MEASURES);
        self::assertSame('base', EdgeSegmentCollection::MEASURE_BASE);
        self::assertSame('quote', EdgeSegmentCollection::MEASURE_QUOTE);
        self::assertSame('grossBase', EdgeSegmentCollection::MEASURE_GROSS_BASE);
    }

    public function test_from_array_rejects_mixed_currencies_in_same_measure(): void
    {
        // Test what happens when segments have different currencies for the same measure
        // This should fail because Money::add requires same currencies
        $segment1 = $this->createSegmentWithMixedCurrencies(true, 'USD', '1', '3');
        $segment2 = $this->createSegmentWithMixedCurrencies(false, 'EUR', '2', '4'); // Different currency

        $this->expectException(InvalidInput::class); // Should fail due to currency mismatch in Money::add

        EdgeSegmentCollection::fromArray([$segment1, $segment2]);
    }

    public function test_capacity_totals_with_all_zero_capacities(): void
    {
        $segment1 = $this->createSegment(true, '0', '0');
        $segment2 = $this->createSegment(false, '0', '0');

        $collection = EdgeSegmentCollection::fromArray([$segment1, $segment2]);

        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_BASE, 2);

        self::assertNotNull($totals);
        self::assertTrue($totals->mandatory()->equals(Money::zero('USD', 2))); // No actual capacity
        self::assertTrue($totals->maximum()->equals(Money::zero('USD', 2))); // No actual capacity
    }

    public function test_capacity_scale_with_mixed_scales(): void
    {
        $segment1 = $this->createSegmentWithScale(true, '1', '3', 2);
        $segment2 = $this->createSegmentWithScale(false, '2', '4', 4);
        $segment3 = $this->createSegmentWithScale(true, '1', '2', 6);

        $collection = EdgeSegmentCollection::fromArray([$segment1, $segment2, $segment3]);

        // Should use the maximum scale encountered (6)
        self::assertSame(6, $collection->capacityScale(EdgeSegmentCollection::MEASURE_BASE));
        self::assertSame(6, $collection->capacityScale(EdgeSegmentCollection::MEASURE_QUOTE));
        self::assertSame(6, $collection->capacityScale(EdgeSegmentCollection::MEASURE_GROSS_BASE));
    }

    public function test_capacity_totals_scale_adjustment(): void
    {
        $segment = $this->createSegmentWithScale(true, '1', '3', 2);
        $collection = EdgeSegmentCollection::fromArray([$segment]);

        // Request scale higher than stored scale
        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_BASE, 4);
        self::assertNotNull($totals);
        self::assertSame(4, $totals->mandatory()->scale());
        self::assertSame(4, $totals->maximum()->scale());

        // Request scale lower than stored scale
        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_BASE, 0);
        self::assertNotNull($totals);
        self::assertSame(2, $totals->mandatory()->scale()); // Uses max(requested, stored)
        self::assertSame(2, $totals->maximum()->scale());
    }

    public function test_capacity_totals_comprehensive_all_measures(): void
    {
        $mandatorySegment = $this->createSegment(true, '10', '20');
        $optionalSegment = $this->createSegment(false, '5', '15');

        $collection = EdgeSegmentCollection::fromArray([$mandatorySegment, $optionalSegment]);

        // Test base measure (USD)
        $baseTotals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_BASE, 2);
        self::assertNotNull($baseTotals);
        self::assertTrue($baseTotals->mandatory()->equals(Money::fromString('USD', '10.00', 2)));
        self::assertTrue($baseTotals->maximum()->equals(Money::fromString('USD', '35.00', 2)));

        // Test quote measure (EUR)
        $quoteTotals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_QUOTE, 2);
        self::assertNotNull($quoteTotals);
        self::assertTrue($quoteTotals->mandatory()->equals(Money::fromString('EUR', '10.00', 2)));
        self::assertTrue($quoteTotals->maximum()->equals(Money::fromString('EUR', '35.00', 2)));

        // Test grossBase measure (USD)
        $grossBaseTotals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_GROSS_BASE, 2);
        self::assertNotNull($grossBaseTotals);
        self::assertTrue($grossBaseTotals->mandatory()->equals(Money::fromString('USD', '10.00', 2)));
        self::assertTrue($grossBaseTotals->maximum()->equals(Money::fromString('USD', '35.00', 2)));
    }

    public function test_from_array_with_empty_array(): void
    {
        $collection = EdgeSegmentCollection::fromArray([]);

        self::assertTrue($collection->isEmpty());
        self::assertSame(0, $collection->count());
        self::assertSame([], $collection->toArray());
        self::assertSame([], iterator_to_array($collection));
    }

    public function test_iterator_behavior(): void
    {
        $segment1 = $this->createSegment(true, '1', '2');
        $segment2 = $this->createSegment(false, '3', '4');

        $collection = EdgeSegmentCollection::fromArray([$segment1, $segment2]);
        $iterated = [];

        foreach ($collection as $segment) {
            $iterated[] = $segment;
        }

        self::assertSame([$segment1, $segment2], $iterated);
    }

    public function test_capacity_totals_handles_mixed_currencies_across_different_measures(): void
    {
        // Test that different measures can have different currencies
        $segment = $this->createSegmentWithMixedCurrenciesForDifferentMeasures();

        $collection = EdgeSegmentCollection::fromArray([$segment]);

        // Base should use USD, Quote should use EUR (from the segment)
        $baseTotals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_BASE, 2);
        self::assertNotNull($baseTotals);
        self::assertSame('USD', $baseTotals->mandatory()->currency());

        $quoteTotals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_QUOTE, 2);
        self::assertNotNull($quoteTotals);
        self::assertSame('EUR', $quoteTotals->mandatory()->currency());
    }

    public function test_capacity_scale_handles_empty_collection(): void
    {
        $collection = EdgeSegmentCollection::empty();

        // Empty collection should return 0 for scale (initialized value)
        self::assertSame(0, $collection->capacityScale(EdgeSegmentCollection::MEASURE_BASE));
        self::assertSame(0, $collection->capacityScale(EdgeSegmentCollection::MEASURE_QUOTE));
        self::assertSame(0, $collection->capacityScale(EdgeSegmentCollection::MEASURE_GROSS_BASE));
    }

    public function test_capacity_totals_with_extreme_scale_differences(): void
    {
        $segment = $this->createSegmentWithExtremeScaleDifference();
        $collection = EdgeSegmentCollection::fromArray([$segment]);

        // Should handle scale differences gracefully
        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_BASE, 10);
        self::assertNotNull($totals);
        self::assertSame(10, $totals->mandatory()->scale()); // Requested scale wins
    }

    public function test_from_array_preserves_segment_identity(): void
    {
        $segment1 = $this->createSegment(true, '1', '2');
        $segment2 = $this->createSegment(false, '3', '4');

        $collection = EdgeSegmentCollection::fromArray([$segment1, $segment2]);
        $segments = $collection->toArray();

        // Should return same instances
        self::assertSame($segment1, $segments[0]);
        self::assertSame($segment2, $segments[1]);
    }

    public function test_capacity_totals_with_minimal_scale_request(): void
    {
        $segment = $this->createSegment(true, '1', '2'); // Creates with scale 0
        $collection = EdgeSegmentCollection::fromArray([$segment]);

        // Request scale smaller than stored scale
        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_BASE, 0);
        self::assertNotNull($totals);
        self::assertSame(0, $totals->mandatory()->scale()); // Uses stored scale (max of 0 and 0)
    }

    public function test_it_rejects_non_list_input(): void
    {
        $segment = $this->createSegment(true, '1', '2');

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Graph edge segments must be provided as a list.');

        EdgeSegmentCollection::fromArray(['segment' => $segment]);
    }

    public function test_it_rejects_non_edge_segment(): void
    {
        $segment = $this->createSegment(true, '1', '2');

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Graph edge segments must be instances of EdgeSegment.');

        EdgeSegmentCollection::fromArray([$segment, 'not-a-segment']);
    }

    public function test_capacity_totals_with_all_mandatory_segments(): void
    {
        // Edge with only mandatory segment (no optional headroom)
        $mandatorySegment = $this->createSegment(true, '100', '100');
        $collection = EdgeSegmentCollection::fromArray([$mandatorySegment]);

        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_QUOTE, 3);
        self::assertNotNull($totals);
        self::assertSame('100.000', $totals->mandatory()->amount());
        self::assertSame('100.000', $totals->maximum()->amount());
        self::assertSame('0.000', $totals->optionalHeadroom()->amount(), 'All-mandatory should have zero headroom');
    }

    public function test_capacity_totals_with_mixed_mandatory_and_optional_segments(): void
    {
        // Edge with mandatory + optional segments
        $mandatorySegment = $this->createSegment(true, '100', '100');
        $optionalSegment = $this->createSegment(false, '0', '400');

        $collection = EdgeSegmentCollection::fromArray([$mandatorySegment, $optionalSegment]);

        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_QUOTE, 3);
        self::assertNotNull($totals);
        self::assertSame('100.000', $totals->mandatory()->amount());
        self::assertSame('500.000', $totals->maximum()->amount()); // 100 + 400
        self::assertSame('400.000', $totals->optionalHeadroom()->amount(), 'Mixed should have positive headroom');
    }

    public function test_capacity_totals_with_zero_mandatory_capacity(): void
    {
        // Edge with only optional segments (no mandatory minimum)
        $optionalSegment = $this->createSegment(false, '0', '500');
        $collection = EdgeSegmentCollection::fromArray([$optionalSegment]);

        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_QUOTE, 3);
        self::assertNotNull($totals);
        self::assertSame('0.000', $totals->mandatory()->amount(), 'All-optional should have zero mandatory');
        self::assertSame('500.000', $totals->maximum()->amount());
        self::assertSame('500.000', $totals->optionalHeadroom()->amount(), 'Headroom should equal maximum when mandatory=0');
    }

    public function test_capacity_totals_with_multiple_mandatory_segments(): void
    {
        // Edge with multiple mandatory segments (unusual but should be handled)
        $firstMandatory = $this->createSegment(true, '50', '50');
        $secondMandatory = $this->createSegment(true, '30', '30');
        $optional = $this->createSegment(false, '0', '100');

        $collection = EdgeSegmentCollection::fromArray([$firstMandatory, $secondMandatory, $optional]);

        $totals = $collection->capacityTotals(EdgeSegmentCollection::MEASURE_QUOTE, 3);
        self::assertNotNull($totals);

        // Mandatory = sum of all mandatory mins = 50 + 30 = 80
        self::assertSame('80.000', $totals->mandatory()->amount());

        // Maximum = sum of all maxes = 50 + 30 + 100 = 180
        self::assertSame('180.000', $totals->maximum()->amount());

        // Headroom = 180 - 80 = 100
        self::assertSame('100.000', $totals->optionalHeadroom()->amount());
    }

    private function createSegmentWithScale(
        bool $isMandatory,
        string $minAmount,
        string $maxAmount,
        int $scale
    ): EdgeSegment {
        return new EdgeSegment(
            $isMandatory,
            new EdgeCapacity(
                Money::fromString('USD', $minAmount, $scale),
                Money::fromString('USD', $maxAmount, $scale),
            ),
            new EdgeCapacity(
                Money::fromString('EUR', $minAmount, $scale),
                Money::fromString('EUR', $maxAmount, $scale),
            ),
            new EdgeCapacity(
                Money::fromString('USD', $minAmount, $scale),
                Money::fromString('USD', $maxAmount, $scale),
            ),
        );
    }

    private function createSegmentWithMixedCurrencies(
        bool $isMandatory,
        string $currency,
        string $minAmount,
        string $maxAmount
    ): EdgeSegment {
        return new EdgeSegment(
            $isMandatory,
            new EdgeCapacity(
                Money::fromString($currency, $minAmount, 2),
                Money::fromString($currency, $maxAmount, 2),
            ),
            new EdgeCapacity(
                Money::fromString('EUR', $minAmount, 2), // Always EUR for quote
                Money::fromString('EUR', $maxAmount, 2),
            ),
            new EdgeCapacity(
                Money::fromString($currency, $minAmount, 2),
                Money::fromString($currency, $maxAmount, 2),
            ),
        );
    }

    private function createSegmentWithMixedCurrenciesForDifferentMeasures(): EdgeSegment
    {
        return new EdgeSegment(
            true,
            new EdgeCapacity( // base - USD
                Money::fromString('USD', '1.00', 2),
                Money::fromString('USD', '2.00', 2),
            ),
            new EdgeCapacity( // quote - EUR
                Money::fromString('EUR', '0.90', 2),
                Money::fromString('EUR', '1.80', 2),
            ),
            new EdgeCapacity( // grossBase - USD
                Money::fromString('USD', '1.10', 2),
                Money::fromString('USD', '2.20', 2),
            ),
        );
    }

    private function createSegmentWithExtremeScaleDifference(): EdgeSegment
    {
        return new EdgeSegment(
            true,
            new EdgeCapacity(
                Money::fromString('USD', '1.0000000000', 10),
                Money::fromString('USD', '2.0000000000', 10),
            ),
            new EdgeCapacity(
                Money::fromString('EUR', '0.9000000000', 10),
                Money::fromString('EUR', '1.8000000000', 10),
            ),
            new EdgeCapacity(
                Money::fromString('USD', '1.1000000000', 10),
                Money::fromString('USD', '2.2000000000', 10),
            ),
        );
    }

    private function createSegment(bool $isMandatory, string $minAmount, string $maxAmount): EdgeSegment
    {
        return new EdgeSegment(
            $isMandatory,
            new EdgeCapacity(
                Money::fromString('USD', $minAmount, 0),
                Money::fromString('USD', $maxAmount, 0),
            ),
            new EdgeCapacity(
                Money::fromString('EUR', $minAmount, 0),
                Money::fromString('EUR', $maxAmount, 0),
            ),
            new EdgeCapacity(
                Money::fromString('USD', $minAmount, 0),
                Money::fromString('USD', $maxAmount, 0),
            ),
        );
    }
}
