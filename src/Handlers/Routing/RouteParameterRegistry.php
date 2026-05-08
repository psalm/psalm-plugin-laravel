<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Routing;

/**
 * Read-only per-route-parameter metadata store.
 *
 * Aggregates two pieces of information for each route parameter name:
 *
 * 1. The bound model class, if a binding is registered globally via
 *    {@see \Illuminate\Routing\Router::bind()} or {@see \Illuminate\Routing\Router::model()}
 *    (the latter being a thin wrapper that also stores the binding in the
 *    {@see \Illuminate\Routing\Router::$binders} map). Used to narrow the
 *    return type of {@see \Illuminate\Http\Request::route()} from string|null
 *    to ModelClass|null. See issues #801 and #803.
 *
 * 2. Whether the parameter is constrained by a regex that demonstrably defeats
 *    every taint sink — i.e. only allows characters from a conservative
 *    whitelist (`\w`, `\d`, `[a-zA-Z0-9_-]`, UUID/ULID shapes). Used by the
 *    taint handler to drop the input source from `Request::route('name')`.
 *    Built by intersecting per-route `wheres` against the global
 *    {@see \Illuminate\Routing\Router::$patterns}; if any route uses the
 *    parameter without a safe constraint, this entry is omitted (conservative).
 *    See issue #849.
 *
 * Populated once during plugin boot by {@see RouteParameterRegistryBuilder}
 * (which calls {@see RouteScanner} against the booted Laravel router).
 * Handlers query `RouteParameterRegistry::instance()` inside event callbacks.
 *
 * The sibling `@internal RouteParameterRegistryBuilder` owns mutation of the
 * static instance; instances themselves are read-only after construction.
 *
 * @psalm-external-mutation-free
 */
final class RouteParameterRegistry
{
    private static ?self $instance = null;

    /**
     * @param array<string, class-string> $bindings parameter name => bound model FQCN
     * @param array<string, true> $safeConstraints parameter name => true when the constraint is safe for every sink
     *
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly array $bindings,
        private readonly array $safeConstraints,
    ) {}

    /** @psalm-external-mutation-free */
    public static function instance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self([], []);
        }

        return self::$instance;
    }

    /**
     * Replace the singleton. Called by {@see RouteParameterRegistryBuilder}
     * at plugin boot, and by unit tests that need a known fixture.
     *
     * @internal
     *
     * @psalm-external-mutation-free
     */
    public static function setInstance(self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * @return class-string|null
     *
     * @psalm-mutation-free
     */
    public function getBoundModel(string $parameterName): ?string
    {
        return $this->bindings[$parameterName] ?? null;
    }

    /** @psalm-mutation-free */
    public function hasSafeConstraint(string $parameterName): bool
    {
        return isset($this->safeConstraints[$parameterName]);
    }

    /**
     * Whether the registry has any opinion at all. Used by the taint handler
     * as an early-out: when no bindings and no safe constraints have been
     * collected, no `Request::route(...)` call can be narrowed, so the
     * handler skips the (relatively expensive) caller-class walk on every
     * subsequent call site.
     *
     * @psalm-mutation-free
     */
    public function isEmpty(): bool
    {
        return $this->bindings === [] && $this->safeConstraints === [];
    }
}
