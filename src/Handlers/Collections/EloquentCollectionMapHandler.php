<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TConditional;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TKeyOf;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateIndexedAccess;
use Psalm\Type\Atomic\TTemplateKeyOf;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Atomic\TTemplateParamClass;
use Psalm\Type\Atomic\TTemplatePropertiesOf;
use Psalm\Type\Atomic\TTemplateValueOf;
use Psalm\Type\Atomic\TValueOf;
use Psalm\Type\TypeNode;
use Psalm\Type\TypeVisitor;
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
        if (\count($args) !== 1 || $args[0]->unpack || !$stmt instanceof \PhpParser\Node\Expr\MethodCall) {
            return null;
        }

        $source = $event->getSource();
        $nodeTypes = $source->getNodeTypeProvider();

        // A union receiver is analysed once per atomic; answering for one arm would speak for all of them.
        $receiver = $nodeTypes->getType($stmt->var);
        $receiverAtomic = $receiver instanceof Union && $receiver->isSingle() ? $receiver->getSingleAtomic() : null;
        if (!$receiverAtomic instanceof TNamedObject || $receiverAtomic->extra_types !== []) {
            return null;
        }

        $callback = $nodeTypes->getType($args[0]->value);
        $returned = $callback instanceof Union ? self::callbackReturnType($callback) : null;
        if (!$returned instanceof Union) {
            return null;
        }

        if ($method === 'map') {
            $keyType = null;
            $valueType = $returned;
        } else {
            $keyAndValue = self::arrayKeyAndValue($returned);
            if ($keyAndValue === null) {
                return null;
            }

            [$keyType, $valueType] = $keyAndValue;
        }

        $codebase = $source->getCodebase();
        if (!self::isAllModels($valueType, $codebase)) {
            return null;
        }

        // Psalm also dispatches this provider for a proxy that reaches the collection through `@mixin`; only a
        // receiver that IS an Eloquent collection is promised Eloquent-only methods such as load().
        if (!ClassLineage::isA($codebase, $receiverAtomic->value, EloquentCollection::class)) {
            return null;
        }

        // map() keeps the receiver's keys, which are Eloquent\Collection's TKey as the receiver binds it: a subclass
        // may fix it (`extends Collection<string, TModel>`) or move it to another template slot.
        $keyType ??= self::eloquentKeyType($codebase, $receiverAtomic);
        if (!$keyType instanceof Union) {
            return null;
        }

        // Plain receivers keep Psalm's `&static` marker (the stub's former `static<...>` branch). A userland
        // subclass may bind TModel more tightly than Model, so it widens to the base Eloquent class.
        $called = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();
        $isPlainReceiver = \strtolower($called) === \strtolower(EloquentCollection::class);

        return new Union([new TGenericObject(EloquentCollection::class, [$keyType, $valueType], is_static: $isPlainReceiver)]);
    }

    /**
     * `Eloquent\Collection::TKey` for the receiver, read through the ancestry the way Psalm flattened it: each
     * ancestor's template is stated in its DIRECT child's terms, so a forwarded template is followed up the chain
     * until it reaches the receiver's own template (matched by defining class) or a concrete type.
     */
    private static function eloquentKeyType(Codebase $codebase, TNamedObject $receiver): ?Union
    {
        $typeParams = $receiver instanceof TGenericObject ? $receiver->type_params : [];
        if (\strcasecmp($receiver->value, EloquentCollection::class) === 0) {
            return $typeParams[0] ?? null;
        }

        try {
            $storage = $codebase->classlike_storage_provider->get($receiver->value);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }

        $value = $storage->template_extended_params[EloquentCollection::class]['TKey'] ?? null;
        for ($hops = 0; $hops < 8 && $value instanceof Union; ++$hops) {
            $forwarded = $value->isSingle() ? $value->getSingleAtomic() : null;
            if (!$forwarded instanceof TTemplateParam) {
                // The key is stated in the subclass's own templates (`key-of<Data>`), which are not substituted
                // here: decline rather than leak them. Templates of the calling function are fine.
                return self::containsClassTemplate($value) ? null : $value;
            }

            if (\strcasecmp($forwarded->defining_class, $storage->name) === 0) {
                $position = \array_search($forwarded->param_name, \array_keys($storage->template_types ?? []), true);

                return $position === false ? null : ($typeParams[$position] ?? null);
            }

            $value = $storage->template_extended_params[$forwarded->defining_class][$forwarded->param_name] ?? null;
        }

        return null;
    }

    /**
     * Whether a template declared by a class (not by the calling function) appears at any depth.
     */
    private static function containsClassTemplate(Union $type): bool
    {
        $visitor = new class extends TypeVisitor {
            /** @psalm-pure */
            #[\Override]
            protected function enterNode(TypeNode $type): ?int
            {
                // The visitor does not descend into the operand of an unresolved `key-of<>` / `value-of<>`, which only
                // survives expansion when it depends on a template.
                $classTemplate = $type instanceof TKeyOf
                    || $type instanceof TValueOf
                    || (
                        ($type instanceof TTemplateParam
                            || $type instanceof TTemplateKeyOf
                            || $type instanceof TTemplateValueOf
                            || $type instanceof TTemplateIndexedAccess
                            || $type instanceof TTemplateParamClass
                            || $type instanceof TTemplatePropertiesOf
                            || $type instanceof TConditional)
                        && !\str_starts_with($type->defining_class, 'fn-')
                    );

                return $classTemplate ? self::STOP_TRAVERSAL : null;
            }
        };

        // traverse() returns false exactly when enterNode() stopped the traversal.
        return !$visitor->traverse($type);
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
