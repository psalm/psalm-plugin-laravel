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
        <findOctaneIncompatibleBinding value="true" />
        <experimental value="true" />
        <failOnInternalError value="true" />
        <configDirectory name="app/Config" />
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

Column names are validated against the model's `@property` annotations. Columns with no matching declaration are not claimed by the plugin: relation chains fall through to `mixed`, and direct Model calls fall through to `UndefinedMagicMethod`. Custom Eloquent builder methods that happen to start with `where` (e.g. `whereByMake(string)`) are also left to Psalm's normal resolution so their declared return types win. Partial `@property` annotation is therefore safe.

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

When enabled, the plugin checks that `view()` and `Factory::make()` calls reference Blade templates that exist on disk.
Only string literal view names are validated — dynamic names and namespaced views (e.g., `mail::html.header`) are skipped.

See [MissingView](issues/MissingView.md) for details.

### Example

```xml
<findMissingViews value="true" />
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

## Cache directory

**default**: `<psalm-cache-dir>/plugin-laravel` (inside Psalm's project-specific cache directory)

The plugin stores generated files (alias stubs) and cached migration schemas in this directory. By default, it uses a subdirectory inside Psalm's own cache, so `--clear-cache` removes plugin caches along with Psalm's.

### Migration schema cache

When `columnFallback="migrations"` is active, the plugin caches the parsed migration schema to disk so subsequent Psalm runs skip re-parsing unchanged migrations.

The cache key is a fingerprint of sorted migration and SQL dump file paths, their modification times, and the plugin version. Any file change or plugin upgrade automatically invalidates the cache.

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

The plugin registers its handlers and type inference normally in every mode. This option only changes the default reporting level for experimental plugin issues:

- `UnknownModelAttribute`
- `UndefinedModelRelation`

With the default `false`, these are advisory `info` findings. Setting `value="true"` promotes their default level to `error`. Explicit Psalm [`issueHandlers`](https://psalm.dev/docs/running_psalm/dealing_with_code_issues/) always take precedence, including `error`, `info`, `suppress`, and scoped filters.

Experimental issue behaviour may change before graduation. Model serialization array-shape inference (`ModelToArrayShapeHandler`) is a stable v4.15 enhancement and is always active; it is not controlled by this setting. `UnresolvableAppendedModelAttribute` is also stable and remains an error by default in both modes.

## `failOnInternalError`

**default**: `false`

When the plugin encounters an internal error (e.g. failing to boot the Laravel app or generate stubs), it prints a warning and disables itself for that run.
Set this to `true` to throw the exception instead.

This also covers partial boots. When the app's `bootstrap()` throws partway (for example, a `config/*.php` file that calls `parse_url(env('UNSET'))`), the plugin normally keeps running in a degraded mode (service providers never booted, so model, facade and container inference is reduced) and prints a warning about it. With `failOnInternalError` enabled, that swallowed bootstrap failure fails the run instead of degrading silently.

**Recommended for CI.** Without this, a misconfigured environment causes the plugin to silently disable itself — your pipeline passes but without any plugin analysis.
With `failOnInternalError`, the Psalm run fails immediately, so you know the plugin isn't working.

### Example

```xml
<failOnInternalError value="true" />
```
