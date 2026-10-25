---
title: Configuration
nav_order: 2
---

# Configuration

The default plugin config is simple:

```xml
<plugins>
    <pluginClass class="Psalm\LaravelPlugin\Plugin" />
</plugins>
```

All custom config parameters are listed below. They are specified as XML elements inside the `<pluginClass>` tag in your `psalm.xml`.

Full config example:

```xml
<plugins>
    <pluginClass class="Psalm\LaravelPlugin\Plugin">
        <modelProperties columnFallback="none" />
        <resolveDynamicWhereClauses value="false" />
        <resolveConfigReturnTypes value="false" />
        <reportImplicitQueryBuilderCalls value="true" />
        <findMissingTranslations value="true" />
        <findMissingViews value="true" />
        <findUnregisteredRouteNames value="true" />
        <findOctaneIncompatibleBinding value="true" />
        <findPromptInjection value="true" />
        <experimental value="true" />
        <failOnInternalError value="true" />
        <configDirectory name="app/Config" />
        <blade />
    </pluginClass>
</plugins>
```

## `modelProperties`

**default**: `columnFallback="migrations"`

`@property` annotations on your model always take precedence over inference.
If a property is not declared via PHPDoc, this setting instructs the plugin how to infer property types.

### Example

```xml
<modelProperties columnFallback="migrations" />
```

### `columnFallback` values

- `migrations` — Parses SQL schema dumps (`php artisan schema:dump`) and PHP migration files to infer column names and types (e.g. `$user->email` resolves to `string`).
- `none` — Disables migration-based column inference. Use this if you declare column types via `@property` annotations, or if your migrations can't be statically parsed (dynamic schema changes).

## `resolveDynamicWhereClauses`

**default**: `true`

When enabled, the plugin resolves Laravel's [dynamic where methods](https://laravel.com/docs/queries#dynamic-where-clauses) (e.g. `whereTitle('foo')`, `whereFirstName('John')`) on both Eloquent relation chains and direct Model static / instance calls (`User::whereEmail('a@b')`, `$user->whereEmail('a@b')`), preserving the relation or `Builder<TModel>` generic type instead of returning `mixed` or raising `UndefinedMagicMethod`.

Column names are validated against the model's `@property` annotations and, when [`columnFallback`](#columnfallback-values) is `migrations`, against the columns parsed from your migrations. A segment is matched as `Str::snake($segment)` against the exact column name, as Laravel builds the query, so `whereUserId()` matches `user_id` but `whereLine1()` does not match `line_1`. `@property` wins over the migration schema. The argument is `mixed`, as with `where()`: Laravel forwards it to `where()`, which accepts null, arrays, expressions, enums, dates and subquery closures, so the column's type is not imposed. With `columnFallback="none"`, or when the model's schema could not be resolved, only `@property` annotations count. Columns found in neither source are not claimed by the plugin: relation chains fall through to `mixed`, and direct Model calls fall through to `UndefinedMagicMethod`. Custom Eloquent builder methods that happen to start with `where` (e.g. `whereByMake(string)`) are also left to Psalm's normal resolution so their declared return types win. Partial `@property` annotation is therefore safe.

Disable if dynamic where resolution conflicts with your codebase.

### Example

```xml
<resolveDynamicWhereClauses value="false" />
```

## `resolveConfigReturnTypes`

**default**: `true`

When enabled, the plugin narrows `config('some.key')`, `Config::get('some.key')`, and `Repository::get('some.key')` (both the concrete `\Illuminate\Config\Repository` and the contract) from `mixed` to the runtime type reflected from the booted Laravel app.

Scalar values are intentionally generalised (`config('app.debug')` stays `bool`, not the boot-time literal `false`) so env-driven overrides keep working at the call site without triggering spurious `TypeDoesNotContainType` warnings.
Arrays preserve shape up to depth 5 with a 64-key per-level cap and a 512-property cross-level budget; beyond any cap, the value degrades to `array<array-key, mixed>`.

