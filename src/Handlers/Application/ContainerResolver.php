<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Application;

use PhpParser\Node\Arg;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\NodeTypeProvider;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParamClass;
use Psalm\Type\Union;

final class ContainerResolver
{
    /**
     * Abstract => [concrete class fqn or string, whether the container resolved an object].
     * The flag keeps an object's class from being read back as a literal string.
     *
     * @psalm-var array<string, array{string, bool}>
     */
    private static array $cache = [];

    public static function reset(): void
    {
        self::$cache = [];
    }

    /**
     * Instantiates every container binding once and queues each resolved class for scanning, so Psalm
     * has storage for every class `app()` can return (a class nothing else references crashed analysis
     * with "Could not get class storage", vimeo/psalm#3196). Runs once at plugin init, outside stub
     * registration. The previous scan hook on the Application/Container interfaces re-ran every make() on
     * each visit (2-5 times per run) and queued classes while stubs were registering, so Psalm scanned
     * them as stubs.
     *
     * A binding closure can register further bindings while it runs, so the keys are re-read until a
     * round visits nothing new; each abstract is still resolved once within the pass.
     *
     * The results are discarded afterwards: a later binding's closure can rebind an abstract the pass
     * already resolved, so analysis resolves from the live container, as it did before this pass existed.
     *
     * `store_failure: false`: a class Psalm cannot locate is not recorded as missing.
     */
    public static function queueBoundClassesForScanning(Codebase $codebase): void
    {
        $visited = [];

        try {
            do {
                $foundNew = false;

                foreach (\array_keys(ApplicationProvider::getApp()->getBindings()) as $abstract) {
                    $abstract = (string) $abstract;

                    if (isset($visited[$abstract])) {
                        continue;
                    }

                    $visited[$abstract] = true;
                    $foundNew = true;
                    $resolved = self::resolveFromApplicationContainer($abstract);

                    if ($resolved === null || !$resolved[1] || \str_contains($resolved[0], '@anonymous')) {
                        continue;
                    }

                    $codebase->queueClassLikeForScanning($resolved[0], store_failure: false);
                }
            } while ($foundNew);
        } finally {
            self::reset();
        }
    }

    /**
     * @return array{string, bool}|null [class fqn or string, resolved to an object]
     */
    private static function resolveFromApplicationContainer(string $abstract): ?array
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
            $resolved = [$concrete, false];
        } elseif (\is_object($concrete)) {
            // normally we have an object resolved
            $resolved = [$concrete::class, true];
        } else {
            // Some Laravel bindings (e.g. Authenticatable on a fresh Testbench app
            // with no authenticated user) resolve to null. The previous assert-based
            // check was a no-op in production, letting `$concrete::class` crash on
            // null. Return null so the caller falls back to mixed inference.
            return null;
        }

        self::$cache[$abstract] = $resolved;

        return $resolved;
    }

    /**
     * @param list<Arg> $call_args
     */
    public static function resolvePsalmTypeFromApplicationContainerViaArgs(
        NodeTypeProvider $nodeTypeProvider,
        array $call_args,
        Codebase $codebase,
    ): ?Union {
        if ($call_args === []) {
            return null;
        }

        $firstArgType = $nodeTypeProvider->getType($call_args[0]->value);
        if (!$firstArgType instanceof \Psalm\Type\Union) {
            return null;
        }

        // Every atomic is a string literal (a lone literal is the one-element case). One
        // unresolvable element declines the whole call: a partial union would be unsound.
        $literals = $firstArgType->getLiteralStrings();
        if ($literals !== [] && \count($literals) === \count($firstArgType->getAtomicTypes())) {
            $resolved = [];
            foreach ($literals as $literal) {
                $resolvedLiteral = self::resolveFromLiteralString($codebase, $literal->value);
                if (!$resolvedLiteral instanceof Union) {
                    return null;
                }

                $resolved[] = $resolvedLiteral;
            }

            return Type::combineUnionTypeArray($resolved, $codebase);
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

    private static function resolveFromLiteralString(Codebase $codebase, string $abstract): ?Union
    {
        $resolved = self::resolveFromApplicationContainer($abstract);

        // An object's class is named only when Psalm has its storage: the container instantiated it,
        // but nothing guarantees Psalm scanned its file. Otherwise treat the resolution as failed.
        $concreteClass = $resolved === null ? null : self::knownClassName($codebase, $resolved[0]);

        if ($resolved !== null && $resolved[1] && $concreteClass === null) {
            $resolved = null;
        }

        if ($resolved === null) {
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
            // knownClassName() never autoloads (see there). Interfaces and traits stay mixed — we
            // never claim an unresolvable contract resolves to itself. We only ever return the
            // abstract itself, a supertype of whatever the runtime would build, so this cannot
            // introduce a false-positive on a member that genuinely exists.
            $abstractClass = self::knownClassName($codebase, $abstract);

            return $abstractClass === null ? null : new Union([new TNamedObject($abstractClass)]);
        }

        // A binding can resolve to a class-name string (`fn () => Foo::class`) as well as to a path.
        if ($concreteClass !== null) {
            return new Union([
                new TNamedObject($concreteClass),
            ]);
        }

        $concrete = $resolved[0];

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
     * The canonical name of a class (not an interface or trait) Psalm has storage for, else null. Storage only, not `class_exists()`: a
     * class loaded at runtime (a `class_alias()` of an anonymous class, an unscannable named class) can
     * lack storage, and naming it reports UndefinedClass. Never autoloads: a class whose load raises a
     * deprecation would crash the run under Psalm's error handler, here where nothing catches it (#1652).
     *
     * Psalm's storage lookup is case-insensitive but container keys are not: an un-namespaced `hash` or
     * `schema` is a service key that must not match the global `Hash` / `Schema` facade alias class, so it
     * has to be spelled exactly as the storage name. A namespaced name keeps the lenient match. A
     * global-namespace `class_alias()` whose name differs from its target therefore stays unknown.
     *
     * @psalm-mutation-free
     */
    private static function knownClassName(Codebase $codebase, string $class): ?string
    {
        $storage = ClassLineage::storage($codebase, $class);

        if (!$storage instanceof ClassLikeStorage || $storage->is_interface || $storage->is_trait) {
            return null;
        }

        $class = \ltrim($class, '\\');

        return \str_contains($class, '\\') || $storage->name === $class ? $storage->name : null;
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
