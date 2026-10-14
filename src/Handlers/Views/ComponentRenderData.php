<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use Illuminate\View\InvokableComponentVariable;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\TypeExpander;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Union;

/**
 * What `Illuminate\View\Component::data()` merges into the view a component's `render()` returns,
 * on top of whatever that `render()` passed itself.
 *
 * Without this, every class component is a {@see \Psalm\LaravelPlugin\Issues\MissingViewVariable}
 * false positive: `render()` typically passes `['component' => $this]` (or nothing at all) while
 * the template reads the component's public properties, which Laravel merges in at render time
 * (`Concerns\CompilesComponents` emits `startComponent($component->resolveView(), $component->data())`,
 * and `ManagesComponents::renderComponent()` folds the same array into the returned View).
 *
 * Exposure rules are read off `vendor/laravel/framework/src/Illuminate/View/Component.php`:
 * `data()` is `array_merge(extractPublicProperties(), extractPublicMethods())`, both filtered by
 * the shared `shouldIgnore()` — a `__`-prefixed name, or one of `ignoredMethods()`. Properties
 * additionally drop statics; methods do not. A method wins a name a property also has.
 *
 * `$except` and an overridden `ignoredMethods()` / `shouldIgnore()` only ever REMOVE names, so
 * {@see self::forSource()} ignores them and stays a superset of the real set — the safe direction
 * there, because a name in the set only ever silences a report. {@see self::guaranteedFor()}
 * declares the names as present, so it declines on them instead. An overridden `data()` or
 * extraction method can ADD names or change their values, which nothing static can enumerate, so
 * both consumers treat it as unknowable.
 *
 * @internal
 */
final class ComponentRenderData
{
    private const COMPONENT = 'illuminate\view\component';

    /** Userland overrides that can add names or change the values `data()` returns. */
    private const OPENING_OVERRIDES = ['data', 'extractpublicproperties', 'extractpublicmethods', 'createvariablefrommethod'];

    /** Userland overrides that can only remove names from what `data()` returns. */
    private const NARROWING_OVERRIDES = ['ignoredmethods', 'shouldignore'];

    /**
     * `Component::ignoredMethods()`, lowercased. Applied to properties too: `shouldIgnore()` is
     * shared between the two extractors.
     *
     * @var list<lowercase-string>
     */
    private const IGNORED = [
        'data',
        'render',
        'resolve',
        'resolveview',
        'shouldrender',
        'view',
        'withname',
        'withattributes',
        'flushcache',
        'forgetfactory',
        'forgetcomponentsresolver',
        'resolvecomponentsusing',
    ];

    /**
     * The keys Laravel merges into a view rendered from the class currently being analyzed.
     *
     * @return array{0: array<string, Union>, 1: bool}|null the merged keys and whether they are the
     *         whole of what `data()` contributes, or null when the analyzed class is not a
     *         `Component` subclass and nothing is merged at all
     */
    public static function forSource(StatementsSource $source): ?array
    {
        $fqClassName = $source->getFQCLN();

        if ($fqClassName === null) {
            return null;
        }

        $codebase = $source->getCodebase();
        $storage = self::storage($codebase, $fqClassName);

        if (!$storage instanceof ClassLikeStorage || !isset($storage->parent_classes[self::COMPONENT])) {
            return null;
        }

        $exposed = self::exposed($codebase, $storage, false);

        return $exposed === null ? [[], false] : [$exposed, true];
    }

