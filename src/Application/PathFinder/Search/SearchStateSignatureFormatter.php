<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

use function count;
use function explode;
use function trim;

/**
 * Converts {@see SearchStateSignature} instances into associative maps for logging and debugging.
 */
final class SearchStateSignatureFormatter
{
    /**
     * @return array<string, string>
     */
    public static function format(SearchStateSignature|string $signature): array
    {
        $value = $signature instanceof SearchStateSignature ? $signature->value() : $signature;

        $segments = [];
        foreach (explode('|', $value) as $segment) {
            $segment = trim($segment);

            if ('' === $segment) {
                continue;
            }

            $parts = explode(':', $segment, 2);

            if (2 === count($parts)) {
                [$label, $segmentValue] = $parts;
                $label = trim($label);
                $segmentValue = trim($segmentValue);

                if ('' === $label) {
                    continue;
                }

                $segments[$label] = $segmentValue;

                continue;
            }

            $label = trim($parts[0]);

            if ('' === $label) {
                continue;
            }

            continue;
        }

        return $segments;
    }
}
