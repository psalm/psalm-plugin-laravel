<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use PhpParser\Node\Arg;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\NodeTypeProvider;
use Psalm\Type;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParamClass;
use Psalm\Type\Union;

final class ContainerResolver
{
    /**
     * map of abstract to concrete class fqn
     * @psalm-var array<string, class-string|string>
     */
    private static array $cache = [];

    /**
     * @psalm-return class-string|string|null
     */
    private static function resolveFromApplicationContainer(string $abstract): ?string
    {
        if (\array_key_exists($abstract, self::$cache)) {
            return self::$cache[$abstract];
        }

        // dynamic analysis to resolve the actual type from the container.
        // Narrowed annotation: every abstract this resolver receives is a service or
        // path-helper; both Container::make() return shapes are object|string. null
        // covers the unbound-Authenticatable case below. Closure-bound abstracts that
        // return arbitrary scalars/arrays are out of scope for this plugin.
        try {
            /** @psalm-var object|string|null $concrete */
            $concrete = ApplicationProvider::getApp()->make($abstract);
        } catch (\Throwable) {
            return null;
        }

        if (\is_string($concrete)) {
            // some path-helpers actually return a string when being resolved
            $concreteClass = $concrete;
        } elseif (\is_object($concrete)) {
            // normally we have an object resolved
            $concreteClass = $concrete::class;
        } else {
            // Some Laravel bindings (e.g. Authenticatable on a fresh Testbench app
            // with no authenticated user) resolve to null. The previous assert-based
            // check was a no-op in production, letting `$concrete::class` crash on
            // null. Return null so the caller falls back to mixed inference.
            return null;
        }

        self::$cache[$abstract] = $concreteClass;

        return $concreteClass;
    }

    /**
     * @param list<Arg> $call_args
     */
    public static function resolvePsalmTypeFromApplicationContainerViaArgs(
        NodeTypeProvider $nodeTypeProvider,
        array $call_args,
    ): ?Union {
        if ($call_args === []) {
            return null;
        }

        $firstArgType = $nodeTypeProvider->getType($call_args[0]->value);
        if (!$firstArgType instanceof \Psalm\Type\Union) {
            return null;
        }

        if ($firstArgType->isSingleStringLiteral()) {
            return self::resolveFromLiteralString($firstArgType->getSingleStringLiteral()->value);
        }

        if (!$firstArgType->isSingle()) {
            return null;
        }

        $atomic = $firstArgType->getSingleAtomic();
        if ($atomic instanceof TClassString) {
            return self::resolveFromClassString($atomic);
        }

        return null;
    }

    private static function resolveFromLiteralString(string $abstract): ?Union
    {
        $concrete = self::resolveFromApplicationContainer($abstract);

        if ($concrete === null) {
            // Container resolution failed: either the abstract is unbound, or the
            // plugin's booted app (Orchestra Testbench, when analysing a Laravel
            // *package* rather than an app) lacks the provider that would register
            // the binding, and the concrete class is not auto-wireable via reflection
            // (protected/private constructor or provider-supplied dependencies).
            // See #757 / umbrella #766.
            //
            // If the abstract is itself a loadable class name, mirror Laravel's
            // runtime: in a real app the owning provider IS loaded, so
            // `app(Foo::class)` returns a `Foo`. Returning the named object here is
            // symmetrical with resolveFromClassString() (#750), which already returns
            // a TNamedObject for `class-string<Foo>` without touching the container.
            //
            // `class_exists()` is both safe and sufficient as the guard: the typical
            // failure (Container::build throwing "not instantiable" / "unresolvable
            // dependency") only happens AFTER `new ReflectionClass($abstract)` has
            // already succeeded, so the class is loaded and `class_exists()` is true.
            // It also correctly excludes interfaces (returns false → mixed) — we never
            // claim an unresolvable contract resolves to itself. We only ever return
            // the abstract itself, a supertype of whatever the runtime would build, so
            // this cannot introduce a false-positive on a member that genuinely exists.
            if (\class_exists($abstract)) {
                return new Union([
                    new TNamedObject($abstract),
                ]);
            }

            return null;
        }

        // todo: is there a better way to check if this is a literal class string?
        if (\class_exists($concrete)) {
            return new Union([
                new TNamedObject($concrete),
            ]);
        }

        // The likes of publicPath, which returns a literal string. Use
        // Type::getAtomicStringFromLiteral() rather than TLiteralString::make(): a binding can
        // resolve to a string at least Config::$max_string_length chars long (e.g. a minified
        // asset blob), and make() throws InvalidArgumentException on those — uncaught, that
        // crashes the whole run under amphp workers. The helper degrades such values to a
        // non-falsy-string supertype instead, keeping the inferred type sound. See #1178.
        return new Union([
            Type::getAtomicStringFromLiteral($concrete),
        ]);
    }

    /**
     * Resolves `app($classString)` / `resolve($classString)` / `make($classString)` where
     * `$classString` is typed as a `class-string<Foo>` atomic rather than a literal.
     *
     * This covers both `static::class` (Psalm encodes it as `TClassString($fq_class_name,
     * new TNamedObject($fq_class_name, is_static: true))`, see ClassConstAnalyzer) and
     * variables typed as `class-string<Foo>`.
     *
     * @psalm-pure
     */
    private static function resolveFromClassString(TClassString $atomic): ?Union
    {
        $asType = $atomic->as_type;
        if (!$asType instanceof \Psalm\Type\Atomic\TNamedObject) {
            // Bare `class-string` (no constraint). We cannot narrow further.
            return null;
        }

        if ($atomic instanceof TTemplateParamClass) {
            // `class-string<T>` template parameter. Resolving to the upper bound would
            // mask template tracking at the call site (a correctly-T-returning statement
            // would become InvalidReturnStatement), so falling back to mixed is
            // conservative until the plugin can project T into the return type.
            return null;
        }

        // For `static::class`, $asType already carries `is_static: true` and renders as
        // `Foo&static`, preserving late static binding through callers.
        return new Union([$asType]);
    }
}
