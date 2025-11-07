<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateSignature;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateSignatureFormatter;
use SomeWork\P2PPathFinder\Tests\Application\Support\Generator\SearchStateSignatureGenerator;

use function explode;
use function trim;

final class SearchStateSignatureTest extends TestCase
{
    public function test_from_string_normalizes_whitespace(): void
    {
        $signature = SearchStateSignature::fromString(' range:null|desired:null ');

        self::assertSame('range:null|desired:null', $signature->value());
    }

    public function test_from_string_rejects_empty_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Search state signatures cannot be empty.');

        SearchStateSignature::fromString('   ');
    }

    public function test_from_string_requires_label_separator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Search state signature segments require a label/value separator.');

        SearchStateSignature::fromString('label-without-separator');
    }

    public function test_from_string_requires_label_before_separator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Search state signature segments require a label before the separator.');

        SearchStateSignature::fromString(':missing-label');
    }

    public function test_from_string_rejects_empty_segments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SearchStateSignature::fromString('range:null||desired:null');
    }

    public function test_from_string_rejects_blank_segments_after_trimming(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Search state signatures cannot contain blank segments.');

        SearchStateSignature::fromString('range:null|   |desired:null');
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

    public function test_compose_requires_segments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Search state signatures require at least one segment.');

        SearchStateSignature::compose([]);
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

    public function test_formatter_produces_human_readable_map(): void
    {
        $signature = SearchStateSignature::fromString('range:USD:1.000:5.000:3|desired:EUR:2.500:3|signature:SRC>USD>EUR');

        self::assertSame(
            [
                'range' => 'USD:1.000:5.000:3',
                'desired' => 'EUR:2.500:3',
                'signature' => 'SRC>USD>EUR',
            ],
            SearchStateSignatureFormatter::format($signature),
        );

        self::assertSame(
            [
                'range' => 'USD:1.000:5.000:3',
                'desired' => 'EUR:2.500:3',
                'signature' => 'SRC>USD>EUR',
            ],
            SearchStateSignatureFormatter::format($signature->value()),
        );
    }

    /**
     * @return iterable<string, array{SearchStateSignature, array<string, string>}>
     */
    public static function provideGeneratedSignatures(): iterable
    {
        for ($seed = 0; $seed < 64; ++$seed) {
            $generator = new SearchStateSignatureGenerator(new Randomizer(new Mt19937($seed)));
            [$signature, $segments] = $generator->signature();

            yield 'seed-'.$seed => [$signature, $segments];
        }
    }

    /**
     * @dataProvider provideGeneratedSignatures
     *
     * @param array<string, string> $segments
     */
    public function test_generated_signatures_round_trip(SearchStateSignature $signature, array $segments): void
    {
        $trimmed = SearchStateSignature::fromString('  '.$signature->value().'  ');

        self::assertSame($signature->value(), $trimmed->value());
        self::assertSame($signature->value(), SearchStateSignature::compose($segments)->value());
        self::assertSame($segments, SearchStateSignatureFormatter::format($signature));
        self::assertSame($segments, SearchStateSignatureFormatter::format($trimmed));
        self::assertSame(
            $signature->value(),
            SearchStateSignature::compose(SearchStateSignatureFormatter::format($signature))->value(),
        );

        foreach (explode('|', $signature->value()) as $segment) {
            self::assertNotSame('', trim($segment));
            self::assertMatchesRegularExpression('/^[^:]+:.+$/', $segment);
        }
    }
}
