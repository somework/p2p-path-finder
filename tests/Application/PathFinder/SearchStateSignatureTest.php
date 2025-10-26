<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateSignature;

final class SearchStateSignatureTest extends TestCase
{
    public function test_from_string_normalizes_whitespace(): void
    {
        $signature = SearchStateSignature::fromString(' range:null|desired:null ');

        self::assertSame('range:null|desired:null', $signature->value());
    }

    public function test_from_string_rejects_empty_segments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SearchStateSignature::fromString('range:null||desired:null');
    }

    public function test_from_string_rejects_leading_or_trailing_delimiters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SearchStateSignature::fromString('|range:null');
    }

    public function test_from_string_rejects_trailing_delimiter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SearchStateSignature::fromString('range:null|');
    }

    public function test_from_string_requires_values_when_separator_present(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SearchStateSignature::fromString('range:');
    }

    public function test_compose_builds_normalized_signature(): void
    {
        $signature = SearchStateSignature::compose([
            'range' => 'USD:1:2',
            'desired' => 'EUR:5:2',
        ]);

        self::assertSame('range:USD:1:2|desired:EUR:5:2', $signature->value());
    }

    public function test_compose_rejects_empty_segments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SearchStateSignature::compose(['' => 'value']);
    }

    public function test_compose_rejects_empty_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SearchStateSignature::compose(['range' => '   ']);
    }

    public function test_compose_rejects_segment_delimiter_in_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SearchStateSignature::compose(['range' => 'USD|1']);
    }

    public function test_compose_rejects_labels_with_delimiters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SearchStateSignature::compose(['ra|nge' => 'USD:1']);
    }

    public function test_equals_and_compare(): void
    {
        $alpha = SearchStateSignature::fromString('label:alpha');
        $beta = SearchStateSignature::fromString('label:beta');

        self::assertTrue($alpha->equals(SearchStateSignature::fromString('label:alpha')));
        self::assertFalse($alpha->equals($beta));
        self::assertSame(-1, $alpha->compare($beta));
        self::assertSame(1, $beta->compare($alpha));
        self::assertSame(0, $alpha->compare(SearchStateSignature::fromString('label:alpha')));
    }
}