Three call-site rules mirror `Arr::get` runtime behavior:

- key absent → generalised default
- key present, value not null → reflected value (default ignored)
- key present, value is null → stored null (default ignored, even when supplied)

Closure values stored in config reflect to `\Closure`. Closure defaults resolve to their declared return type (typed closures); untyped closures contribute `mixed` without dropping other union members.

### When to disable

When you always use `Config::integer()`, `Config::string()` and other typed calls consistently.

### Example

```xml
<resolveConfigReturnTypes value="false" />
```

## `reportImplicitQueryBuilderCalls`

**default**: `false`

When enabled, the plugin flags query builder and local scope methods called directly on an Eloquent model (forwarded by Laravel through `__callStatic` / `__call`) and asks for the explicit `Model::query()->...` form instead. It reports query builder methods (`where`, `find`, `orderBy`, ...), custom builder methods, and local scopes (legacy `scopeXxx()` and modern `#[Scope]`). Real model methods (including a method whose name collides with a builder method) and genuinely undefined methods are left alone.

See [ImplicitQueryBuilderCall](issues/ImplicitQueryBuilderCall.md) for details.

### Example

```xml
<reportImplicitQueryBuilderCalls value="true" />
```

## `configDirectory`

**default**: the booted Laravel app's `config_path()`

Controls which directories are treated as config directories by [`NoEnvOutsideConfig`](issues/NoEnvOutsideConfig.md). `env()` calls inside any of these directories are exempt from the check.

Each entry can be an absolute path or a relative path resolved by PHP's `glob()` against the current working directory. Psalm sets the working directory to the directory containing `psalm.xml` by default (controlled by Psalm's `resolveFromConfigFile` option), so relative entries normally resolve from the project root. Absolute paths are recommended when running Psalm from a subdirectory or when several config files are in play. Glob patterns are supported and expanded once at plugin boot.

