<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

use InvalidArgumentException;

use function explode;
use function implode;
use function str_contains;
use function strlen;
use function strpos;
use function trim;

/**
 * Unique identifier for search state properties at a node.
 *
 * ## Purpose
 *
 * Signatures uniquely identify the "context" of a state:
 * - Amount range (min/max spend bounds)
 * - Desired amount
 * - Route signature (path taken to reach node)
 *
 * States with the same signature are considered equivalent for dominance comparison.
 *
 * ## Format
 *
 * Signature format: `label1:value1|label2:value2|...`
 *
 * Example:
 * ```
 * range:USD:100.000:200.000:3|desired:USD:150.000:3
 * ```
 *
 * ## Validation
 *
 * - Non-empty signature
 * - No double delimiters (`||`)
 * - Each segment: `label:value` format
 * - Labels and values must be non-empty
 *
 * @internal
 */
final class SearchStateSignature implements \Stringable
{
    private const SEGMENT_DELIMITER = '|';
    private const LABEL_SEPARATOR = ':';

    private readonly string $value;

    private function __construct(string $value)
    {
        $value = trim($value);

        if ('' === $value) {
            throw new InvalidArgumentException('Search state signatures cannot be empty.');
        }

        if (str_contains($value, self::SEGMENT_DELIMITER.self::SEGMENT_DELIMITER)) {
            throw new InvalidArgumentException('Search state signatures cannot contain empty segments.');
        }

        if (self::SEGMENT_DELIMITER === $value[0] || self::SEGMENT_DELIMITER === $value[strlen($value) - 1]) {
            throw new InvalidArgumentException('Search state signatures cannot start or end with the segment delimiter.');
        }

        $segments = explode(self::SEGMENT_DELIMITER, $value);
        foreach ($segments as $segment) {
            $segment = trim($segment);

            if ('' === $segment) {
                throw new InvalidArgumentException('Search state signatures cannot contain blank segments.');
            }

            $separatorPosition = strpos($segment, self::LABEL_SEPARATOR);
            if (false === $separatorPosition) {
                throw new InvalidArgumentException('Search state signature segments require a label/value separator.');
            }

            if (0 === $separatorPosition) {
                throw new InvalidArgumentException('Search state signature segments require a label before the separator.');
            }

            if ($separatorPosition === strlen($segment) - 1) {
                throw new InvalidArgumentException('Search state signature segments require a value after the separator.');
            }
        }

        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    /**
     * @param iterable<string, string> $segments
     */
    public static function compose(iterable $segments): self
    {
        $normalized = [];

        foreach ($segments as $label => $value) {
            $label = trim($label);
            $value = trim($value);

            if ('' === $label) {
                throw new InvalidArgumentException('Search state signature labels must be non-empty.');
            }

            if (str_contains($label, self::SEGMENT_DELIMITER) || str_contains($label, self::LABEL_SEPARATOR)) {
                throw new InvalidArgumentException('Search state signature labels cannot contain delimiters.');
            }

            if ('' === $value) {
                throw new InvalidArgumentException('Search state signature values must be non-empty.');
            }

            if (str_contains($value, self::SEGMENT_DELIMITER)) {
                throw new InvalidArgumentException('Search state signature values cannot contain the segment delimiter.');
            }

            $normalized[] = $label.self::LABEL_SEPARATOR.$value;
        }

        if ([] === $normalized) {
            throw new InvalidArgumentException('Search state signatures require at least one segment.');
        }

        return new self(implode(self::SEGMENT_DELIMITER, $normalized));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function compare(self $other): int
    {
        return $this->value <=> $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
