<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Detects BCMath function calls in production code.
 *
 * This rule ensures all arbitrary-precision arithmetic uses BigDecimal
 * instead of BCMath for consistency and better type safety.
 *
 * @implements Rule<FuncCall>
 */
final class BCMathFunctionCallRule implements Rule
{
    /**
     * List of BCMath functions to detect.
     */
    private const BCMATH_FUNCTIONS = [
        'bcadd',
        'bcsub',
        'bcmul',
        'bcdiv',
        'bcmod',
        'bcpow',
        'bcsqrt',
        'bccomp',
        'bcscale',
        'bcpowmod',
    ];

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return array<int, \PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name) {
            return [];
        }

        $functionName = $node->name->toLowerString();

        // Check if this is a BCMath function
        if (!in_array($functionName, self::BCMATH_FUNCTIONS, true)) {
            return [];
        }

        // Skip if in test files or example files
        $file = $scope->getFile();
        if ($this->isTestOrExampleFile($file)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'BCMath function %s() is prohibited. Use BigDecimal instead for consistency.',
                $functionName
            ))
                ->identifier('p2pPathFinder.bcmathUsage')
                ->tip($this->getSuggestion($functionName))
                ->build(),
        ];
    }

    private function isTestOrExampleFile(string $file): bool
    {
        return str_contains($file, '/tests/')
            || str_contains($file, '/examples/')
            || str_contains($file, 'Test.php');
    }

    private function getSuggestion(string $functionName): string
    {
        $suggestions = [
            'bcadd' => 'Use BigDecimal::plus()',
            'bcsub' => 'Use BigDecimal::minus()',
            'bcmul' => 'Use BigDecimal::multipliedBy()',
            'bcdiv' => 'Use BigDecimal::dividedBy()',
            'bccomp' => 'Use BigDecimal::compareTo()',
            'bcpow' => 'Use BigDecimal::power()',
            'bcsqrt' => 'Use BigDecimal::sqrt()',
            'bcmod' => 'Use BigDecimal::remainder()',
        ];

        return $suggestions[$functionName] ?? 'Use BigDecimal methods instead';
    }
}