    /**
     * The keys `Component::data()` hands the view of a class rendered as `<x-…>`, typed by what
     * every render of the class guarantees: declared property types expanded against the class,
     * and a method's runtime wrapper. A property with no declared type, or one declared by a
     * generic class, is left out rather than guessed.
     *
     * @return array<string, Union>|null null when the key set is not knowable: a userland
     *         `$except`, a narrowing or opening override, or an unresolvable declaring class
     */
    public static function guaranteedFor(Codebase $codebase, ClassLikeStorage $storage): ?array
    {
        if (!isset($storage->parent_classes[self::COMPONENT])
            || self::overridesAny($storage, self::NARROWING_OVERRIDES)
            || \strtolower($storage->declaring_property_ids['except'] ?? '') !== self::COMPONENT
        ) {
            return null;
        }

        $exposed = self::exposed($codebase, $storage, true);

        if ($exposed === null) {
            return null;
        }

        $guaranteed = [];

        foreach ($exposed as $name => $type) {
            // A method closure is already expanded with `static` collapsed, so this pass leaves it as is.
            $expanded = TypeExpander::expandUnion(
                $codebase,
                $type,
                $storage->name,
                $storage->name,
                $storage->parent_class,
                final: $storage->final,
            );

            if (!$expanded->isMixed()) {
                $guaranteed[$name] = $expanded;
            }
        }

        return $guaranteed;
    }

    /**
     * @param bool $typedOnly skip a property with no declared type or a generic declaring class
     *
     * @return array<string, Union>|null
     */
    private static function exposed(Codebase $codebase, ClassLikeStorage $storage, bool $typedOnly): ?array
    {
        if (self::overridesAny($storage, self::OPENING_OVERRIDES)) {
            return null;
        }

        $data = self::publicProperties($codebase, $storage, $typedOnly);

        if ($data === null) {
            return null;
        }

        $methods = self::publicMethods($codebase, $storage);

        if ($methods === null) {
            return null;
        }

        // array_merge() with the method array second: a method wins a name a property also has.
        return $methods + $data;
    }

