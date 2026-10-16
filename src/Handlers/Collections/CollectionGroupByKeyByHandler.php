<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelPropertyResolver;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;
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
        return [Collection::class, EloquentCollection::class, LazyCollection::class];
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
        $arg = $source->getNodeTypeProvider()->getType($args[0]->value);
        if (!$arg instanceof Union) {
            return null;
        }

        $class = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();
        if (ModelPropertyResolver::isLazyCollectionSubclass($class, $source->getCodebase())) {
            return null;
        }

        $templates = $event->getTemplateTypeParameters();
        $stmt = $event->getStmt();
        $lhs = $stmt instanceof \PhpParser\Node\Expr\MethodCall
            ? $source->getNodeTypeProvider()->getType($stmt->var)
            : null;
        if ($arg->isSingleStringLiteral()) {
            $model = ModelPropertyResolver::resolveExactlyOneModelClass($templates, 1, $lhs, $source->getCodebase());
            $key = $model === null ? null : self::resolveKeyType(
                ModelPropertyHandler::resolveColumnType($source->getCodebase(), $model, $arg->getSingleStringLiteral()->value),
                $source->getCodebase(),
                $method === 'groupby',
                $class === LazyCollection::class && $method === 'keyby',
            );
            $value = $templates[1] ?? ($model === null ? null : new Union([new TNamedObject($model)]));
        } elseif (self::returnsMixed($arg) && self::isBaseCollection($class) && self::isExactBaseCollection($lhs)) {
            // Laravel casts group/key values to array keys at runtime, but Psalm carries `mixed` through the stub's
            // TGroupKey/TNewKey conditional, so the outer key would be `array-key|mixed` and break `static<TKey, ...>` calls.
            // Both the dispatched class and the receiver expression must be exactly a base collection: subclasses may
            // reorder templates (`$this` inside one can still be typed as the base), and template or intersection
            // receivers make the answer disagree with what Psalm merges for the other members.
            $key = Type::getArrayKey();
            $value = $templates[1] ?? null;
        } else {
            return null;
        }

        if (!$key instanceof Union || !$value instanceof Union) {
            return null;
        }

        if ($method === 'keyby') {
            return new Union([new TGenericObject($class, [$key, $value], is_static: true)]);
        }

        $innerKey = $templates[0] ?? null;
        $preserve = isset($args[1]) ? $source->getNodeTypeProvider()->getType($args[1]->value) : null;
        if (!$innerKey instanceof Union) {
            return null;
        }

        $innerKey = !($preserve instanceof Union) || $preserve->isFalse() ? Type::getInt()
            : ($preserve->isTrue() ? $innerKey : Type::combineUnionTypes($innerKey, Type::getInt()));

        // LazyCollection::groupBy() is passthru() over collect()->groupBy(): the groups are eager Collections
        $group = $class === LazyCollection::class
            ? new TGenericObject(Collection::class, [$innerKey, $value])
            : (new TGenericObject($class, [$innerKey, $value], is_static: true))->setIsStatic(true, true);

        return new Union([new TGenericObject($class, [$key, new Union([$group])], is_static: true)]);
    }

    /** @psalm-mutation-free */
    private static function returnsMixed(Union $arg): bool
    {
        $mixed = false;
        foreach ($arg->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TClosure && !$atomic instanceof TCallable) {
                return false;
            }

            $mixed = $mixed || $atomic->return_type?->hasMixed() === true;
        }

        return $mixed;
    }

    /** @psalm-pure */
    private static function isBaseCollection(string $class): bool
    {
        return \in_array(\strtolower($class), ['illuminate\support\collection', 'illuminate\database\eloquent\collection'], true);
    }

    /** @psalm-mutation-free */
    private static function isExactBaseCollection(?Union $lhs): bool
    {
        if (!$lhs instanceof Union) {
            return false;
        }

        foreach ($lhs->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject || $atomic->extra_types !== [] || !self::isBaseCollection($atomic->value)) {
                return false;
            }
        }

        return true;
    }

    /** @psalm-mutation-free */
    private static function resolveKeyType(?Union $type, \Psalm\Codebase $codebase, bool $groupBy, bool $lazyKeyBy): ?Union
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
            // Lazy keyBy() yields the raw key from a generator: no array-key coercion turns bool into int
            if (!$atomic instanceof TInt && ($lazyKeyBy || !$atomic instanceof TBool)) {
                return null;
            }
        }

        return Type::getInt();
    }
}
