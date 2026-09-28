<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal\Ast;

use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\MethodIdentifier;

/**
 * Resolves a method's source AST from Psalm's method storage without invoking it.
 *
 * The supplied identifier is exact: callers choose whether they need the called,
 * appearing, or declaring method before entering this utility. Keeping that choice
 * outside prevents an AST lookup from silently broadening into an inheritance walk.
 *
 * @internal
 */
final class ClassMethodResolver
{
    /**
     * @return ?array{classMethod: Stmt\ClassMethod, fileStmts: list<Stmt>}
     */
    public static function resolve(Codebase $codebase, MethodIdentifier $methodId): ?array
    {
        // RelationMethodParser calls this path for every method dispatch on every
        // Model subclass, including forwarded Builder methods. Avoid an exception on
        // each expected cold miss.
        if (!$codebase->methods->hasStorage($methodId)) {
            return null;
        }

        try {
            $methodStorage = $codebase->methods->getStorage($methodId);
        } catch (\InvalidArgumentException|\UnexpectedValueException $exception) {
            $codebase->progress->debug(
                "Laravel plugin: could not get method storage for {$methodId}: {$exception->getMessage()}\n",
            );
            return null;
        }

        $location = $methodStorage->location;
        if (!$location instanceof CodeLocation) {
            return null;
        }

        try {
            $fileStmts = $codebase->getStatementsForFile($location->file_path);
        } catch (\InvalidArgumentException|\UnexpectedValueException $exception) {
            $codebase->progress->debug(
                "Laravel plugin: could not get statements for {$location->file_path}: {$exception->getMessage()}\n",
            );
            return null;
        }

        $classMethod = self::findMethod($fileStmts, '', $methodId->fq_class_name, $methodId->method_name);
        if (!$classMethod instanceof Stmt\ClassMethod) {
            return null;
        }

        return ['classMethod' => $classMethod, 'fileStmts' => $fileStmts];
    }

    /**
     * Walk namespace → class-like → method manually because Psalm's AST has no parent links.
     *
     * @param list<Stmt> $stmts
     * @psalm-mutation-free
     */
    private static function findMethod(
        array $stmts,
        string $namespace,
        string $fqClassName,
        string $methodNameLower,
    ): ?Stmt\ClassMethod {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                $found = self::findMethod(
                    $stmt->stmts,
                    $stmt->name?->toString() ?? '',
                    $fqClassName,
                    $methodNameLower,
                );
                if ($found instanceof Stmt\ClassMethod) {
                    return $found;
                }

                continue;
            }

            if (!$stmt instanceof Stmt\ClassLike || !$stmt->name instanceof Identifier) {
                continue;
            }

            $shortName = $stmt->name->toString();
            $fqcn = $namespace !== '' ? $namespace . '\\' . $shortName : $shortName;
            if (\strcasecmp($fqcn, $fqClassName) !== 0) {
                continue;
            }

            foreach ($stmt->stmts as $member) {
                if ($member instanceof Stmt\ClassMethod && \strtolower($member->name->name) === $methodNameLower) {
                    return $member;
                }
            }
        }

        return null;
    }
}
