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
 * Model::make() is forwarded through __callStatic to Builder::make(), which
 * just creates a new instance via newModelInstance(). Using the constructor
 * directly is clearer, avoids the indirection, and makes the code easier
 * to understand for static analysis tools and developers alike.
 *
 * @see https://github.com/larastan/larastan/blob/2.x/src/Rules/NoModelMakeRule.php
 */
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
        if (!$expr->name instanceof Identifier || $expr->name->name !== 'make') {
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

        $shortName = self::shortClassName($className);

        IssueBuffer::accepts(
            new ModelMakeDiscouraged(
                "Use new {$shortName}() instead of {$shortName}::make(). "
                    . 'The constructor is clearer and avoids __callStatic indirection.',
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

        return $codebase->classExtendsOrImplements($className, Model::class);
    }

    /**
     * @psalm-pure
     */
    private static function shortClassName(string $fqcn): string
    {
        $parts = \explode('\\', $fqcn);

        return \end($parts);
    }
}
