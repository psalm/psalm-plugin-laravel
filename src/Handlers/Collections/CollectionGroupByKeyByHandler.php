<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelPropertyResolver;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

final class CollectionGroupByKeyByHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Collection::class, EloquentCollection::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $method = $event->getMethodNameLowercase();
        $args = $event->getCallArgs();
        if (!\in_array($method, ['groupby', 'keyby'], true) || $args === []
            || ($method === 'keyby' && \count($args) !== 1) || \count($args) > 2) {
            return null;
        }

        $source = $event->getSource();
        $column = $source->getNodeTypeProvider()->getType($args[0]->value);
        if (!$column instanceof Union || !$column->isSingleStringLiteral()) {
            return null;
        }

        $stmt = $event->getStmt();
        $lhs = $stmt instanceof \PhpParser\Node\Expr\MethodCall
            ? $source->getNodeTypeProvider()->getType($stmt->var)
            : null;
        $model = ModelPropertyResolver::resolveExactlyOneModelClass(
            $event->getTemplateTypeParameters(),
            1,
            $lhs,
            $source->getCodebase(),
        );
        $key = $model === null ? null : self::resolveKeyType(
            ModelPropertyHandler::resolveColumnType($source->getCodebase(), $model, $column->getSingleStringLiteral()->value),
            $source->getCodebase(),
            $method === 'groupby',
        );
        if ($model === null || !$key instanceof \Psalm\Type\Union) {
            return null;
        }

        $class = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();
        $value = $event->getTemplateTypeParameters()[1] ?? new Union([new TNamedObject($model)]);

        if ($method === 'keyby') {
            return new Union([new TGenericObject($class, [$key, $value], is_static: true)]);
        }

        $preserve = isset($args[1]) ? $source->getNodeTypeProvider()->getType($args[1]->value) : null;
        $innerKey = $event->getTemplateTypeParameters()[0] ?? null;
        if (!$innerKey instanceof Union) {
            return null;
        }

        $innerKey = !($preserve instanceof Union) || $preserve->isFalse() ? Type::getInt()
            : ($preserve->isTrue() ? $innerKey : Type::combineUnionTypes($innerKey, Type::getInt()));

        return new Union([new TGenericObject($class, [$key,
            new Union([(new TGenericObject($class, [$innerKey, $value], is_static: true))->setIsStatic(true, true)]),
        ], is_static: true)]);
    }

    /** @psalm-mutation-free */
    private static function resolveKeyType(?Union $type, \Psalm\Codebase $codebase, bool $groupBy): ?Union
    {
        if (!$type instanceof Union) {
            return null;
        }

        $atomics = $type->getAtomicTypes();
        $atomic = reset($atomics);
        if ($groupBy && \count($atomics) === 1 && $atomic instanceof TNamedObject) {
            try {
                return $codebase->classlike_storage_provider->get($atomic->value)->enum_type === 'int'
                    ? Type::getInt()
                    : null;
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        foreach ($atomics as $atomic) {
            if (!$atomic instanceof TInt && !$atomic instanceof TBool) {
                return null;
            }
        }

        return Type::getInt();
    }
}
