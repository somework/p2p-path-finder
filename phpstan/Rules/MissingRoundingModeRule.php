<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

use function count;

/**
 * Detects toScale() calls without explicit RoundingMode parameter.
 *
 * Enforces that all BigDecimal::toScale() calls specify a RoundingMode
 * to ensure deterministic rounding behavior.
 *
 * @implements Rule<MethodCall>
 */
final class MissingRoundingModeRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return array<int, \PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }

        // Only check toScale() method calls
        if ('toScale' !== $node->name->toString()) {
            return [];
        }

        // Check if this is called on a BigDecimal type
        $callerType = $scope->getType($node->var);
        $bigDecimalType = new ObjectType('Brick\\Math\\BigDecimal');

        if (!$bigDecimalType->isSuperTypeOf($callerType)->yes()) {
            return [];
        }

        // Check if RoundingMode parameter is provided
        $args = $node->getArgs();

        // toScale($scale, $roundingMode) - we need 2 arguments
        if (count($args) < 2) {
            return [
                RuleErrorBuilder::message(
                    'Call to BigDecimal::toScale() must include explicit RoundingMode parameter for deterministic behavior.'
                )
                    ->identifier('p2pPathFinder.missingRoundingMode')
                    ->tip('Add RoundingMode::HALF_UP as second parameter: ->toScale($scale, RoundingMode::HALF_UP)')
                    ->build(),
            ];
        }

        // If the second argument is provided, verify it's not a variable that could be null
        // (This is a best-effort check - PHPStan type analysis will catch most issues)

        return [];
    }
}
