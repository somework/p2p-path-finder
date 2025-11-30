<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\DNumber;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function in_array;
use function is_string;
use function sprintf;

/**
 * Detects float literals in arithmetic operations to prevent precision loss.
 *
 * This rule catches:
 * - Binary arithmetic operations (+ - * / %) with float literals
 * - Method calls on Money/ExchangeRate with float arguments
 * - BigDecimal operations with float literals
 *
 * @implements Rule<Node\Expr>
 */
final class FloatLiteralInArithmeticRule implements Rule
{
    /**
     * Allowed contexts where float literals are acceptable (e.g., time calculations).
     */
    private const ALLOWED_CONTEXTS = [
        'elapsedMilliseconds',
        'startedAt',
        'microtime',
        'time',
        'sprintf',
        'guard',
        'report',
        'budget',
    ];

    /**
     * Variable name patterns that indicate time-related calculations.
     */
    private const TIME_RELATED_PATTERNS = [
        'milliseconds',
        'seconds',
        'elapsed',
        'started',
        'budget',
        'timeout',
        'duration',
    ];

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return array<int, \PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Check binary operations (+ - * / %)
        if ($node instanceof BinaryOp) {
            return $this->checkBinaryOperation($node, $scope);
        }

        // Check method calls (Money::multiply, ExchangeRate::convert, etc.)
        if ($node instanceof MethodCall) {
            return $this->checkMethodCall($node, $scope);
        }

        // Check function calls (bcadd, bcmul, etc. - though we have a separate rule for those)
        if ($node instanceof FuncCall) {
            return $this->checkFunctionCall($node, $scope);
        }

        return [];
    }

    /**
     * @return array<int, \PHPStan\Rules\RuleError>
     */
    private function checkBinaryOperation(BinaryOp $node, Scope $scope): array
    {
        $errors = [];

        // Skip if this is a time calculation (e.g., microtime arithmetic)
        if ($this->isAllowedContext($node, $scope)) {
            return [];
        }

        // Check if left operand is a float literal
        if ($node->left instanceof DNumber) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Float literal %s used in arithmetic operation. Use BigDecimal or numeric-string instead.',
                $node->left->value
            ))
                ->identifier('p2pPathFinder.floatLiteral')
                ->tip('Convert to string: \''.$node->left->value.'\'')
                ->build();
        }

        // Check if right operand is a float literal
        if ($node->right instanceof DNumber) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Float literal %s used in arithmetic operation. Use BigDecimal or numeric-string instead.',
                $node->right->value
            ))
                ->identifier('p2pPathFinder.floatLiteral')
                ->tip('Convert to string: \''.$node->right->value.'\'')
                ->build();
        }

        return $errors;
    }

    /**
     * @return array<int, \PHPStan\Rules\RuleError>
     */
    private function checkMethodCall(MethodCall $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }

        $methodName = $node->name->toString();
        $moneyMethods = ['multiply', 'divide', 'add', 'subtract'];
        $rateMethods = ['convert', 'multiply'];

        // Check if this is a Money or ExchangeRate method
        if (!in_array($methodName, array_merge($moneyMethods, $rateMethods), true)) {
            return [];
        }

        $errors = [];

        // Check arguments for float literals
        foreach ($node->getArgs() as $arg) {
            if ($arg->value instanceof DNumber) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'Float literal %s passed to %s(). Use numeric-string instead.',
                    $arg->value->value,
                    $methodName
                ))
                    ->identifier('p2pPathFinder.floatLiteral')
                    ->tip('Convert to string: \''.$arg->value->value.'\'')
                    ->build();
            }
        }

        return $errors;
    }

    /**
     * @return array<int, \PHPStan\Rules\RuleError>
     */
    private function checkFunctionCall(FuncCall $node, Scope $scope): array
    {
        // We have a separate rule for BCMath, so skip those here
        return [];
    }

    private function isAllowedContext(Node $node, Scope $scope): bool
    {
        // Check if the node is within an allowed context by looking at function/class names
        $function = $scope->getFunction();
        if (null !== $function) {
            $functionName = $function->getName();
            foreach (self::ALLOWED_CONTEXTS as $context) {
                if (false !== stripos($functionName, $context)) {
                    return true;
                }
            }
        }

        // Check class name
        $classReflection = $scope->getClassReflection();
        if (null !== $classReflection) {
            $className = $classReflection->getName();
            foreach (self::ALLOWED_CONTEXTS as $context) {
                if (false !== stripos($className, $context)) {
                    return true;
                }
            }
        }

        // Check if the literal is 1000.0 (common for ms conversion) in a time-related context
        if ($node instanceof BinaryOp) {
            $literal = null;
            if ($node->left instanceof DNumber) {
                $literal = $node->left->value;
            } elseif ($node->right instanceof DNumber) {
                $literal = $node->right->value;
            }

            if (1000.0 === $literal || 1000 === $literal) {
                // Check if the other operand or result is time-related
                if ($this->isTimeRelatedExpression($node, $scope)) {
                    return true;
                }
            }

            // Allow 0.0 comparisons in guard/validation contexts
            if ((0.0 === $literal || 0 === $literal) && ($node instanceof BinaryOp\Smaller || $node instanceof BinaryOp\Greater)) {
                if ($this->isTimeRelatedExpression($node, $scope)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isTimeRelatedExpression(Node $node, Scope $scope): bool
    {
        // Get all variable names involved in the expression
        $variables = $this->extractVariableNames($node);

        foreach ($variables as $varName) {
            foreach (self::TIME_RELATED_PATTERNS as $pattern) {
                if (false !== stripos($varName, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string>
     */
    private function extractVariableNames(Node $node): array
    {
        $names = [];

        if ($node instanceof Expr\Variable && is_string($node->name)) {
            $names[] = $node->name;
        }

        if ($node instanceof Expr\PropertyFetch) {
            if ($node->name instanceof Node\Identifier) {
                $names[] = $node->name->toString();
            }
        }

        // Recursively check operands for binary operations
        if ($node instanceof BinaryOp) {
            $names = array_merge($names, $this->extractVariableNames($node->left));
            $names = array_merge($names, $this->extractVariableNames($node->right));
        }

        return $names;
    }
}
