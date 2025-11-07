<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

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

            [$label, $segmentValue] = explode(':', $segment, 2);
            $label = trim($label);
            $segments[$label] = trim($segmentValue);
        }

        return $segments;
    }
}
