<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

/**
 * Resolves what a relation-query call (`whereHas()`, `has()`, ...) runs on, for
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\RelationCallbackParamsHandler}: the model whose relations the call
 * names ({@see receiverModel()}) and whether the dispatched method is Laravel's own signature
 * ({@see laravelDeclaring()}). Anything not provably one model's builder resolves to null, so the caller declines.
 *
 * @internal
 */
final class RelationQueryReceiver
{
    /**
     * Declaring classes whose signature is Laravel's own (the plugin stub and the trait it mirrors).
     *
     * @var list<lowercase-string>
     */
    private const LARAVEL_DECLARING_CLASSES = [
        'illuminate\\database\\eloquent\\builder',
        'illuminate\\database\\eloquent\\concerns\\queriesrelationships',
    ];

    /**
     * The declaring method of `$class::$method` when it is Laravel's own: declared by Eloquent's Builder or its
     * `QueriesRelationships` trait, and not replaced by another trait. Psalm ignores `insteadof`
     * (vimeo/psalm#12113), so such an override still reports Laravel's trait as the declaring one. Unknown
     * classes decline.
     *
     * @psalm-capabilities read-props
     */
    public static function laravelDeclaring(Codebase $codebase, string $class, string $method): ?MethodIdentifier
    {
        $declaring = $codebase->getDeclaringMethodId($class . '::' . $method);
        $declaring = $declaring === null ? null : MethodIdentifier::wrap($declaring);

        return $declaring instanceof MethodIdentifier
            && \in_array(\strtolower($declaring->fq_class_name), self::LARAVEL_DECLARING_CLASSES, true)
            && !self::traitOverrides($codebase, $class, $method)
            ? $declaring
            : null;
    }

    /**
     * The model the receiver's relations belong to, and whether the call forwards through that model's own builder
     * (a static call, or a Relation) rather than running on the receiver itself: a single-atomic Builder of exactly
     * the dispatched class, a Relation (it forwards to `Builder`) via its TRelatedModel, or the named model of a
     * static call.
     *
     * @return array{class-string<Model>, bool}|null
     */
    public static function receiverModel(
        StatementsAnalyzer $source,
        MethodCall|NullsafeMethodCall|StaticCall $call,
        string $dispatched,
    ): ?array {
        $codebase = $source->getCodebase();
        $isBuilderDispatch = \strtolower($dispatched) === \strtolower(Builder::class);

        if ($call instanceof StaticCall) {
            $class = $call->class instanceof Name
                ? ClassLikeAnalyzer::getFQCLNFromNameObject($call->class, $source->getAliases())
                : null;

            return $isBuilderDispatch
                && $class !== null
                && ClassLineage::isA($codebase, $class, Model::class)
                ? [$class, true]
                : null;
        }

        $receiver = $source->getNodeTypeProvider()->getType($call->var);
        $atomics = $receiver instanceof Union
            ? \array_values(\array_filter($receiver->getAtomicTypes(), static fn($atomic): bool => !$atomic instanceof TNull))
            : [];
        $atomic = $atomics[0] ?? null;

        if (\count($atomics) !== 1 || !$atomic instanceof TNamedObject) {
            return null;
        }

        $forwards = ClassLineage::isA($codebase, $atomic->value, Relation::class);
        if (!$forwards && \strtolower($atomic->value) !== \strtolower($dispatched)) {
            return null;
        }

        $model = $forwards
            ? ($isBuilderDispatch ? self::projectedModel($codebase, $atomic, Relation::class, 'TRelatedModel') : null)
            : self::projectedModel($codebase, $atomic, Builder::class, 'TModel');

        return $model === null ? null : [$model, $forwards];
    }

    /**
     * The model a receiver binds an ancestor's template to (`Builder::TModel`, `Relation::TRelatedModel`): the ancestor's
     * own argument, a concrete model the subclass extends it with, or the receiver's type param at the position of the
     * template it forwards, matched by the defining class (a name match alone would read another slot). Anything else
     * names no model.
     *
     * @param class-string $ancestor
     * @return class-string<Model>|null
     * @psalm-capabilities read-props
     */
    private static function projectedModel(Codebase $codebase, TNamedObject $atomic, string $ancestor, string $template): ?string
    {
        $typeParams = $atomic instanceof TGenericObject ? $atomic->type_params : [];
        if (\strtolower($atomic->value) === \strtolower($ancestor)) {
            return ModelPropertyResolver::extractExactlyOneModelFromUnion($typeParams[0] ?? null, $codebase);
        }

        try {
            $storage = $codebase->classlike_storage_provider->get($atomic->value);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }

        // `template_extended_params` states each ancestor's template in its DIRECT child's terms, so a forwarded
        // template (`TRelatedModel:HasOneOrMany`) is followed up the chain until the receiver's own template.
        $value = $storage->template_extended_params[$ancestor][$template] ?? null;
        for ($hops = 0; $hops < 8 && $value instanceof Union; ++$hops) {
            $concrete = ModelPropertyResolver::extractExactlyOneModelFromUnion($value, $codebase);
            $forwarded = $value->isSingle() ? $value->getSingleAtomic() : null;
            if ($concrete !== null || !$forwarded instanceof TTemplateParam) {
                return $concrete;
            }

            if (\strcasecmp($forwarded->defining_class, $storage->name) === 0) {
                $position = \array_search($forwarded->param_name, \array_keys($storage->template_types ?? []), true);

                return $position === false ? null : ModelPropertyResolver::extractExactlyOneModelFromUnion($typeParams[$position] ?? null, $codebase);
            }

            $value = $storage->template_extended_params[$forwarded->defining_class][$forwarded->param_name] ?? null;
        }

        return null;
    }

    /**
     * Whether a trait other than Laravel's `QueriesRelationships` declares the method on the class or an ancestor.
     * Psalm ignores `insteadof` (vimeo/psalm#12113), so such an override still reports Laravel's trait as the
     * declaring one. Unknown classes count as overridden.
     *
     * @psalm-capabilities read-props
     */
    private static function traitOverrides(Codebase $codebase, string $class, string $method): bool
    {
        $provider = $codebase->classlike_storage_provider;
        $seen = [];

        try {
            foreach ([$class, ...\array_values($provider->get($class)->parent_classes)] as $name) {
                $queue = \array_values($provider->get($name)->used_traits);
                while (($trait = \array_pop($queue)) !== null) {
                    if (isset($seen[\strtolower($trait)])) {
                        continue;
                    }

                    $seen[\strtolower($trait)] = true;
                    $traitStorage = $provider->get($trait);

                    if (isset($traitStorage->methods[$method])) {
                        if (\strtolower($trait) !== self::LARAVEL_DECLARING_CLASSES[1]) {
                            return true;
                        }
                    } else {
                        \array_push($queue, ...\array_values($traitStorage->used_traits));
                    }
                }
            }
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return true;
        }

        return false;
    }
}
