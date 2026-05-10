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
