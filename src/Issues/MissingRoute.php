<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when route(), to_route(), URL::route()/signedRoute()/temporarySignedRoute(),
 * Redirect::route(), or redirect()->route() references a route name that is not
 * registered anywhere in the booted application.
 */
final class MissingRoute extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/MissingRoute/';

    // No ERROR_LEVEL override: controlled by the plugin setting findMissingRoutes.
    // Also entered in ExperimentalIssuePolicy::ISSUES — defaults to 'info' until
    // graduated, given the false-negative surface documented on the handler
    // (Route::has() guards, conditionally-registered routes, stale route caches).
}
