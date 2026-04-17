<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when a singleton() / singletonIf() binding closure resolves a
 * request-scoped Laravel service (Request, Session, Auth, etc.). Under Laravel
 * Octane, the application instance is reused across requests, so such captures
 * leak state from the first resolving request into every subsequent request.
 *
 * scoped() / scopedIf() bindings are NOT reported: Octane flushes them between
 * requests via Container::forgetScopedInstances().
 */
final class OctaneIncompatibleBinding extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/OctaneIncompatibleBinding/';

    public const ERROR_LEVEL = 1;
}
