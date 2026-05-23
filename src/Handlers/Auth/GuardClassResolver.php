<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;

/**
 * Resolves a guard name to the Psalm `Union` for its concrete driver class.
 *
 * Single source of truth shared between the auth-domain handlers:
 *
 *  - {@see AuthMethodHandler::resolveGuardReturnType()} narrows the return type of
 *    `Auth::guard($name)` / `AuthManager::guard($name)` / `Factory::guard($name)`.
 *  - {@see AuthFunctionHandler::getFunctionReturnType()} narrows the return type
 *    of the global `auth($name)` helper.
 *
 * Both surfaces apply the same rule (guard name + `auth.guards.<name>.driver` →
 * concrete `SessionGuard` / `TokenGuard`), so the rule lives here and the callers
 * stay focused on dispatch.
 *
 * Returns `null` for unknown guards or custom (non-standard) drivers; callers fall
 * back to the stub-declared union type in that case.
 */
final class GuardClassResolver
{
    /**
     * Psalm hooks fire once per call site. Typical Laravel apps use 1–3 distinct
     * guard classes total, so caching by FQCN turns N `auth('web')` / `Auth::guard('web')`
     * call sites into one allocation. `Type\Union` is `ImmutableNonCloneableTrait`,
     * so sharing the same instance across call sites is safe.
     *
     * @var array<class-string, Type\Union>
     */
    private static array $union_cache = [];

    public static function resolve(string $guardName): ?Type\Union
    {
        $fqcn = AuthConfigAnalyzer::instance()->getGuardFQCN($guardName);
        if ($fqcn === null) {
            return null;
        }

        return self::$union_cache[$fqcn] ??= new Type\Union([new TNamedObject($fqcn)]);
    }
}
