<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Routing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionFunction;

/**
 * Walks a booted {@see Router} once and produces the data backing
 * {@see RouteParameterRegistry}.
 *
 * Two passes:
 *
 *  1. Bindings — enumerated from {@see Router::$binders} via reflection
 *     (the property is protected and Laravel exposes no public iterator).
 *     For each binder closure we try, in order:
 *       a. Closure return type via {@see ReflectionFunction::getReturnType()}
 *          — covers explicit `Route::bind('name', fn (...): Genre => ...)`.
 *       b. Closure static variables via {@see ReflectionFunction::getStaticVariables()}
 *          looking for a `class` (or `$class`) string that is a Model FQCN —
 *          covers `Route::model('name', Genre::class)`, where Laravel internally
 *          wraps the lookup in a closure that captures `$class`.
 *
 *  2. Constraints — for each parameter name appearing in any registered route,
 *     we collect the regex from the route's `wheres` array, falling back to
 *     {@see Router::getPatterns()} for global patterns. The name is reported
 *     as having a "safe constraint" only when EVERY route using the name has
 *     a constraint AND every collected regex is in
 *     {@see SafeRoutePattern::isSafe()}'s whitelist (issue #849).
 *
 * Construction never throws: any reflection or container error is caught and
 * the scanner falls back to an empty registry. The plugin is best-effort —
 * a runtime quirk in a user's router subclass must not break analysis.
 *
 * @internal
 */
final class RouteScanner
{
    public function scan(Router $router): RouteParameterRegistry
    {
        $bindings = $this->collectBindings($router);
        $safeConstraints = $this->collectSafeConstraints($router);

        return new RouteParameterRegistry($bindings, $safeConstraints);
    }

    /**
     * @return array<string, class-string>
     */
    private function collectBindings(Router $router): array
    {
        $binders = $this->readProtectedBinders($router);

        if ($binders === null) {
            return [];
        }

        $bindings = [];

        foreach ($binders as $name => $binder) {
            if (!\is_string($name) || !$binder instanceof \Closure) {
                continue;
            }

            $modelClass = $this->resolveBinderModelClass($binder);

            if ($modelClass !== null) {
                $bindings[$name] = $modelClass;
            }
        }

        return $bindings;
    }

    /**
     * Read Router::$binders, which is protected.
     *
     * Returns null when reflection fails (subclass hides the property,
     * Laravel internals change shape, ...). Caller treats null as "no
     * bindings discoverable" and proceeds without erroring.
     *
     * @return array<array-key, mixed>|null
     */
    private function readProtectedBinders(Router $router): ?array
    {
        // ReflectionClass::getProperty() already walks up the ancestor chain,
        // so a Router subclass that inherits `binders` works without an
        // explicit climb. We only need to surface the failure if the
        // property is genuinely absent (Laravel internals changed).
        try {
            $property = (new \ReflectionClass($router))->getProperty('binders');
        } catch (\ReflectionException) {
            return null;
        }

        /** @var mixed $value */
        $value = $property->getValue($router);

        return \is_array($value) ? $value : null;
    }

    /**
     * Inspect a binder closure for the bound model class.
     *
     * Two strategies:
     *
     *  - Declared return type: `Route::bind('genre', fn (string $v): ?Genre => ...)`
     *    We unwrap nullable, reject `void`/`mixed`/scalars, and accept a class
     *    name that is a subclass of {@see Model}.
     *
     *  - Static (captured) variables: `Route::model('genre', Genre::class)`
     *    Laravel's `RouteBinding::forModel` returns a closure that captures
     *    `$class` (and `$callback`). We look for a captured string that
     *    names a Model subclass.
     *
     * Returns null when neither strategy yields a Model subclass — the caller
     * skips the entry, leaving the parameter without a registered binding.
     *
     * @return class-string|null
     */
    private function resolveBinderModelClass(\Closure $binder): ?string
    {
        try {
            $reflection = new \ReflectionFunction($binder);
        } catch (\ReflectionException) {
            return null;
        }

        $returnType = $reflection->getReturnType();

        if ($returnType instanceof \ReflectionNamedType && !$returnType->isBuiltin()) {
            $name = $returnType->getName();

            if ($this->isModelClass($name)) {
                return $name;
            }
        }

        /** @var array<string, mixed> $staticVariables */
        $staticVariables = $reflection->getStaticVariables();

        /** @var mixed $value */
        foreach ($staticVariables as $value) {
            if (\is_string($value) && $this->isModelClass($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @psalm-assert-if-true class-string<Model> $candidate
     */
    private function isModelClass(string $candidate): bool
    {
        if ($candidate === '' || $candidate === Model::class) {
            return false;
        }

        if (!\class_exists($candidate)) {
            return false;
        }

        return \is_subclass_of($candidate, Model::class);
    }

    /**
     * @return array<string, true>
     */
    private function collectSafeConstraints(Router $router): array
    {
        /** @var array<string, string> $globalPatterns */
        $globalPatterns = $router->getPatterns();

        // For each parameter name we track every constraint observed across
        // the registered routes. Names with at least one unconstrained route
        // are removed (conservative): the call site can't know which route
        // resolves a given Request, so we only suppress taint when EVERY
        // route binds the name to a known-safe shape.
        /** @var array<string, list<string>> $perName collected regexes per name */
        $perName = [];
        /** @var array<string, true> $unconstrained names that appear without a regex anywhere */
        $unconstrained = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (!$route instanceof Route) {
                continue;
            }

            /** @var list<string> $parameterNames */
            $parameterNames = $route->parameterNames();

            /** @var array<string, string> $wheres */
            $wheres = $route->wheres;

            foreach ($parameterNames as $name) {
                $regex = $wheres[$name] ?? $globalPatterns[$name] ?? null;

                if ($regex === null) {
                    $unconstrained[$name] = true;

                    continue;
                }

                $perName[$name][] = $regex;
            }
        }

        $safe = [];

        foreach ($perName as $name => $regexes) {
            if (isset($unconstrained[$name])) {
                continue;
            }

            $allSafe = true;

            foreach ($regexes as $regex) {
                if (!SafeRoutePattern::isSafe($regex)) {
                    $allSafe = false;

                    break;
                }
            }

            if ($allSafe) {
                $safe[$name] = true;
            }
        }

        return $safe;
    }
}
