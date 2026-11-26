<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\ForwardsCalls;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;

/**
 * Whether a model's query is built by Laravel's own Model code on a builder the plugin knows. Shared by the
 * eager-load constraint typing ({@see \Psalm\LaravelPlugin\Handlers\Eloquent\RelationCallbackParamsHandler}) and
 * the column-list check ({@see \Psalm\LaravelPlugin\Handlers\Rules\UnknownModelAttributeHandler}), so both
 * decline on the same overrides.
 *
 * @internal
 */
final class ModelQueryPath
{
    /**
     * The Model methods that build a model's query, each declared by Model itself (its magic forwarding is
     * checked next to them, see {@see modelForwardingIsLaravels()}).
     *
     * @var non-empty-list<lowercase-string>
     */
    private const MODEL_QUERY_PATH = [
        'query',
        'newquery',
        'newquerywithoutscopes',
        'newmodelquery',
        'newquerywithoutrelationships',
        'registerglobalscopes',
    ];

    /**
     * Whether a query on `$model` is built by Laravel's own code on a builder the plugin knows: no unknown
     * builder ({@see hasUnknownBuilder()}), every MODEL_QUERY_PATH method declared by Model itself, and Model's
     * own magic forwarding (a static `Model::withOnly()` reaches the builder through it).
     *
     * @param class-string<Model> $model
     */
    public static function isLaravels(Codebase $codebase, string $model): bool
    {
        if (self::hasUnknownBuilder($codebase, $model)) {
            return false;
        }

        foreach (self::MODEL_QUERY_PATH as $method) {
            if (!self::declaredBy($codebase, $model, $method, Model::class) instanceof MethodIdentifier) {
                return false;
            }
        }

        return self::modelForwardingIsLaravels($codebase, $model);
    }

    /**
     * Whether the builder a model's queries run on is one the plugin does not know: `newEloquentBuilder()` is
     * overridden (it wins over `#[UseEloquentBuilder]` and `$builder`) yet {@see ModelMethodHandler} holds no custom
     * builder for the model, so it resolves to base `Builder` and would reject the real one. The detector's own result
     * is asked instead of re-deriving its rule from Psalm storage, which cannot see its interface/nullable
     * outcomes. The one legitimate base builder is an override whose EFFECTIVE declared type
     * (docblock over native) is exactly `Builder`, optionally nullable or generic, with no intersection. Unreadable
     * storage counts as unknown.
     */
    public static function hasUnknownBuilder(Codebase $codebase, string $model): bool
    {
        if (ModelMethodHandler::getBuilderClassForModel($model) !== Builder::class) {
            return false;
        }

        try {
            $declaring = $codebase->getDeclaringMethodId($model . '::neweloquentbuilder');
            $declaring = $declaring === null ? null : MethodIdentifier::wrap($declaring);

            if (!$declaring instanceof MethodIdentifier) {
                return true;
            }

            if (\strtolower($declaring->fq_class_name) === \strtolower(Model::class)) {
                return false;
            }

            $type = $codebase->methods->getStorage($declaring)->return_type;
        } catch (\UnexpectedValueException|\InvalidArgumentException|UnpopulatedClasslikeException) {
            return true;
        }

        $atomics = \array_values(\array_filter($type?->getAtomicTypes() ?? [], static fn(\Psalm\Type\Atomic $atomic): bool => !$atomic instanceof TNull));
        $base = $atomics[0] ?? null;

        return \count($atomics) !== 1
            || !$base instanceof TNamedObject
            || \strcasecmp($codebase->classlikes->getUnAliasedName($base->value), Builder::class) !== 0
            || $base->extra_types !== [];
    }

    /**
     * Whether `$class::$method` is declared by `$declaringClass` itself: a userland override, or a trait that
     * supplies one, makes another class the declaring one.
     *
     * @psalm-pure
     */
    public static function declaredBy(Codebase $codebase, string $class, string $method, string $declaringClass): ?MethodIdentifier
    {
        $declaring = $codebase->getDeclaringMethodId($class . '::' . $method);
        $declaring = $declaring === null ? null : MethodIdentifier::wrap($declaring);

        return $declaring instanceof MethodIdentifier && \strcasecmp($declaring->fq_class_name, $declaringClass) === 0 ? $declaring : null;
    }

    /**
     * Whether `$model` forwards calls to its builder through Laravel's own `__call()`/`__callStatic()` and the
     * `ForwardsCalls` helpers they use: an override may hand the builder something else.
     *
     * @param class-string<Model> $model
     * @psalm-pure
     */
    private static function modelForwardingIsLaravels(Codebase $codebase, string $model): bool
    {
        foreach (['__call', '__callstatic'] as $method) {
            if (!self::declaredBy($codebase, $model, $method, Model::class) instanceof MethodIdentifier) {
                return false;
            }
        }

        foreach (['forwardcallto', 'forwarddecoratedcallto'] as $method) {
            $declaring = $codebase->getDeclaringMethodId($model . '::' . $method);

            if ($declaring === null || !\in_array(\strtolower(MethodIdentifier::wrap($declaring)->fq_class_name), [\strtolower(Model::class), \strtolower(ForwardsCalls::class)], true)) {
                return false;
            }
        }

        return true;
    }
}
