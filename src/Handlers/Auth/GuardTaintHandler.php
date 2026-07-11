<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

use Illuminate\Auth\SessionGuard;
use Illuminate\Auth\TokenGuard;
use Psalm\LaravelPlugin\Internal\WarningReporter;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type\TaintKind;

/**
 * Attaches the guards' taint metadata to the *real* Laravel classes, instead of via full-class
 * `.phpstub` files.
 *
 * A stub that redeclares `Illuminate\Auth\SessionGuard` / `TokenGuard` to host a single
 * `@psalm-taint-*` method shadows the whole class: a class declaration in a stub claims the class's
 * file slot, so when user code only reaches the guard through `auth('web')` / `Auth::guard('web')`
 * narrowing — never naming the class — Psalm never scans the real vendor source and strips every
 * other method, breaking `auth('web')->user()` with `UndefinedMethod` (#1113). (It also turns those
 * methods magic if the stub re-adds `Macroable`, which then fatals Psalm via `getMethodParams` on a
 * return-type-provider-only class.)
 *
 * Instead we set the same fields Psalm derives from a `@psalm-taint-source` / `@psalm-taint-escape`
 * docblock — `taint_source_types` / `removed_taints` — directly on the real method storage during
 * the scan phase (exactly what `FunctionLikeDocblockScanner` does for those tags). The instance-call
 * taint path reads them back: `MethodCallReturnTypeFetcher` applies `removed_taints` and delegates
 * `taint_source_types` to `FunctionCallReturnTypeFetcher::taintUsingStorage()`. The real class is
 * left fully intact, so its methods resolve normally.
 *
 * What this reproduces from the deleted stubs:
 *
 *  - {@see TokenGuard::getTokenForRequest()} reads user-controlled request input (query string,
 *    request body, Bearer header, HTTP basic password) → an `input` taint *source* (was
 *    `@psalm-taint-source input`). Load-bearing: the real vendor body isn't taint-analysed, so
 *    without this the return carries no taint.
 *  - {@see SessionGuard::hashPasswordForCookie()} returns an HMAC digest of the password hash, not
 *    the hash itself → `user_secret` taint is *escaped* from the result (was `@psalm-taint-escape
 *    user_secret`). This is defensive: the result already carries no taint to strip (the real
 *    `hash_hmac()` body breaks the flow, and a hex digest cannot carry injection taint), so the
 *    escape is a no-op today. It is kept to make the security contract explicit and to stay correct
 *    if a future Psalm ever propagates taint through the HMAC.
 *
 * The deleted SessionGuard stub additionally carried `@psalm-flow ($passwordHash) -> return`, which
 * re-propagated non-secret taints through the HMAC. That is intentionally dropped: a hex digest is
 * injection-inert, so the propagation was over-conservative (see SafeHtmlHashPasswordForCookie.phpt).
 */
final class GuardTaintHandler implements AfterClassLikeVisitInterface
{
    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $storage = $event->getStorage();

        if (\strcasecmp($storage->name, TokenGuard::class) === 0) {
            $method = self::resolveMethod($event, $storage, 'gettokenforrequest');

            if ($method instanceof \Psalm\Storage\MethodStorage) {
                $method->taint_source_types |= TaintKind::ALL_INPUT;
            }
        } elseif (\strcasecmp($storage->name, SessionGuard::class) === 0) {
            $method = self::resolveMethod($event, $storage, 'hashpasswordforcookie');

            if ($method instanceof \Psalm\Storage\MethodStorage) {
                $method->removed_taints |= TaintKind::USER_SECRET;
            }
        }
    }

    /**
     * Look up a method on the (already-matched) guard class, warning if it is absent.
     *
     * A guard taint method silently vanishing is a security regression — a missing source is an
     * invisible false negative — so we surface it the way {@see \Psalm\LaravelPlugin\Handlers\Facades\AppFacadeRegistrationHandler}
     * surfaces a facade root going missing: a `warning` (debug is a no-op under the default
     * progress). It only fires for the two matched classes, so there is no per-class-visit spam.
     *
     * @param lowercase-string $method
     */
    private static function resolveMethod(AfterClassLikeVisitEvent $event, ClassLikeStorage $storage, string $method): ?MethodStorage
    {
        $method_storage = $storage->methods[$method] ?? null;

        if ($method_storage === null) {
            WarningReporter::emit(
                $event->getCodebase()->progress,
                "Laravel plugin: {$storage->name}::{$method}() not found — guard taint annotation skipped. "
                . 'The Laravel method signature may have changed; please report this against psalm-plugin-laravel.',
            );
        }

        return $method_storage;
    }
}
