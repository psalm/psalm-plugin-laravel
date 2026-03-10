[![Packagist version](https://img.shields.io/packagist/v/psalm/plugin-laravel.svg)](https://packagist.org/packages/psalm/plugin-laravel)
[![Packagist downloads](https://img.shields.io/packagist/dt/psalm/plugin-laravel.svg)](https://packagist.org/packages/psalm/plugin-laravel)
[![Type coverage](https://shepherd.dev/github/psalm/psalm-plugin-laravel/coverage.svg)](https://shepherd.dev/github/psalm/psalm-plugin-laravel)
[![Tests](https://github.com/psalm/psalm-plugin-laravel/actions/workflows/test.yml/badge.svg)](https://github.com/psalm/psalm-plugin-laravel/actions/workflows/test.yml)
[![Tests](https://github.com/psalm/psalm-plugin-laravel/actions/workflows/test-laravel.yml/badge.svg)](https://github.com/psalm/psalm-plugin-laravel/actions/workflows/test-laravel.yml)

# Psalm plugin for Laravel

## Looking for contributors

This package is maintained by [@alies-dev](https://github.com/sponsors/alies-dev) and is open to new contributors. If you're passionate about Laravel internals and static analysis, consider joining the effort.

Areas where help is especially welcome:
 - [ ] Full support for custom Model Query Builders
 - [ ] Option to rely on Model `@property` declarations only
 - [x] ~~Remove `barryvdh/laravel-ide-helper` dependency for more accurate attribute types~~
 - [ ] Support `.sql` migration files for attribute discovery

________


## Overview
A [Psalm](https://github.com/vimeo/psalm) plugin that provides static analysis and type support for Laravel.
Catch type-related bugs early — without writing a single test.
 
![Screenshot](/docs/assets/screenshot.png)


## Versions & Dependencies

Maintained versions:

| Laravel Psalm Plugin | PHP   | Laravel   | Psalm |
|----------------------|-------|-----------|-------|
| 4.x                  | ^8.3  | 12, 13    | 7     |
| 3.x                  | ^8.2  | 11, 12    | 6, 7  |
| 2.12+                | ^8.0  | 9, 10, 11 | 5, 6  |

_(Older versions of Laravel, PHP, and Psalm were supported by version 1.x of the plugin, but they are no longer maintained)_


See [releases](https://github.com/psalm/psalm-plugin-laravel/releases) for more details about supported PHP, Laravel and Psalm versions.


## Quickstart

### Step 1: Install

```bash
composer require --dev psalm/plugin-laravel
```

### Step 2: Configure
If you didn't use Psalm on the project before, you need to create a Psalm config:
```bash
./vendor/bin/psalm --init
```

### Step 3: Enable the plugin:
```bash
./vendor/bin/psalm-plugin enable psalm/plugin-laravel
```

### Step 4: Run 🚀
Run your usual Psalm command:
```bash
./vendor/bin/psalm
```

You can customize Psalm configuration using [XML config](https://psalm.dev/docs/running_psalm/configuration/)
and/or [cli parameters](https://psalm.dev/docs/running_psalm/command_line_usage/).

**Recommendation**: use [baseline file](https://psalm.dev/docs/running_psalm/dealing_with_code_issues/#using-a-baseline-file) and increase
`errorLevel` at least to `4`: this way you can catch more issues.
Step by step set `errorLevel` to `1` and use Psalm and this plugin at full power 🚀.  


## Configuration

The plugin can be configured via XML elements inside the `<pluginClass>` tag in your `psalm.xml`:

```xml
<plugins>
    <pluginClass class="Psalm\LaravelPlugin\Plugin">
        <failOnInternalError>false</failOnInternalError>
        <modelDiscovery source="static" />
    </pluginClass>
</plugins>
```

### `failOnInternalError`

- **Type:** `true` | `false`
- **Default:** `false`

When the plugin encounters an internal error (e.g. failing to boot the Laravel app or generate stubs), it normally prints a warning and disables itself for that run.
Set this to `true` to throw the exception instead — useful in CI to ensure the plugin is actually running.

### `modelDiscovery`

- **Attribute:** `source`
- **Values:** `static` (default)
- **Default:** `static`

Controls how Eloquent model properties (columns) are discovered.

- `static` — Parses your migration files to infer column names and types. This enables type-aware property access on models (e.g. `$user->email` resolves to `string`).
- Any other value — Disables migration-based property discovery. Use this if you prefer to rely solely on `@property` PHPDoc annotations on your model classes.

### Model directories

The plugin scans directories to discover Eloquent model classes.
You can configure which directories are scanned by publishing a Laravel config file at `config/psalm-laravel.php`:

```php
<?php

return [
    'model_locations' => [
        'app/Models',
        'app/Domain/Models',
    ],
];
```

If not set, the plugin falls back to the `ide-helper.model_locations` config key, and then to `app/Models/` and `app/`.

### `PSALM_LARAVEL_PLUGIN_CACHE_PATH`

The plugin generates stub files at runtime (aliases, etc.) and caches them to a temp directory.
Set this environment variable to override the cache location:

```bash
PSALM_LARAVEL_PLUGIN_CACHE_PATH=/path/to/cache ./vendor/bin/psalm
```

By default, stubs are cached in the system temp directory (`sys_get_temp_dir()`).


## How it works

Under the hood it reads Laravel's native `@method` annotations on facade classes and generates alias stubs from `Facade::defaultAliases()`. It also ships hand-crafted stubs for taint analysis and special cases.

It also parses any database migrations it can find to try to understand property types in your database models.


## Psalm-Laravel-Plugin or Larastan?

Both! It's fine to use both tools at the same project: they use different approaches to analyze code, and thus you can find more bugs!
Psalm and PHPStan use almost the same syntax annotations, so you should not have any conflicts.
