<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
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
 * `data()` is `extractPublicProperties() + extractPublicMethods()`, both filtered by the shared
 * `shouldIgnore()` — a `__`-prefixed name, or one of `ignoredMethods()`. Properties additionally
 * drop statics; methods do not.
 *
 * `$except` and an overridden `ignoredMethods()` only ever REMOVE names, so ignoring them leaves
 * this set a superset of the real one — the safe direction, because a name in the set only ever
 * silences a report. An overridden `data()` can ADD names, which nothing static can enumerate, so
 * that one opens the set instead.
 *
 * @internal
 */
final class ComponentRenderData
{
    private const COMPONENT = 'illuminate\view\component';

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

        $declaresData = $storage->declaring_method_ids['data'] ?? null;

        if ($declaresData instanceof MethodIdentifier
            && \strtolower($declaresData->fq_class_name) !== self::COMPONENT
        ) {
            // A userland data() can add keys no static walk can enumerate.
            return [[], false];
        }

        $data = self::publicProperties($codebase, $storage);

        if ($data === null) {
            return [[], false];
        }

        $methods = self::publicMethods($codebase, $storage);

        if ($methods === null) {
            return [[], false];
        }

        // Properties win: Laravel's array_merge() puts the method array second, but a name cannot be
        // both a property and a method, so the order only matters for the types carried here.
        return [$data + $methods, true];
    }

    /**
     * @return array<string, Union>|null null when a declaring class could not be resolved, which
     *         makes the exposed set unknowable rather than empty
     *
     * @psalm-mutation-free
     */
    private static function publicProperties(Codebase $codebase, ClassLikeStorage $storage): ?array
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

            $properties[$name] = $property->type ?? Type::getMixed();
        }

        return $properties;
    }

    /**
     * Every public method becomes an invokable variable of the same (cased) name. Its value is a
     * closure Laravel builds at render time, so the type is `mixed`: a template that calls it is
     * checked by nothing here, and a declaration for it would compare against the wrong thing.
     *
     * @return array<string, Union>|null
     *
     * @psalm-mutation-free
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

            $methods[$method->cased_name ?? $methodName] = Type::getMixed();
        }

        return $methods;
    }

    /**
     * `Component::shouldIgnore()`: a name `data()` never exposes. Shared with
     * {@see \Psalm\LaravelPlugin\Blade\ComponentViewMap}, which applies the same rule to reflection.
     *
     * @psalm-pure
     */
    public static function ignored(string $name): bool
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
