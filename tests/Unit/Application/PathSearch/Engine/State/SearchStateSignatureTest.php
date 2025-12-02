<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine\State;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchStateSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchStateSignatureFormatter;
use SomeWork\P2PPathFinder\Tests\Helpers\Generator\SearchStateSignatureGenerator;

use function explode;
use function sprintf;
use function trim;

#[CoversClass(SearchStateSignature::class)]
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

    public function test_from_string_rejects_segment_delimiter_in_label_separator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Search state signature segments require a label/value separator.');

        SearchStateSignature::fromString('range');
    }

    public function test_from_string_accepts_complex_valid_segments(): void
    {
        $signature = SearchStateSignature::fromString('range:USD:100.000:200.000:3|desired:EUR:150.000:3|route:SRC->MID->DST');

        self::assertSame('range:USD:100.000:200.000:3|desired:EUR:150.000:3|route:SRC->MID->DST', $signature->value());
    }

    public function test_from_string_handles_unicode_characters(): void
    {
        $signature = SearchStateSignature::fromString('currency:€|amount:100.50');

        self::assertSame('currency:€|amount:100.50', $signature->value());
    }

    public function test_from_string_accepts_numeric_labels_and_values(): void
    {
        $signature = SearchStateSignature::fromString('1:2|3:4');

        self::assertSame('1:2|3:4', $signature->value());
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

    public function test_compose_rejects_label_separator_in_labels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Search state signature labels cannot contain delimiters.');

        SearchStateSignature::compose(['range:value' => 'USD:1']);
    }

    public function test_compose_accepts_various_string_keys(): void
    {
        // Test with keys that contain numbers but are strings
        $signature = SearchStateSignature::compose(['key1' => 'value1', 'key2' => 'value2']);

        self::assertSame('key1:value1|key2:value2', $signature->value());
    }

    public function test_compose_handles_whitespace_in_segments(): void
    {
        $signature = SearchStateSignature::compose([
            ' range ' => ' USD:1 ',
            ' desired ' => ' EUR:2 ',
        ]);

        self::assertSame('range:USD:1|desired:EUR:2', $signature->value());
    }

    public function test_compose_accepts_special_characters_in_values(): void
    {
        $signature = SearchStateSignature::compose([
            'path' => 'SRC->MID->DST',
            'amount' => '100.50',
            'currency' => '$USD',
        ]);

        self::assertSame('path:SRC->MID->DST|amount:100.50|currency:$USD', $signature->value());
    }

    public function test_compose_preserves_order_of_segments(): void
    {
        $signature = SearchStateSignature::compose([
            'z' => 'last',
            'a' => 'first',
            'm' => 'middle',
        ]);

        self::assertSame('z:last|a:first|m:middle', $signature->value());
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

    public function test_compare_with_different_lengths(): void
    {
        $short = SearchStateSignature::fromString('a:1');
        $long = SearchStateSignature::fromString('a:1|b:2');

        self::assertSame(-1, $short->compare($long));
        self::assertSame(1, $long->compare($short));
    }

    public function test_compare_with_special_characters(): void
    {
        $special1 = SearchStateSignature::fromString('path:SRC->DST');
        $special2 = SearchStateSignature::fromString('path:SRC->MID->DST');

        self::assertSame(-1, $special1->compare($special2));
        self::assertSame(1, $special2->compare($special1));
    }

    public function test_compare_is_lexicographic(): void
    {
        $a = SearchStateSignature::fromString('label:a');
        $b = SearchStateSignature::fromString('label:b');
        $c = SearchStateSignature::fromString('label:c');

        self::assertSame(-1, $a->compare($b));
        self::assertSame(-1, $b->compare($c));
        self::assertSame(1, $c->compare($a));
    }

    public function test_equals_with_identical_complex_signatures(): void
    {
        $sig1 = SearchStateSignature::fromString('range:USD:100.000:200.000:3|desired:EUR:150.000:3|route:SRC->MID->DST');
        $sig2 = SearchStateSignature::fromString('range:USD:100.000:200.000:3|desired:EUR:150.000:3|route:SRC->MID->DST');

        self::assertTrue($sig1->equals($sig2));
        self::assertSame(0, $sig1->compare($sig2));
    }

    public function test_equals_with_different_order(): void
    {
        $sig1 = SearchStateSignature::fromString('a:1|b:2');
        $sig2 = SearchStateSignature::fromString('b:2|a:1');

        self::assertFalse($sig1->equals($sig2));
        self::assertNotSame(0, $sig1->compare($sig2));
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

    public function test_value_method_returns_internal_string(): void
    {
        $value = 'range:USD:100.000:200.000:3|desired:EUR:150.000:3';
        $signature = SearchStateSignature::fromString($value);

        self::assertSame($value, $signature->value());
    }

    public function test_to_string_method_returns_same_as_value(): void
    {
        $signature = SearchStateSignature::fromString('test:value');

        self::assertSame($signature->value(), (string) $signature);
        self::assertSame('test:value', (string) $signature);
    }

    public function test_stringable_interface_integration(): void
    {
        $signature = SearchStateSignature::fromString('label:value');

        // Test that it works in string contexts
        $string = 'Signature: '.$signature;
        self::assertSame('Signature: label:value', $string);

        // Test in string concatenation
        self::assertSame('label:value', $signature.'');

        // Test in sprintf
        $formatted = sprintf('Value: %s', $signature);
        self::assertSame('Value: label:value', $formatted);

        // Test in json_encode context (if used as string)
        $jsonString = json_encode(['sig' => (string) $signature]);
        self::assertSame('{"sig":"label:value"}', $jsonString);
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