    /**
     * @param list<lowercase-string> $methodNames
     *
     * @psalm-mutation-free
     */
    private static function overridesAny(ClassLikeStorage $storage, array $methodNames): bool
    {
        foreach ($methodNames as $methodName) {
            $declaring = $storage->declaring_method_ids[$methodName] ?? null;

            if ($declaring instanceof MethodIdentifier && \strtolower($declaring->fq_class_name) !== self::COMPONENT) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param bool $typedOnly skip a property with no declared type, or one a generic class declares
     *
     * @return array<string, Union>|null null when a declaring class could not be resolved, which
     *         makes the exposed set unknowable rather than empty
     *
     * @psalm-mutation-free
     */
    private static function publicProperties(Codebase $codebase, ClassLikeStorage $storage, bool $typedOnly): ?array
    {
        $properties = [];

        foreach ($storage->declaring_property_ids as $name => $declaringClass) {
            if (self::ignored($name)) {
                continue;
            }

            $declaring = self::storage($codebase, $declaringClass);

            if (!$declaring instanceof ClassLikeStorage) {
                return null;
            }

            $property = $declaring->properties[$name] ?? null;

            if ($property === null
                || $property->is_static === true
                || $property->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC
            ) {
                continue;
            }

            if ($typedOnly && ($property->type === null || $declaring->template_types !== null)) {
                continue;
            }

            $properties[$name] = $property->type ?? Type::getMixed();
        }

        return $properties;
    }

    /**
     * Every public method becomes a variable of the same (cased) name, built by
     * `Component::createVariableFromMethod()`: an `InvokableComponentVariable` of the method's return
     * type for a method with no parameters at all (optional ones count), a `Closure` of the method's
     * signature otherwise.
     *
     * @return array<string, Union>|null
     */
    private static function publicMethods(Codebase $codebase, ClassLikeStorage $storage): ?array
    {
        $methods = [];

        foreach ($storage->declaring_method_ids as $methodName => $methodId) {
            if (self::ignored($methodName)) {
                continue;
            }

            $declaring = self::storage($codebase, $methodId->fq_class_name);

            if (!$declaring instanceof ClassLikeStorage) {
                return null;
            }

            $method = $declaring->methods[$methodName] ?? null;

            if ($method === null || $method->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
                continue;
            }

            if ($method->params !== []) {
                $methods[$method->cased_name ?? $methodName] = self::closureOf($codebase, $storage, $declaring, $method, $methodName);

                continue;
            }

            // A trait's `self` and `parent` are the class that uses it, which Psalm records as the
            // method's appearing class.
            $appearing = $declaring->is_trait
                ? self::storage($codebase, ($storage->appearing_method_ids[$methodName] ?? $methodId)->fq_class_name)
                : $declaring;

            if (!$appearing instanceof ClassLikeStorage) {
                return null;
            }

            $methods[$method->cased_name ?? $methodName] = new Union([
                new TGenericObject(InvokableComponentVariable::class, [self::returnType($codebase, $storage, $declaring, $appearing, $method)]),
            ]);
        }

        return $methods;
    }

    /**
     * `static` is the component instance itself, so it resolves to the component as if final: the
     * late-static marker would otherwise leak into the view as `Component&static`. `mixed` when
     * unknown: no declared return type, or one carrying a template (the generic declaring class's
     * or the method's own), which nothing binds here.
     */
    private static function returnType(
        Codebase $codebase,
        ClassLikeStorage $storage,
        ClassLikeStorage $declaring,
        ClassLikeStorage $appearing,
        MethodStorage $method,
    ): Union {
        if (!$method->return_type instanceof Union || $declaring->template_types !== null) {
            return Type::getMixed();
        }

        $expanded = TypeExpander::expandUnion(
            $codebase,
            $method->return_type,
            $appearing->name,
            $storage->name,
            $appearing->parent_class,
            final: true,
        );

        // A method-level template is never bound here. `call_user_func()` of a void method is null.
        return match (true) {
            $expanded->getTemplateTypes() !== [] => Type::getMixed(),
            $expanded->isVoid() => Type::getNull(),
            default => $expanded,
        };
    }

    /**
     * `Closure::fromCallable([$this, $name])` keeps the method's signature, so the variable is that
     * signature with Psalm's own slots: an untyped parameter or return stays unknown, as it does for
     * a first-class callable, and the method's own purity carries over.
     *
     * A generic method or declaring class is `mixed`, i.e. not checked: nothing binds its templates
     * here, and a bare `Closure` would reject a template's correct `\Closure(...)` declaration.
     */
    private static function closureOf(
        Codebase $codebase,
        ClassLikeStorage $storage,
        ClassLikeStorage $declaring,
        MethodStorage $method,
        string $methodName,
    ): Union {
        if ($method->template_types !== null || $declaring->template_types !== null) {
            return Type::getMixed();
        }

        // `self` and `parent` name the class the method appears in (a trait's user, not the trait);
        // only `static` is the rendered component.
        $appearingId = $storage->appearing_method_ids[$methodName] ?? null;
        $appearing = $appearingId === null ? null : self::storage($codebase, $appearingId->fq_class_name);

        if (!$appearing instanceof ClassLikeStorage) {
            return Type::getMixed();
        }

        // `final: true` collapses `static` to the component: a subclass instance is still one, and
        // `Component&static` means nothing to a template.
        $expand = static fn(Union $type): Union => TypeExpander::expandUnion(
            $codebase,
            $type,
            $appearing->name,
            $storage->name,
            $appearing->parent_class,
            final: true,
        );

        // TypeExpander skips a parameter's `@param-out` type (a gap in Psalm's own callable expansion),
        // and an unexpanded `self` there becomes the by-ref variable's type in the template.
        $params = [];

        foreach ($method->params as $param) {
            $outType = $param->out_type;

            if ($outType instanceof Union) {
                $param = clone $param;
                $param->out_type = $expand($outType);
            }

            $params[] = $param;
        }

        return $expand(new Union([new TClosure($params, $method->return_type, $method->capabilities)]));
    }

    /**
     * @psalm-pure
     */
    private static function ignored(string $name): bool
    {
        return \str_starts_with($name, '__') || \in_array(\strtolower($name), self::IGNORED, true);
    }

    /**
     * @psalm-mutation-free
     */
    private static function storage(Codebase $codebase, string $fqClassName): ?ClassLikeStorage
    {
        try {
            return $codebase->classlike_storage_provider->get(\strtolower($fqClassName));
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }
    }
}
