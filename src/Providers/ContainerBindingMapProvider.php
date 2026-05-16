<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

/**
 * Static map of container accessor strings to their concrete service classes,
 * harvested at scan time from `ServiceProvider::register()` bodies.
 *
 * Solves two related issues both rooted in the Testbench app not reliably
 * running vendor/user service providers' bindings:
 *
 * - {@see \Psalm\LaravelPlugin\Handlers\Facades\AppFacadeRegistrationHandler::tryGetFacadeRootClass()}
 *   reads it before the `Facade::getFacadeRoot()` runtime probe — when the
 *   facade's accessor is a string alias (e.g. `'subscription'`) bound by a
 *   package provider, the runtime probe throws `BindingResolutionException`
 *   and emits a warning on every Psalm run. A map hit short-circuits the
 *   probe, silences the warning, and supplies the underlying service class
 *   so {@see \Psalm\LaravelPlugin\Handlers\Facades\FacadeMethodHandler}
 *   forwards real method signatures. See issue #942.
 *
 * - {@see \Psalm\LaravelPlugin\Util\ContainerResolver::resolveFromApplicationContainer()}
 *   reads it before its Testbench `make()` call — `app('datatables.request')`
 *   would otherwise return `mixed` and cascade into `MixedAssignment` /
 *   `MixedMethodCall` at every call site. See issue #766.
 *
 * Population is driven by {@see BootTimeProviderHarvester}, which runs in
 * `Plugin::__invoke` (main process, before any Psalm fork) and enumerates every
 * `ServiceProvider` reachable through composer auto-discovery,
 * `bootstrap/providers.php`, or `config/app.php`. Each provider's class body
 * is parsed and handed to {@see \Psalm\LaravelPlugin\Util\ProviderBindingHarvester}
 * for binding-shape extraction.
 *
 * Mutation is gated behind `@internal record()` to keep the surface tiny while
 * still allowing the scanner handler to populate from scan-time events. Mirrors
 * the simple `SchemaStateProvider` pattern rather than the ModelMetadataRegistry
 * builder split — there's only one mutation site and one piece of state.
 *
 * @internal
 * @psalm-external-mutation-free
 */
final class ContainerBindingMapProvider
{
    /** @var array<string, class-string> accessor string => concrete service class FQCN */
    private static array $map = [];

    /**
     * Record an accessor → service class mapping discovered by the scanner.
     *
     * First write wins. Subsequent attempts to overwrite the same accessor are
     * ignored — a single accessor can be re-bound by multiple providers (e.g.
     * package provider binds `'cache'`, user provider overrides with a wrapper),
     * but the scanner has no ordering guarantee, so deterministic behaviour is
     * better served by stable first-write semantics than by last-write race.
     *
     * @param class-string $serviceClass
     * @psalm-external-mutation-free
     */
    public static function record(string $accessor, string $serviceClass): void
    {
        if ($accessor === '' || isset(self::$map[$accessor])) {
            return;
        }

        self::$map[$accessor] = $serviceClass;
    }

    /**
     * @return ?class-string concrete service class for the accessor, or null when unknown.
     * @psalm-external-mutation-free
     */
    public static function lookup(string $accessor): ?string
    {
        return self::$map[$accessor] ?? null;
    }

    /**
     * Test helper. Production code never resets the map mid-run — scanner
     * populates it during scan phase and consumers read it during analysis.
     *
     * @internal
     * @psalm-api Tests only.
     * @psalm-external-mutation-free
     */
    public static function reset(): void
    {
        self::$map = [];
    }
}
