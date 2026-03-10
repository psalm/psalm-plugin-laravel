<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

use PhpParser;
use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;

use function array_merge;
use function is_string;

/**
 * AST-parses a model's casts() method body to extract cast definitions
 * without invoking the method.
 *
 * Handles:
 * - Simple `return [...]` with literal key-value pairs
 * - `array_merge(parent::casts(), [...])` patterns
 * - Class constants and string concatenation
 *
 * Unparseable expressions result in 'mixed' for the affected key.
 *
 * @internal
 */
final class CastsMethodParser
{
    /**
     * Parse the casts() method of a model class and extract cast definitions.
     *
     * @return array<string, string> Map of property name → cast string
     */
    public static function parse(Codebase $codebase, string $modelClass): array
    {
        $methodId = $modelClass . '::casts';

        if (!$codebase->methodExists($methodId)) {
            return [];
        }

        try {
            $methodStorage = $codebase->methods->getStorage(
                MethodIdentifier::wrap($methodId),
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return [];
        }

        $location = $methodStorage->location;
        if ($location === null) {
            return [];
        }

        $filePath = $location->file_path;

        try {
            $stmts = $codebase->getStatementsForFile($filePath);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return [];
        }

        $nodeFinder = new PhpParser\NodeFinder();

        // Find the class method named 'casts'
        $castsMethod = $nodeFinder->findFirst($stmts, static function (PhpParser\Node $node) use ($modelClass): bool {
            if (!$node instanceof PhpParser\Node\Stmt\ClassMethod) {
                return false;
            }

            if ($node->name->name !== 'casts') {
                return false;
            }

            // Check that this is in the right class
            /** @var mixed $parent */
            $parent = $node->getAttribute('parent');
            if ($parent instanceof PhpParser\Node\Stmt\Class_ && $parent->namespacedName !== null) {
                return $parent->namespacedName->toString() === $modelClass;
            }

            return false;
        });

        if (!$castsMethod instanceof PhpParser\Node\Stmt\ClassMethod || $castsMethod->stmts === null) {
            return [];
        }

        // Find return statements
        foreach ($castsMethod->stmts as $stmt) {
            if (!$stmt instanceof PhpParser\Node\Stmt\Return_ || $stmt->expr === null) {
                continue;
            }

            return self::extractCastsFromExpr($stmt->expr);
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private static function extractCastsFromExpr(PhpParser\Node\Expr $expr): array
    {
        // Simple array return: return [...]
        if ($expr instanceof PhpParser\Node\Expr\Array_) {
            return self::extractCastsFromArray($expr);
        }

        // array_merge(parent::casts(), [...])
        if (
            $expr instanceof PhpParser\Node\Expr\FuncCall
            && $expr->name instanceof PhpParser\Node\Name
            && $expr->name->toLowerString() === 'array_merge'
        ) {
            $casts = [];
            foreach ($expr->args as $arg) {
                if (!$arg instanceof PhpParser\Node\Arg) {
                    continue;
                }
                if ($arg->value instanceof PhpParser\Node\Expr\Array_) {
                    $casts = array_merge($casts, self::extractCastsFromArray($arg->value));
                }
                // parent::casts() — skip, we get parent casts from $casts property
            }

            return $casts;
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private static function extractCastsFromArray(PhpParser\Node\Expr\Array_ $array): array
    {
        $casts = [];

        foreach ($array->items as $item) {
            if ($item === null || $item->key === null) {
                continue;
            }

            $key = self::resolveStringValue($item->key);
            $value = self::resolveStringValue($item->value);

            if ($key === null) {
                continue;
            }

            // If we can't resolve the value, use 'mixed'
            $casts[$key] = $value ?? 'mixed';
        }

        return $casts;
    }

    private static function resolveStringValue(PhpParser\Node\Expr $expr): ?string
    {
        // Simple string literal
        if ($expr instanceof PhpParser\Node\Scalar\String_) {
            return $expr->value;
        }

        // Class constant: SomeEnum::class
        if (
            $expr instanceof PhpParser\Node\Expr\ClassConstFetch
            && $expr->class instanceof PhpParser\Node\Name
            && $expr->name instanceof PhpParser\Node\Identifier
            && $expr->name->name === 'class'
        ) {
            /** @var mixed $resolved */
            $resolved = $expr->class->getAttribute('resolvedName');
            if (is_string($resolved)) {
                return $resolved;
            }

            return $expr->class->toString();
        }

        // String concatenation: SomeClass::class . ':nullable'
        if ($expr instanceof PhpParser\Node\Expr\BinaryOp\Concat) {
            $left = self::resolveStringValue($expr->left);
            $right = self::resolveStringValue($expr->right);

            if ($left !== null && $right !== null) {
                return $left . $right;
            }
        }

        return null;
    }
}
