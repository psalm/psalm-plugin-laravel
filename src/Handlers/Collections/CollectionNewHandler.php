<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Union;

/**
 * `new Collection([...])` / `new LazyCollection([...])` / `new Eloquent\Collection([...])` get the same
 * widened TKey/TValue as `collect([...])`. Psalm binds the constructor's templates from the literal
 * array itself, and Psalm has no constructor-return-type provider, so the `New_` node type is replaced
 * after analysis.
 *
 * Only these three classes: a subclass may declare its templates in another order, and the resolver
 * answers a `[TKey, TValue]` pair.
 *
 * @see CollectionInputTypeResolver
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1766
 *
 * @internal
 */
final class CollectionNewHandler implements AfterExpressionAnalysisInterface
{
    /** Lowercase class names whose `[TKey, TValue]` template order is known. */
    private const COLLECTION_CLASSES = [
        'illuminate\support\collection' => true,
        'illuminate\support\lazycollection' => true,
        'illuminate\database\eloquent\collection' => true,
    ];

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        if (!$expr instanceof New_ || !$expr->class instanceof Name || \count($expr->args) !== 1) {
            return null;
        }

        $arg = $expr->args[0];
        if (!$arg instanceof Arg || $arg->unpack || $arg->name instanceof Identifier) {
            return null;
        }

        $source = $event->getStatementsSource();
        $nodeTypeProvider = $source->getNodeTypeProvider();

        $type = $nodeTypeProvider->getType($expr);
        if (!$type instanceof Union || !$type->isSingle()) {
            return null;
        }

        $atomic = $type->getSingleAtomic();
        if (!$atomic instanceof TGenericObject) {
            return null;
        }

        $class = $atomic->value;
        if (!isset(self::COLLECTION_CLASSES[\strtolower($class)])) {
            return null;
        }

        $resolved = CollectionInputTypeResolver::resolve($nodeTypeProvider->getType($arg->value), $source->getCodebase());
        if ($resolved === null) {
            return null;
        }

        $nodeTypeProvider->setType($expr, new Union([new TGenericObject($class, $resolved)]));

        return null;
    }
}
