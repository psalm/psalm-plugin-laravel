# Psalm plugin for Laravel

## The package is seeking maintainers

⚠️ This is a perfect opportunity to learn Laravel very deep and collaborate with other high-skilled developers.
At this moment, the package is maintained almost solely by [@alies-dev](https://github.com/sponsors/alies-dev), and he is looking for developers to build a team
who can constantly improve this package and the whole Psalm ecosystem.

Some ideas to implement:
 - [ ] Fully support custom Model Query Builders (medium)
 - [ ] Add an option to rely on Model `@property` declarations only
 - [ ] Get rid of `barryvdh/laravel-ide-helper` dependency and be more accurate with attribute types
 - [ ] Support .sql files for migrations (to find information about attributes and their types)

________


[![Packagist version](https://img.shields.io/packagist/v/psalm/plugin-laravel.svg)](https://packagist.org/packages/psalm/plugin-laravel)
[![Packagist downloads](https://img.shields.io/packagist/dt/psalm/plugin-laravel.svg)](https://packagist.org/packages/psalm/plugin-laravel)
[![Type coverage](https://shepherd.dev/github/psalm/psalm-plugin-laravel/coverage.svg)](https://shepherd.dev/github/psalm/psalm-plugin-laravel)
[![Tests](https://github.com/psalm/psalm-plugin-laravel/actions/workflows/test.yml/badge.svg)](https://github.com/psalm/psalm-plugin-laravel/actions/workflows/test.yml)
[![Tests](https://github.com/psalm/psalm-plugin-laravel/actions/workflows/test-laravel.yml/badge.svg)](https://github.com/psalm/psalm-plugin-laravel/actions/workflows/test-laravel.yml)

## Overview
This [Psalm](https://github.com/vimeo/psalm) plugin brings static analysis and type support to projects using Laravel. Our goal is to find as many type-related
 bugs as possible, therefore increasing developer productivity and application health. Find bugs without the overhead
 of writing tests!
 
 ![Screenshot](/assets/screenshot.png)


## Versions & Dependencies

| Laravel Psalm Plugin | PHP   | Laravel      | Psalm |
|----------------------|-------|--------------|-------|
| 2.10+                | ^8.0  | 9, 10, 11    | 5     |
| 2.0-2.9              | ^8.0  | 8, 9, 10, 11 | 4, 5  |
| 1.x                  | ^7.1  | 5, 6, 7, 8   | 3, 4  |

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

### Step 3: enable the plugin:
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
`errorLevel` at least to `4`: this way you can catch more issues. Step by step set `errorLevel` to `1` and use Psalm and this plugin at full power 🚀.  


## How it works

Under the hood it just runs https://github.com/barryvdh/laravel-ide-helper and feeds the resultant stubs into Psalm, which can read PhpStorm meta stubs.

It also parses any database migrations it can find to try to understand property types in your database models.


## Psalm-Laravel-Plugin or Larastan?

Both! It's fine to use both tools at the same project: they use different approaches to analyze code, and thus you can find more bugs!
Psalm and PHPStan use almost same the syntax annotations, so you should not have any conflicts.