**Defining any `<configDirectory>` replaces the default**, so include `config` (or whatever your project's standard config dir is) explicitly if you still want it covered. Test files (paths containing `/tests/`) are always exempt regardless of this setting.

If no entry resolves to an existing directory at boot, the plugin emits a warning so the typo case (`<configDirectory name="cofnig" />`) is surfaced rather than silently flagging every `env()` call.

### Examples

A non-standard layout (e.g. BookStack's `app/Config/`):

```xml
<configDirectory name="app/Config" />
```

Standard `config/` plus monorepo package configs:

```xml
<configDirectory name="config" />
<configDirectory name="packages/*/config" />
```

## `findMissingTranslations`

**default**: `false`

When enabled, the plugin checks that `__()` and `trans()` calls reference translation keys that exist in the application's language files.
Uses Laravel's `Translator::has()` from the booted app, which handles PHP array files, JSON files, and fallback locales automatically.

Only string literal keys are checked -- dynamic or concatenated keys are skipped.
Namespaced package keys (e.g., `vendor::file.key`) are also skipped.

See [MissingTranslation](issues/MissingTranslation.md) for details.

### Example

```xml
<findMissingTranslations value="true" />
```

## `findMissingViews`

**default**: `false`

When enabled, the plugin checks that a view name passed to `view()`, `Factory::make()`/`first()`/`renderWhen()`/`renderUnless()`/`renderEach()` (and the `View` facade), `ResponseFactory::view()`/`response()->view()` (and the `Response` facade), `Router::view()` (and the `Route` facade), `MailMessage::view()`/`markdown()`/`text()` (including the array form of `view()`), or `TestResponse::assertViewIs()` references a Blade template that exists on disk.
Only string literal view names are validated — dynamic names and namespaced views (e.g., `mail::html.header`) are skipped.

See [MissingView](issues/MissingView.md) for details.

### Example

```xml
<findMissingViews value="true" />
```

## `findUnconfiguredFilesystemDisks`

**default**: `false`, or `true` when [`<experimental value="true" />`](#experimental) is set. An explicit value here always wins; a bare `<findUnconfiguredFilesystemDisks />` with no `value` attribute counts as not set, so it still follows `<experimental>`.

When enabled, the plugin checks that disk names passed to `Storage::disk()` / `Storage::drive()` are present in `filesystems.disks`. A name counts when it is a string literal, a string-backed enum case, or a class constant typed as one string literal (`self::` included). An unconfigured disk is a hard `InvalidArgumentException` at runtime, not a silent fallback to `local`, so the failure mode is availability, not a wrong write target.

Only calls through the `Storage` facade (and its root `\Storage` alias) are checked (an injected `FilesystemManager` may be a userland subclass with its own disk resolution). Dynamic, `null`, falsy (`''`, `'0'`), and dotted (nested-group) names are skipped. The check reads `filesystems.disks` once from the booted application, so it requires the project's own `bootstrap/app.php` to resolve cleanly; it stays off under the Testbench package-mode fallback and after a degraded boot.

See [UnconfiguredFilesystemDisk](issues/UnconfiguredFilesystemDisk.md) for details.

### Example

```xml
<findUnconfiguredFilesystemDisks value="true" />
```

## `findUnregisteredRouteNames`

**default**: `false`, or `true` when [`<experimental value="true" />`](#experimental) is set. An explicit value here always wins; a bare `<findUnregisteredRouteNames />` with no `value` attribute counts as not set, so it still follows `<experimental>`.

When enabled, the plugin flags a route name passed to `route()`, `to_route()`, `URL::route()`/`signedRoute()`/`temporarySignedRoute()`, `Redirect::route()`, `redirect()->route()`, or `url()->route()` that is not registered in the booted application. A name counts when it is a string literal, a string-backed enum case, or a class constant typed as one string literal (`self::` included); dynamic names are skipped, and named arguments are resolved by parameter name.

The check stays off, silently, when the route table cannot be trusted: the Testbench package fallback boot or a swallowed bootstrap error, an application with no named routes, or an application that registers a missing-named-route resolver. Once enabled it reports at Psalm's normal `error` level.

See [UnregisteredRouteName](issues/UnregisteredRouteName.md) for details and known false-positive sources.

### Example

```xml
<findUnregisteredRouteNames value="true" />
```

## `findSerializedQueuedModels`

**default**: `false`, or `true` when [`<experimental value="true" />`](#experimental) is set. An explicit value here always wins; a bare `<findSerializedQueuedModels />` with no `value` attribute counts as not set, so it still follows `<experimental>`.

When enabled, the plugin flags a class implementing `ShouldQueue` that holds an Eloquent model (or an `Eloquent\Collection`) in a non-static property it declares, when the class has no `__serialize()` or `__sleep()` from any source. Without one the whole model is written into the queue payload instead of a `ModelIdentifier`.

The check is on the resulting `__serialize()`, not on the trait name, so the framework bases that already pull the trait in are silent (`Illuminate\Foundation\Queue\Queueable`, what `make:job` scaffolds since Laravel 11, and `Illuminate\Notifications\Notification`), as is a class that hand-writes its own serialization.

See [SerializedQueuedModel](issues/SerializedQueuedModel.md) for details.

### Example

```xml
<findSerializedQueuedModels value="true" />
```

## `findOctaneIncompatibleBinding`

**default**: omit the element. The plugin then auto-detects: the rule registers if the project depends on `laravel/octane`, and stays off otherwise.

The plugin flags `singleton()` and `singletonIf()` binding closures that resolve request-scoped Laravel services (Request, Session, Auth, Cookie, Config, UrlGenerator, Redirector). Under Laravel Octane the application instance is reused across requests, so these captures leak state from the first resolving request into every subsequent one. `scoped()` / `scopedIf()` bindings are not flagged: Octane flushes them between requests.

To override the auto-detect:

- `value="true"`: force the rule on. Useful for projects that don't install `laravel/octane` directly but still want the check (e.g. shared libraries that aim to stay Octane-safe).
- `value="false"`: force the rule off, even when `laravel/octane` is installed.

See [OctaneIncompatibleBinding](issues/OctaneIncompatibleBinding.md) for details.

### Example

```xml
<findOctaneIncompatibleBinding value="true" />
```

## `findPromptInjection`

**default**: `auto`

Controls the reporting level of `TaintedLlmPrompt`, raised when untrusted input reaches a `laravel/ai` prompt sink. See [LLM prompt injection](security.md#llm-integration) for what is covered and for when the integration loads (`laravel/ai >=1.0.0 <2.0.0` only).

- element omitted (`auto`): enforced when the supported integration is installed, at Psalm's normal error level.
- `value="false"`: explicit opt-out. Suppresses only `TaintedLlmPrompt`; model-output taint sources keep their ordinary `Tainted*` findings.
- `value="true"`: enforced, but only inside the same integration gate. It does not enable the rule when `laravel/ai` is absent or unsupported.

An explicit `<TaintedLlmPrompt errorLevel="..." />` in `issueHandlers` always wins over this setting. If your agents already run a prompt guard, annotate the guard (see the link above) instead of opting out globally.

### Example

```xml
<findPromptInjection value="false" />
```

## `blade`

**default**: off

```xml
<blade />
```

Analyze `*.blade.php` templates as PHP files at their own paths. Omit the element, or write `<blade value="false" />`, to turn it off; `psalm-laravel analyze --blade` / `--no-blade` switches it for one run. User guide: [Blade Template Analysis](blade.md).

`value` and `cacheDir` are the only attributes. Any other one aborts the run like an unknown `--plugin-option` key.

### `cacheDir`

**default**: `blade/` inside the [plugin cache directory](#cache-directory)

```xml
<blade cacheDir="build/blade-shadows" />
```

Where compiled templates are cached. Absolute, or relative to the directory Psalm runs in. A directory inside the project must be gitignored and kept out of `<projectFiles>`.

## Per-run overrides

Bool and enum settings on this page can be overridden for one run without touching `psalm.xml`, through `psalm-laravel analyze`:

```bash
vendor/bin/psalm-laravel analyze --experimental --plugin-option=modelProperties.columnFallback=none
```

* Keys mirror the XML names; nested settings use a dot (`modelProperties.columnFallback`). `psalm-laravel analyze --help` lists every key with its values.
* Values: `true` or `false` for every flag, including the tri-state ones (`findOctaneIncompatibleBinding`, `findPromptInjection` and the three `experimental`-derived rules), so an override can force them but cannot restore auto-detection. `modelProperties.columnFallback` takes `migrations` or `none`.
* Path settings (`configDirectory`, `blade cacheDir`) are XML-only.
* `--plugin-option=KEY=VALUE` is repeatable (`=` form only); a repeated key is last-wins. `--blade` / `--no-blade` are shorthands for `blade=true|false` and `--experimental` for `experimental=true`, ordered with `--plugin-option`. Precedence per key: command line > `psalm.xml` > default.
* There is no environment-variable form for a bare `vendor/bin/psalm`; edit `psalm.xml` there.
* `experimental` is applied after the overrides: `experimental=true` turns on the rules it derives (`findUnconfiguredFilesystemDisks`, `findSerializedQueuedModels`, `findUnregisteredRouteNames`) unless an override or the XML sets one of them explicitly.
* An unknown key, a token without `=`, or a value outside the key's values aborts the run with a non-zero exit and a message naming the problem. `analyze` checks them before it starts Psalm. An override never skips the XML checks: a malformed element in `psalm.xml` still fails the run.

### Result cache

Psalm's result cache is keyed on the config file, not on overrides. Every setting except `blade` only changes which findings are reported, so flipping it between runs reuses a warm cache and gives the same result as a cold run.

`blade` is the exception (stale file references can hide dead-code findings such as `UnusedClass`), so `psalm-laravel analyze` adds `--no-reference-cache` whenever a `blade` override is given. Rationale: [decisions](contributing/decisions.md#per-run-overrides).

## Cache directory

**default**: `<psalm-cache-dir>/plugin-laravel` (inside Psalm's project-specific cache directory)

The plugin stores generated files (alias stubs) and cached migration schemas in this directory. By default, it uses a subdirectory inside Psalm's own cache, so `--clear-cache` removes plugin caches along with Psalm's.

### Migration schema cache

When `columnFallback="migrations"` is active, the plugin caches the parsed migration schema to disk so subsequent Psalm runs skip re-parsing unchanged migrations.

The cache key is a fingerprint of sorted migration and SQL dump file paths, hashes of their contents, and the installed plugin version and commit reference. Any file change or plugin upgrade (including a new commit of a dev branch such as `4.x-dev`) automatically invalidates the cache. Content hashes (rather than modification times) keep the cache usable on CI, where a fresh checkout stamps every file with the checkout time.

**Cache invalidation**: run `--clear-cache` to remove all plugin caches (including migration schema). The plugin also cleans up stale cache files automatically on each cache miss.

**Diagnostics**: if the plugin detects a corrupt or unreadable cache file, it logs a warning and falls back to a full parse. Run with `--debug` to see cache hit/miss messages.

### env `PSALM_LARAVEL_PLUGIN_CACHE_PATH` (deprecated)

> **Deprecated** in v4.3 and will be removed in v5. The plugin now uses Psalm's cache directory automatically.

Environment variable to override the cache location.

```bash
PSALM_LARAVEL_PLUGIN_CACHE_PATH=/path/to/cache ./vendor/bin/psalm
```

## `experimental`

**default**: `false`

```xml
<experimental value="true" />
```

Early access to plugin features that are still on their way to becoming the default in a later minor or major release. Enabling it pulls in two directions at once, tightening some checks while turning others on:

- Any experimental plugin issue with no explicit [`issueHandlers`](https://psalm.dev/docs/running_psalm/dealing_with_code_issues/) entry is enforced as `error` instead of its default `info`.
- [`findUnregisteredRouteNames`](#findunregisteredroutenames), [`findUnconfiguredFilesystemDisks`](#findunconfiguredfilesystemdisks), and [`findSerializedQueuedModels`](#findserializedqueuedmodels), off by default, turn on unless the project sets them explicitly.

For one run without editing `psalm.xml`: `psalm-laravel analyze --experimental` (see [Per-run overrides](#per-run-overrides)).

An explicit `<PluginIssue>` entry takes complete ownership of that issue (base level and scoped filters), regardless of `<experimental>`. When using scoped filters, state the desired base level explicitly:

```xml
<PluginIssue name="UndefinedModelRelation" errorLevel="info">
    <errorLevel type="suppress">
        <directory name="legacy" />
    </errorLevel>
</PluginIssue>
```

Without the outer `errorLevel="info"`, Psalm uses its normal implicit fallback of `error` outside the scoped filter.

## `failOnInternalError`

**default**: `false`

When the plugin encounters an internal error (e.g. failing to boot the Laravel app or generate stubs), it prints a warning and disables itself for that run.
Set this to `true` to throw the exception instead.

This also covers partial boots. When the app's `bootstrap()` throws partway (for example, a `config/*.php` file that calls `parse_url(env('UNSET'))`), the plugin normally keeps running in a degraded mode (service providers never booted, so model, facade and container inference is reduced) and prints a warning about it. With `failOnInternalError` enabled, that swallowed bootstrap failure fails the run instead of degrading silently.

The same holds for [Blade analysis](blade.md): when it is enabled but turns itself off for the run (for example, the booted app never bound `blade.compiler`), the run fails instead of reporting zero template findings. A view tree with no templates is not a failure.

**Recommended for CI.** Without this, a misconfigured environment causes the plugin to silently disable itself — your pipeline passes but without any plugin analysis.
With `failOnInternalError`, the Psalm run fails immediately, so you know the plugin isn't working.

### Example

```xml
<failOnInternalError value="true" />
```
