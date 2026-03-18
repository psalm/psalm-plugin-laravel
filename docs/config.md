---
title: Configuration
nav_order: 2
---

# Configuration

Default plugin config is simple:

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
        <failOnInternalError value="true" />
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

- `migrations` — Parses migration files to infer column names and types (e.g. `$user->email` resolves to `string`).
- `none` — Disables migration-based column inference. Use this if you declare column types via `@property` annotations, or if your migrations can't be statically parsed (SQL migrations, dynamic schema changes).

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

## env `PSALM_LARAVEL_PLUGIN_CACHE_PATH`

**default**: `sys_get_temp_dir()/psalm-laravel-<hash>` (project-specific subdirectory)

Environment variable to override the cache location for generated stub files (aliases, etc.).

### Example

```bash
PSALM_LARAVEL_PLUGIN_CACHE_PATH=/path/to/cache ./vendor/bin/psalm
```
