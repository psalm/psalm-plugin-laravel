<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

use Illuminate\Auth\SessionGuard;
use Illuminate\Auth\TokenGuard;
use Psalm\LaravelPlugin\Handlers\Validation\ValidationRuleAnalyzer;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Progress\Progress;
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
 *    if a future Psalm ever propagates taint through the HMAC. This method only exists on Laravel 12+
 *    (framework #58107); on the supported Laravel 11 line it is legitimately absent, so the branch
 *    probes by presence and stays silent when missing — warning about a skipped no-op on every
 *    Laravel 11 scan was pure noise (#1143). The load-bearing TokenGuard source above keeps warning.
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
        self::annotateGuardTaints($event->getStorage(), $event->getCodebase()->progress);
    }

    /**
     * Apply the guards' taint metadata to the matched real class storage.
     *
     * Split out from {@see afterClassLikeVisit} so it can be driven with a synthetic
     * {@see ClassLikeStorage} and a capturing {@see Progress} in a unit test, without standing up a
     * whole {@see \Psalm\Codebase}: the SessionGuard absence path (the #1143 regression) never fires
     * against the installed Laravel, where the method exists.
     *
     * @internal
     */
    public static function annotateGuardTaints(ClassLikeStorage $storage, Progress $progress): void
    {
        if (\strcasecmp($storage->name, TokenGuard::class) === 0) {
            // getTokenForRequest() exists on every supported Laravel version and is a load-bearing
            // taint *source*, so a missing method is a real regression (an invisible false negative).
            $method = self::resolveMethod($progress, $storage, 'gettokenforrequest', warnIfMissing: true);

            if ($method instanceof MethodStorage) {
                // Psalm 6 models a taint set as a list<string> of kind names (Psalm 7 uses an int
                // bitmask), and has no TaintKind::ALL_INPUT constant — merge the ALL_INPUT group list.
                $method->taint_source_types = self::mergeTaints($method->taint_source_types, ValidationRuleAnalyzer::allInputTaints());
            }
        } elseif (\strcasecmp($storage->name, SessionGuard::class) === 0) {
            // hashPasswordForCookie() only exists on Laravel 12+ and carries a no-op escape (see class
            // docblock), so its absence on Laravel 11 has zero analysis impact — stay silent.
            $method = self::resolveMethod($progress, $storage, 'hashpasswordforcookie', warnIfMissing: false);

            if ($method instanceof MethodStorage) {
                $method->removed_taints = self::mergeTaints($method->removed_taints, [TaintKind::USER_SECRET]);
            }
        }
    }

    /**
     * Merge two Psalm 6 taint lists (Psalm 7 would do this with a bitwise `|`).
     *
     * @param array<string> $a
     * @param list<string> $b
     * @return list<string>
     *
     * @psalm-pure
     */
    private static function mergeTaints(array $a, array $b): array
    {
        return \array_values(\array_unique(\array_merge($a, $b)));
    }

    /**
     * Look up a method on the (already-matched) guard class.
     *
     * When $warnIfMissing is true, an absent method is surfaced as a `warning` (a no-op under the
     * default progress) the way {@see \Psalm\LaravelPlugin\Handlers\Facades\AppFacadeRegistrationHandler}
     * surfaces a facade root going missing: for a method that must exist on every supported Laravel
     * version, a silent disappearance is a security regression — a missing taint source is an
     * invisible false negative. Pass false for a version-optional method whose absence is expected on
     * a supported version (the SessionGuard branch), to avoid warning about a skipped no-op (#1143).
     *
     * @param lowercase-string $method
     */
    private static function resolveMethod(Progress $progress, ClassLikeStorage $storage, string $method, bool $warnIfMissing): ?MethodStorage
    {
        $method_storage = $storage->methods[$method] ?? null;

        if ($method_storage === null && $warnIfMissing) {
            $progress->warning(
                "Laravel plugin: {$storage->name}::{$method}() not found — guard taint annotation skipped. "
                . 'The Laravel method signature may have changed; please report this against psalm-plugin-laravel.',
            );
        }

        return $method_storage;
    }
}
