<?php

declare(strict_types=1);

/**
 * Loaded via the `autoloader` attribute in psalm.xml. Registers macros on the
 * test fixture class at runtime so they appear in `Macroable::$macros` by the
 * time the plugin's `AfterCodebasePopulated` handler reads it.
 *
 * The fixture class itself lives in `macro-fixtures-class.phpstub` (loaded as a
 * stub) so Psalm's normal type analysis sees it without parsing the runtime
 * macro registration calls below.
 *
 * Stands in for what a real Laravel app would do via
 * `App\Providers\AppServiceProvider::boot()`.
 *
 * Macros are registered on a dedicated fixture class rather than on framework
 * classes like `Stringable` because the `autoloader` attribute force-loads any
 * referenced class ahead of Psalm's normal scan order, which alters Psalm's
 * argument-count diagnostics for unrelated framework methods (observed
 * regression against `Stringable::trim`/`ltrim`/`rtrim`).
 */

require_once __DIR__ . '/macro-fixtures-class.phpstub';

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Tests\Psalm\LaravelPlugin\Type\Fixtures\MacroFixtureBag;

MacroFixtureBag::macro('shoutTest', static fn(): string => 'OK');
MacroFixtureBag::macro('countCharsTest', static fn(string $needle): int => 0);

// Locks in coverage for issue #899 idea #1: docblock-aware closure type extraction.
// The closure has NO native return type but a `@return non-empty-string` docblock,
// and `@param positive-int $count` narrows what reflection's plain `int` would surface.
// Reflection cannot see the docblock at all — only Psalm's pre-scanned
// {@see \Psalm\Storage\FunctionLikeStorage} carries the parsed `@param` / `@return`
// Union types. `MacroRegistry::recoverClosureStorage()` looks them up by file + line
// and `MacroRegistry::buildDefinitionFromStorage()` copies the docblock Unions into
// the pseudo-method's params and return type.
//
// Without docblock recovery, the registered macro would surface as `function (int): mixed`
// because the closure declares no native return type. With recovery, it surfaces as
// `function (positive-int): non-empty-string`.
MacroFixtureBag::macro(
    'docblockReturnTest',
    /**
     * @param positive-int $count
     * @return non-empty-string
     */
    static function (int $count) {
        return \str_repeat('x', $count);
    },
);

// Generic return-type recovery — the closure's `@return Collection<int, string>`
// docblock cannot be expressed natively (PHP has no generics), so reflection sees
// `Collection` at best and `mixed` at worst. The plugin lifts the full generic
// shape from Psalm's storage so chained calls retain `Collection<int, string>`
// rather than degrading to `mixed` or the raw class.
MacroFixtureBag::macro(
    'docblockGenericTest',
    /**
     * @return Collection<int, string>
     */
    static function () {
        return new Collection(['a', 'b']);
    },
);

// Fluent macro returning `static` — locks in issue #899 §C signal 1
// (fluent return narrowing). Macroable rebinds the closure to the calling
// instance via `bindTo($this, static::class)`, so `static` resolves to the
// caller's runtime class. Psalm's pseudo-method dispatch expands a
// `TNamedObject('static')` in the return type against the lhs caller, so the
// registry must preserve the literal `static` token rather than flattening
// it to the declaring-class FQCN.
//
// Use a non-`static` closure so `Macroable::__call` can `bindTo($this, ...)`
// successfully — a `static fn(): static => $this` closure can't rebind to a
// non-null `$this` (PHP raises a warning, `bindTo` returns null), forcing the
// `bindTo(null, static::class)` fallback. The current shape avoids that
// detour while still exercising the `: static` return-type expansion.
MacroFixtureBag::macro(
    'fluentTest',
    function (): static {
        /** @var static $this */
        return $this;
    },
);

// Locks in coverage for the issue #648 motivating case: a Builder macro
// registered at runtime should resolve when reached through the typed
// `Customer::query()->testBuilderMacro()` path. Builder is a Macroable owner,
// so its pseudo-methods land on Builder itself and any Builder<TModel> call
// site dispatches to them normally.
Builder::macro('testBuilderMacro', static fn(): string => 'builder macro OK');

// Locks in coverage for issue #899 idea #5 (facade-class macro propagation).
// Each registration targets a Macroable backing class of a Laravel facade —
// the call-site shape Laravel docs encourage:
//
//   Route::macro('foo', fn() => ...) ->    Router            (spatie-dashboard)
//   Http::macro('foo', fn() => ...)  ->    Http\Client\Factory (monica)
//   Response::macro('foo', fn() => ...) -> Routing\ResponseFactory (docs example)
//   Vite::macro('foo', fn() => ...)  ->    Foundation\Vite   (docs example)
//
// The macros land on the Macroable owner's `$macros` storage at registration
// time; the plugin then propagates them onto every facade class that resolves
// to the owner via `FacadeMapProvider`. The closures are never executed at
// type-check time, so the bodies are degenerate (only the param and return
// types matter for analysis).
\Illuminate\Routing\Router::macro(
    'testRouterFacadeMacro',
    static fn(string $name): string => $name,
);
\Illuminate\Http\Client\Factory::macro(
    'testHttpFacadeMacro',
    static fn(string $url): int => 200,
);
\Illuminate\Routing\ResponseFactory::macro(
    'testResponseFacadeMacro',
    static fn(string $value): string => $value,
);
\Illuminate\Foundation\Vite::macro(
    'testViteFacadeMacro',
    static fn(string $asset): string => "resources/images/{$asset}",
);

// Locks in coverage for issue #899 idea #4 (multi-target facade dispatch). Each
// registration targets a Macroable per-store concrete reached only through a
// non-Macroable manager's `__call` forwarding:
//
//   Auth::macro(...)    -> AuthManager::__call -> SessionGuard / RequestGuard / TokenGuard
//   Cache::macro(...)   -> CacheManager::__call -> Repository
//   Session::macro(...) -> SessionManager extends Manager::__call -> Store
//   Storage::macro(...) -> FilesystemManager::__call -> FilesystemAdapter
//   Mail::macro(...)    -> MailManager::__call -> Mailer
//
// The macros land on the concrete's `$macros` storage at registration time; the
// plugin's `FacadeMapProvider::MULTI_TARGET_FACADES` edge set links each
// concrete back to the facade so the existing propagation pass injects the
// macro pseudo-methods on the facade class itself.
\Illuminate\Auth\SessionGuard::macro(
    'testAuthSessionGuardMacro',
    static fn(string $token): string => $token,
);
\Illuminate\Auth\RequestGuard::macro(
    'testAuthRequestGuardMacro',
    static fn(string $name): int => \strlen($name),
);
\Illuminate\Auth\TokenGuard::macro(
    'testAuthTokenGuardMacro',
    static fn(string $token): bool => $token !== '',
);
\Illuminate\Cache\Repository::macro(
    'testCacheFacadeMacro',
    static fn(string $key): string => "cached:{$key}",
);
\Illuminate\Session\Store::macro(
    'testSessionFacadeMacro',
    static fn(string $key): bool => $key !== '',
);
\Illuminate\Filesystem\FilesystemAdapter::macro(
    'testStorageFacadeMacro',
    static fn(string $path): int => \strlen($path),
);
\Illuminate\Mail\Mailer::macro(
    'testMailFacadeMacro',
    static fn(string $to): string => "queued:{$to}",
);
