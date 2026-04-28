<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Routing;

use Illuminate\Routing\Router;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\Progress\Progress;

/**
 * Boots {@see RouteParameterRegistry} once at plugin start-up.
 *
 * Resolves the booted Laravel router (RouteServiceProvider runs during
 * {@see ApplicationProvider::doGetApp()}'s console-kernel bootstrap, so
 * `routes/web.php`, `routes/api.php`, etc. have already executed by the
 * time this fires) and hands it to {@see RouteScanner}.
 *
 * Failures are absorbed: routing introspection is best-effort, and an
 * empty registry is a valid degraded mode (the stub fallback still
 * returns Model|null and the existing taint source remains in place).
 *
 * @internal
 */
final class RouteParameterRegistryBuilder
{
    public static function boot(Progress $output): void
    {
        try {
            $app = ApplicationProvider::getApp();
        } catch (\Throwable $bootFailure) {
            // ApplicationProvider warns once on the FIRST failed bootApp(),
            // but `getApp()` does not cache that null result (`self::$app`
            // stays unset on failure), so a second call here re-runs
            // `doGetApp()` and can re-throw. We must surface that — silently
            // dropping it leaves the registry empty without explanation,
            // which the user reads as missing taint suppression / type
            // narrowing they configured for. Promoted from debug to warning
            // accordingly.
            $output->warning(
                "Laravel plugin: route binding scan skipped — application not bootable: "
                . $bootFailure->getMessage(),
            );

            return;
        }

        if (!$app->bound('router')) {
            // Testbench fallback path or a user app without the router service.
            // Either way there is nothing to scan; leave the registry empty.
            return;
        }

        try {
            /** @var Router $router */
            $router = $app->make('router');
        } catch (\Throwable $resolutionFailure) {
            $output->warning(
                "Laravel plugin: could not resolve router service for route binding analysis: "
                . $resolutionFailure->getMessage(),
            );

            return;
        }

        try {
            $registry = (new RouteScanner())->scan($router);
        } catch (\Throwable $scanFailure) {
            // RouteScanner is defensive but reflection on a non-standard
            // Router subclass could still surprise us — keep the warning
            // visible so plugin maintainers can fix the regression.
            $output->warning(
                "Laravel plugin: route binding scan failed, falling back to empty registry: "
                . $scanFailure->getMessage(),
            );

            return;
        }

        RouteParameterRegistry::setInstance($registry);
    }
}
