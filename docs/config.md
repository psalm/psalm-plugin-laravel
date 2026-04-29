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
        <findMissingTranslations value="true" />
        <findMissingViews value="true" />
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

When enabled, the plugin resolves Laravel's [dynamic where methods](https://laravel.com/docs/queries#dynamic-where-clauses) (e.g. `whereTitle('foo')`, `whereFirstName('John')`) on Eloquent relation chains, preserving the relation's generic type instead of returning `mixed`.

Column names are validated against the model's `@property` annotations. Unmatched columns fall through to `mixed` without an error, so partial annotation is safe.

Disable if dynamic where resolution conflicts with your codebase.

### Example

```xml
<resolveDynamicWhereClauses value="false" />
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

## `configDirectory`

**default**: the booted Laravel app's `config_path()`

Controls which directories are treated as config directories by [`NoEnvOutsideConfig`](issues/NoEnvOutsideConfig.md). `env()` calls inside any of these directories are exempt from the check.

Each entry can be an absolute path or a path relative to where Psalm is invoked (typically the project root; PHP's `glob()` is used for resolution, so `<configDirectory>` is sensitive to your working directory). Glob patterns are supported and expanded once at plugin boot.

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

## `failOnInternalError`

**default**: `false`

When the plugin encounters an internal error (e.g. failing to boot the Laravel app or generate stubs), it prints a warning and disables itself for that run.
Set this to `true` to throw the exception instead.

**Recommended for CI.** Without this, a misconfigured environment causes the plugin to silently disable itself — your pipeline passes but without any plugin analysis.
With `failOnInternalError`, the Psalm run fails immediately, so you know the plugin isn't working.

### Example

```xml
<failOnInternalError value="true" />
```
