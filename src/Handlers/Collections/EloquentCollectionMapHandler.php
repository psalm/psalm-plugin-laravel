<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

/**
 * Narrows `Eloquent\Collection::map()` and `mapWithKeys()` back to an Eloquent collection when every
 * mapped value is provably a Model.
 *
 * Laravel returns `static` only in that case and a `Support\Collection` otherwise. The stub cannot say
 * so with a conditional return: Psalm keeps the non-taken `static<TKey, TMapValue>` branch and
 * bound-checks a scalar TMapValue against `TModel of Model` (InvalidTemplateParam, vimeo/psalm#11867).
 * Widening the stub to `Support\Collection` alone was tried and rejected: it loses `->load()` on a map
 * of models and regresses `CollectionMapTest` (see decisions.md).
 *
 * The stub therefore declares the always-true `Support\Collection` supertype, and this handler answers
 * `Eloquent\Collection` only when the callback's inferred return type proves it; any other shape declines,
 * so the stub's type stands.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1699
 * @internal
 */
final class EloquentCollectionMapHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [EloquentCollection::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $method = $event->getMethodNameLowercase();
        if ($method !== 'map' && $method !== 'mapwithkeys') {
            return null;
        }

        $args = $event->getCallArgs();
        $stmt = $event->getStmt();
        $templateParams = $event->getTemplateTypeParameters();
        if (\count($args) !== 1 || $args[0]->unpack || !$stmt instanceof \PhpParser\Node\Expr\MethodCall
            || $templateParams === null || \count($templateParams) !== 2) {
            return null;
        }

        $source = $event->getSource();
        $nodeTypes = $source->getNodeTypeProvider();

        // A union receiver is analysed once per atomic; answering for one arm would speak for all of them.
        $receiver = $nodeTypes->getType($stmt->var);
        if (!$receiver instanceof Union || !$receiver->isSingle()) {
            return null;
        }

        $callback = $nodeTypes->getType($args[0]->value);
        $returned = $callback instanceof Union ? self::callbackReturnType($callback) : null;
        if (!$returned instanceof Union) {
            return null;
        }

        if ($method === 'map') {
            $keyType = $templateParams[0];
            $valueType = $returned;
        } else {
            $keyAndValue = self::arrayKeyAndValue($returned);
            if ($keyAndValue === null) {
                return null;
            }

            [$keyType, $valueType] = $keyAndValue;
        }

        if (!self::isAllModels($valueType, $source->getCodebase())) {
            return null;
        }

        // Plain receivers keep Psalm's `&static` marker (the stub's former `static<...>` branch). A userland
        // subclass has its own TModel bound that is not known here, so it widens to the base Eloquent class.
        $called = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();
        $isPlainReceiver = \strtolower($called) === \strtolower(EloquentCollection::class);

        return new Union([new TGenericObject(EloquentCollection::class, [$keyType, $valueType], is_static: $isPlainReceiver)]);
    }

    /**
     * The union of every closure return type, or null when any member is not a closure with a known return.
     *
     * @psalm-mutation-free
     */
    private static function callbackReturnType(Union $callback): ?Union
    {
        $returned = [];
        foreach ($callback->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TClosure && !$atomic instanceof TCallable || !$atomic->return_type instanceof Union) {
                return null;
            }

            foreach ($atomic->return_type->getAtomicTypes() as $returnAtomic) {
                $returned[] = $returnAtomic;
            }
        }

        return new Union($returned);
    }

    /**
     * @return array{Union, Union}|null key and value type of a single array atomic
     * @psalm-mutation-free
     */
    private static function arrayKeyAndValue(Union $returned): ?array
    {
        $atomics = $returned->getAtomicTypes();
        if (\count($atomics) !== 1) {
            return null;
        }

        $atomic = \reset($atomics);
        if ($atomic instanceof TKeyedArray) {
            return [$atomic->getGenericKeyType(), $atomic->getGenericValueType()];
        }

        return $atomic instanceof TArray ? [$atomic->type_params[0], $atomic->type_params[1]] : null;
    }

    /**
     * Touches `$codebase` last, after every cheaper decline.
     *
     * @psalm-mutation-free
     */
    private static function isAllModels(Union $type, Codebase $codebase): bool
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TTemplateParam) {
                if (!self::isAllModels($atomic->as, $codebase)) {
                    return false;
                }
            } elseif (!$atomic instanceof TNamedObject || !ClassLineage::isA($codebase, $atomic->value, Model::class)) {
                return false;
            }
        }

        return true;
    }
}
