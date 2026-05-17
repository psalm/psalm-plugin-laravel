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

// Seed the container binding map (issue #942) with the type-test fixture
// provider. In production, vendor `ServiceProvider::register()` methods run
// during composer auto-discovery (via Illuminate's PackageManifest, retargeted
// at the project root by ApplicationProvider). The plugin's own fixtures are
// not a composer package, so register the provider directly on the booted app
// and re-init the snapshot to pick up the new 'subscription' binding.
\Psalm\LaravelPlugin\Providers\ApplicationProvider::getApp()->register(
    \App\Providers\SubscriptionServiceProvider::class,
);
\Psalm\LaravelPlugin\Providers\ContainerBindingMapProvider::init();
