<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

use PhpParser;
use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;

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
        if (!$location instanceof \Psalm\CodeLocation) {
            return [];
        }

        $filePath = $location->file_path;

        try {
            $stmts = $codebase->getStatementsForFile($filePath);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return [];
        }

        // Walk namespace → class → method manually, since Psalm's AST
        // does not run ParentConnectingVisitor (getAttribute('parent') is always null).
        $castsMethod = self::findCastsMethod($stmts, $modelClass);

        if (!$castsMethod instanceof PhpParser\Node\Stmt\ClassMethod || $castsMethod->stmts === null) {
            return [];
        }

        // Find return statements
        foreach ($castsMethod->stmts as $stmt) {
            if (!$stmt instanceof PhpParser\Node\Stmt\Return_ || !$stmt->expr instanceof \PhpParser\Node\Expr) {
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
                    $casts = \array_merge($casts, self::extractCastsFromArray($arg->value));
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
            /** @var string|null $resolved */
            $resolved = $expr->class->getAttribute('resolvedName');
            if (\is_string($resolved)) {
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

    /**
     * Walk the AST manually (namespace → class → method) to find the casts() method belonging to the given model class.
     * @param list<PhpParser\Node\Stmt> $stmts
     * @psalm-mutation-free
     */
    private static function findCastsMethod(array $stmts, string $modelClass): ?PhpParser\Node\Stmt\ClassMethod
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\Namespace_) {
                $namespaceName = $stmt->name?->toString() ?? '';

                foreach ($stmt->stmts as $nsStmt) {
                    if (!$nsStmt instanceof PhpParser\Node\Stmt\Class_) {
                        continue;
                    }

                    $shortName = $nsStmt->name?->toString() ?? '';
                    $fqcn = $namespaceName !== '' ? $namespaceName . '\\' . $shortName : $shortName;

                    if (\strtolower($fqcn) !== \strtolower($modelClass)) {
                        continue;
                    }

                    return self::findCastsInClass($nsStmt);
                }

                continue;
            }

            // Top-level class (no namespace) — FQCN equals the short name
            if (!$stmt instanceof PhpParser\Node\Stmt\Class_) {
                continue;
            }

            $shortName = $stmt->name?->toString() ?? '';

            if (\strtolower($shortName) !== \strtolower($modelClass)) {
                continue;
            }

            return self::findCastsInClass($stmt);
        }

        return null;
    }

    /** @psalm-mutation-free */
    private static function findCastsInClass(PhpParser\Node\Stmt\Class_ $class): ?PhpParser\Node\Stmt\ClassMethod
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\ClassMethod && \strtolower($stmt->name->name) === 'casts') {
                return $stmt;
            }
        }

        return null;
    }
}
