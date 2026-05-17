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
use Tests\Psalm\LaravelPlugin\Type\Fixtures\MacroFixtureBag;

MacroFixtureBag::macro('shoutTest', static fn(): string => 'OK');
MacroFixtureBag::macro('countCharsTest', static fn(string $needle): int => 0);

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
