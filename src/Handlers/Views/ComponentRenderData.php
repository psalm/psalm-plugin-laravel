<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use Illuminate\View\InvokableComponentVariable;
use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\NodeFinder;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\TypeExpander;
use Psalm\LaravelPlugin\Internal\Ast\ClassMethodResolver;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Union;

/**
 * What `Illuminate\View\Component::data()` merges into the view a component's `render()` returns,
 * on top of whatever that `render()` passed itself.
 *
 * Without this, every class component is a {@see \Psalm\LaravelPlugin\Issues\MissingViewData}
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
 * {@see self::forSource()} keeps them supplied (a superset of the real set — the safe direction
 * there, because a name in the set only ever silences a report) but untyped: Laravel then leaves the
 * value the call passes under that key. {@see self::guaranteedFor()}
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
        $storage = ViewClassProperties::storage($codebase, $fqClassName);

        if (!$storage instanceof ClassLikeStorage || !isset($storage->parent_classes[self::COMPONENT])) {
            return null;
        }

        $exposed = self::exposed($codebase, $storage, false);

        return $exposed === null ? [[], false] : [$exposed, true];
    }

    /**
     * The keys `Component::data()` hands the view of a class rendered as `<x-…>`, typed by what
     * every render of the class guarantees: declared property types (`self`/`parent` resolved against
     * the class declaring the property), and a method's runtime wrapper. A property with no declared type, or one declared by a
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
            || self::constructorMentionsExcept($codebase, $storage)
        ) {
            return null;
        }

        $exposed = self::exposed($codebase, $storage, true);

        if ($exposed === null) {
            return null;
        }

        $guaranteed = [];

        foreach ($exposed as $name => $type) {
            // A property type arrives expanded against its declaring class, so this pass leaves it as is.
            // It stays for the method variables: Psalm's own expansion does not recurse into everything
            // (`public-properties-of<self>` keeps a raw `parent` inside its array), and a type seeded with
            // one makes a call on it throw.
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

        $methods = self::publicMethods($codebase, $storage, $typedOnly ? [] : self::exceptedNames($codebase, $storage));

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
     * The property nodes a taint flow into `data()`'s variables can come from: `Class::$name`, per name
     * that really reaches the view. Narrower than the typing side's superset, because here a name in the
     * set only ever REPORTS:
     *
     *  - a name in a literal `$except` default is dropped (a non-literal one keeps every name);
     *  - a public method of the same name, which wins the variable in `data()`, is not consulted (an
     *    accepted over-report);
     *  - the class is the one Psalm writes the property node for: the declaring class, or for a trait
     *    property the (farthest) class using the trait, never the trait itself or an unrelated user of it.
     *
     * Empty when a declaring class could not be resolved: nothing is connected rather than guessed.
     *
     * @return array<string, string> variable name => property id
     *
     * @psalm-capabilities read-props
     */
    public static function exposedPropertyIds(Codebase $codebase, ClassLikeStorage $storage): array
    {
        $except = self::exceptedNames($codebase, $storage) ?? [];
        $ids = [];

        foreach (ViewClassProperties::publicOf($codebase, $storage, self::ignored(...)) ?? [] as $name => [$declaring, $property]) {
            if ($property->is_static === true || \in_array($name, $except, true)) {
                continue;
            }

            $ids[$name] = self::nodeClass($codebase, $storage, $declaring) . '::$' . $name;
        }

        return $ids;
    }

    /**
     * The class whose property node Psalm writes for `$this->name = ...`: the declaring class, or for a
     * trait the farthest class of the hierarchy that uses it. Psalm links no subclass id to an inherited
     * property's node, so the concrete class's `Card::$message` would never be written.
     *
     * @psalm-capabilities read-props
     */
    private static function nodeClass(Codebase $codebase, ClassLikeStorage $storage, ClassLikeStorage $declaring): string
    {
        if (!$declaring->is_trait) {
            return $declaring->name;
        }

        $trait = \strtolower($declaring->name);
        $class = $storage->name;

        foreach (\array_keys($storage->parent_classes) as $parent) {
            $parentStorage = ViewClassProperties::storage($codebase, $parent);

            if ($parentStorage instanceof ClassLikeStorage && isset($parentStorage->used_traits[$trait])) {
                $class = $parentStorage->name;
            }
        }

        return $class;
    }

    /**
     * @param bool $typedOnly skip a property with no declared type, or one a generic class declares
     *
     * @return array<string, Union>|null null when a declaring class could not be resolved, which
     *         makes the exposed set unknowable rather than empty
     */
    private static function publicProperties(Codebase $codebase, ClassLikeStorage $storage, bool $typedOnly): ?array
    {
        $declared = ViewClassProperties::publicOf($codebase, $storage, self::ignored(...));

        if ($declared === null) {
            return null;
        }

        // `forSource()` keeps an excluded name as supplied (a superset), but must not type it: Laravel then
        // leaves the value the call passes in place. `guaranteedFor()` already declines on a userland `$except`.
        $excepted = $typedOnly ? [] : self::exceptedNames($codebase, $storage);
        $properties = [];

        foreach ($declared as $name => [$declaring, $property]) {
            if ($property->is_static === true || ($typedOnly && ($property->type === null || $declaring->template_types !== null))) {
                continue;
            }

            if ($excepted === null || \in_array($name, $excepted, true)) {
                $properties[$name] = Type::getMixed();

                continue;
            }

            // `static` collapses to the class for the view's own seed and for `forSource()`; the
            // `<x-…>` side keeps a non-final class's late-static marker, as its types always did.
            $properties[$name] = ViewClassProperties::typeOf($codebase, $storage, $name, $declaring, $property, final: !$typedOnly || $storage->final)
                ?? Type::getMixed();
        }

        return $properties;
    }

    /**
     * The names a userland `$except` default lists, `[]` when the class does not redeclare it, and null when
     * the list is not readable from storage: Psalm keeps a default only for an untyped property, as
     * `list{'a', 'b'}`, so a `@var`-typed or computed default is unknown.
     *
     * @return list<string>|null
     *
     * @psalm-capabilities read-props
     */
    private static function exceptedNames(Codebase $codebase, ClassLikeStorage $storage): ?array
    {
        // The default is not the value `data()` reads once a constructor has replaced or extended it.
        if (self::constructorMentionsExcept($codebase, $storage)) {
            return null;
        }

        $declaringClass = $storage->declaring_property_ids['except'] ?? self::COMPONENT;

        if (\strtolower($declaringClass) === self::COMPONENT) {
            return [];
        }

        $declaring = ViewClassProperties::storage($codebase, $declaringClass);
        $default = $declaring?->properties['except']->suggested_type ?? null;
        $atomic = $default?->isSingle() === true ? $default->getSingleAtomic() : null;

        if ($atomic instanceof TArray && $atomic->isEmptyArray()) {
            return [];
        }

        if (!$atomic instanceof TKeyedArray || $atomic->fallback_params !== null) {
            return null;
        }

        $names = [];

        foreach ($atomic->properties as $item) {
            if (!$item->isSingleStringLiteral()) {
                return null;
            }

            $names[] = $item->getSingleStringLiteral()->value;
        }

        return $names;
    }

    /**
     * Whether a constructor the class runs (its own, or one a parent declares) touches `$this->except`.
     * A constructor Psalm cannot locate in source (a trait's) is not read.
     *
     * @psalm-capabilities read-props
     */
    private static function constructorMentionsExcept(Codebase $codebase, ClassLikeStorage $storage): bool
    {
        $finder = new NodeFinder();
        $seen = [];

        foreach ([$storage->name, ...\array_keys($storage->parent_classes)] as $className) {
            $class = ViewClassProperties::storage($codebase, $className);
            $constructor = $class?->declaring_method_ids['__construct'] ?? null;

            if (!$constructor instanceof MethodIdentifier || isset($seen[(string) $constructor])) {
                continue;
            }

            $seen[(string) $constructor] = true;
            $resolved = ClassMethodResolver::resolve($codebase, $constructor);

            $mention = $finder->findFirst(
                $resolved['classMethod']->stmts ?? [],
                static fn(Node $node): bool => $node instanceof PropertyFetch
                    && $node->var instanceof Variable
                    && $node->var->name === 'this'
                    && $node->name instanceof Identifier
                    && $node->name->name === 'except',
            );

            if ($mention instanceof Node) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every public method becomes a variable of the same (cased) name, built by
     * `Component::createVariableFromMethod()`: an `InvokableComponentVariable` of the method's return
     * type for a method with no parameters at all (optional ones count), a `Closure` of the method's
     * signature otherwise.
     *
     * @param list<string>|null $excepted the names `$except` removes: `shouldIgnore()` is shared with the
     *        properties, so an excluded method keeps the value the call passes and is `mixed` here, the
     *        way {@see self::publicProperties()} treats a property; null when the list is unreadable,
     *        which leaves every method that way
     *
     * @return array<string, Union>|null
     */
    private static function publicMethods(Codebase $codebase, ClassLikeStorage $storage, ?array $excepted): ?array
    {
        $methods = [];

        foreach ($storage->declaring_method_ids as $methodName => $methodId) {
            if (self::ignored($methodName)) {
                continue;
            }

            $declaring = ViewClassProperties::storage($codebase, $methodId->fq_class_name);

            if (!$declaring instanceof ClassLikeStorage) {
                return null;
            }

            $method = $declaring->methods[$methodName] ?? null;

            if ($method === null || $method->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
                continue;
            }

            $casedName = $method->cased_name ?? $methodName;

            if ($excepted === null || \in_array($casedName, $excepted, true)) {
                $methods[$casedName] = Type::getMixed();

                continue;
            }

            $methods[$casedName] = $method->params === []
                ? self::invokableOf($codebase, $storage, $declaring, $method, $methodName)
                : self::closureOf($codebase, $storage, $declaring, $method, $methodName);
        }

        return $methods;
    }

    /**
     * `$name()` is `call_user_func()` of the method, so the wrapper carries its return type (`null`
     * for `void`). Without a declared return type the variable is `mixed`, i.e. not checked, as for a
     * generic method or declaring class: `InvokableComponentVariable<mixed>` would reject a
     * template's correct `InvokableComponentVariable<TValue>` declaration.
     */
    private static function invokableOf(
        Codebase $codebase,
        ClassLikeStorage $storage,
        ClassLikeStorage $declaring,
        MethodStorage $method,
        string $methodName,
    ): Union {
        $expand = self::expanderFor($codebase, $storage, $declaring, $method, $methodName);

        if (!$expand instanceof \Closure || !$method->return_type instanceof Union) {
            return Type::getMixed();
        }

        $returnType = $expand($method->return_type);

        return new Union([
            new TGenericObject(InvokableComponentVariable::class, [$returnType->isVoid() ? Type::getNull() : $returnType]),
        ]);
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
        $expand = self::expanderFor($codebase, $storage, $declaring, $method, $methodName);

        if (!$expand instanceof \Closure) {
            return Type::getMixed();
        }

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
     * Resolves a method's types as the rendered component sees them, or null when nothing can: a
     * generic method or declaring class (no template is bound here), or an unresolvable appearing class.
     *
     * @return (\Closure(Union): Union)|null
     */
    private static function expanderFor(
        Codebase $codebase,
        ClassLikeStorage $storage,
        ClassLikeStorage $declaring,
        MethodStorage $method,
        string $methodName,
    ): ?\Closure {
        if ($method->template_types !== null || $declaring->template_types !== null) {
            return null;
        }

        // `self` and `parent` name the class the method appears in (a trait's user, not the trait);
        // only `static` is the rendered component.
        $appearingId = $storage->appearing_method_ids[$methodName] ?? null;
        $appearing = $appearingId === null ? null : ViewClassProperties::storage($codebase, $appearingId->fq_class_name);

        if (!$appearing instanceof ClassLikeStorage) {
            return null;
        }

        // `final: true` collapses `static` to the component: a subclass instance is still one, and
        // `Component&static` means nothing to a template.
        return static fn(Union $type): Union => TypeExpander::expandUnion(
            $codebase,
            $type,
            $appearing->name,
            $storage->name,
            $appearing->parent_class,
            final: true,
        );
    }

    /**
     * @psalm-pure
     */
    private static function ignored(string $name): bool
    {
        return \str_starts_with($name, '__') || \in_array(\strtolower($name), self::IGNORED, true);
    }
}
