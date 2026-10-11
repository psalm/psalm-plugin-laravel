<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when route(), to_route(), URL::route()/signedRoute()/temporarySignedRoute(),
 * Redirect::route(), redirect()->route(), or url()->route() references a route name that
 * is not registered anywhere in the booted application.
 */
final class UnregisteredRouteName extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/UnregisteredRouteName/';
}
