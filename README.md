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
 - [ ] Remove `barryvdh/laravel-ide-helper` dependency for more accurate attribute types
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


## How it works

Under the hood it just runs https://github.com/barryvdh/laravel-ide-helper and feeds the resultant stubs into Psalm, which can read PhpStorm meta stubs.

It also parses any database migrations it can find to try to understand property types in your database models.


## Psalm-Laravel-Plugin or Larastan?

Both! It's fine to use both tools at the same project: they use different approaches to analyze code, and thus you can find more bugs!
Psalm and PHPStan use almost the same syntax annotations, so you should not have any conflicts.
