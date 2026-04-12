<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\ModelMakeDiscouraged;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;

/**
 * Flags Model::make() calls and suggests using new Model() instead.
 *
 * Model::make() is forwarded through magic methods (__callStatic -> __call ->
 * forwardCallTo) to Builder::make(), which just creates a new instance via
 * newModelInstance(). Using the constructor directly is clearer and avoids
 * the indirection.
 *
 * @see https://github.com/larastan/larastan/blob/2.x/src/Rules/NoModelMakeRule.php
 */
// This handler uses AfterExpressionAnalysisInterface which fires per-expression.
// The instanceof StaticCall check (line 35) rejects ~95% of expressions immediately.
// An alternative would be emitting from ModelMethodHandler's __callStatic path, but
// that would couple rule enforcement with type resolution. Kept separate for clarity.
final class ModelMakeHandler implements AfterExpressionAnalysisInterface
{
    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof StaticCall) {
            return null;
        }

        // Only handle named method calls (not dynamic ::$method())
        // PHP method names are case-insensitive, so normalize before comparing
        if (!$expr->name instanceof Identifier || \strtolower($expr->name->name) !== 'make') {
            return null;
        }

        // Only handle named class references (not dynamic $class::make())
        if (!$expr->class instanceof Name) {
            return null;
        }

        $className = $expr->class->getAttribute('resolvedName');
        if (!\is_string($className)) {
            return null;
        }

        if (!self::isModelSubclass($className, $event)) {
            return null;
        }

        // If the class (or a non-Model ancestor) declares its own make() method,
        // the call is not magic indirection — it's a legitimate custom method.
        if (self::hasCustomMakeMethod($className, $event)) {
            return null;
        }

        $shortName = self::shortClassName($className);

        IssueBuffer::accepts(
            new ModelMakeDiscouraged(
                "Use new {$shortName}(...) instead of {$shortName}::make(...). "
                    . 'The constructor is clearer and avoids magic method indirection.',
                new CodeLocation($event->getStatementsSource(), $expr),
            ),
            $event->getStatementsSource()->getSuppressedIssues(),
        );

        return null;
    }

    /** @psalm-external-mutation-free */
    private static function isModelSubclass(string $className, AfterExpressionAnalysisEvent $event): bool
    {
        if ($className === Model::class) {
            return true;
        }

        $codebase = $event->getCodebase();

        if (!$codebase->classExists($className)) {
            return false;
        }

        return $codebase->classExtends($className, Model::class);
    }

    /**
     * Checks whether the class (or a non-Model ancestor) declares its own make() method.
     * When a custom make() exists, the call bypasses __callStatic magic entirely.
     *
     * @psalm-mutation-free
     */
    private static function hasCustomMakeMethod(string $className, AfterExpressionAnalysisEvent $event): bool
    {
        $codebase = $event->getCodebase();

        try {
            $classStorage = $codebase->classlike_storage_provider->get(\strtolower($className));
        } catch (\InvalidArgumentException) {
            return false;
        }

        $declaringId = $classStorage->declaring_method_ids['make'] ?? null;

        if ($declaringId === null) {
            return false;
        }

        // If make() is declared on a class other than the base Model,
        // it's a custom method — not magic indirection
        return $declaringId->fq_class_name !== Model::class;
    }

    /** @psalm-pure */
    private static function shortClassName(string $fqcn): string
    {
        $pos = \strrpos($fqcn, '\\');

        return $pos !== false ? \substr($fqcn, $pos + 1) : $fqcn;
    }
}
