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

    // No ERROR_LEVEL override: a request-scoped capture in a singleton closure is a
    // real correctness bug under Octane (state leaks across requests), not a strictness
    // preference. Inherit CodeIssue::ERROR_LEVEL = -1 so the issue is reported as
    // ERROR at every project level, not downgraded to INFO when level > 1.
}
