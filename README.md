# Psalm plugin for Laravel

Laravel static analysis with built-in security scanning.

[![Packagist version](https://img.shields.io/packagist/v/psalm/plugin-laravel.svg)](https://packagist.org/packages/psalm/plugin-laravel)
[![Packagist downloads](https://img.shields.io/packagist/dt/psalm/plugin-laravel.svg)](https://packagist.org/packages/psalm/plugin-laravel)
[![Type coverage](https://shepherd.dev/github/psalm/psalm-plugin-laravel/coverage.svg)](https://shepherd.dev/github/psalm/psalm-plugin-laravel)
[![Tests](https://github.com/psalm/psalm-plugin-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/psalm/psalm-plugin-laravel/actions/workflows/tests.yml)
[![Tests](https://github.com/psalm/psalm-plugin-laravel/actions/workflows/test-laravel-app.yml/badge.svg)](https://github.com/psalm/psalm-plugin-laravel/actions/workflows/test-laravel-app.yml)

The only free tool that combines deep Laravel type analysis with taint-based vulnerability detection.
Catches SQL injection, XSS, SSRF, shell injection, file traversal, and open redirects, without running your code.

> [!NOTE]
> Already using Larastan? psalm-laravel **complements** it with security analysis that PHPStan cannot provide.


![Screenshot](/docs/assets/screenshot.png)


## Security scanning

Plugin ships Laravel-specific taint stubs that track user input from source to sink across your entire codebase.
Unlike pattern-matching tools, Psalm follows dataflow across function boundaries, catching vulnerabilities that simpler scanners miss.

```php
// psalm-laravel catches this:
Route::get('/search', function (Request $request) {
    $sortByColumn = $request->input('sort'); // Tainted source: user input from HTTP request
    User::where('name', $request->input('name'))
        ->orderBy($sortByColumn) // 🚨 Tainted sink: unvalidated user input used in query builder
        ->get();

// Psalm output:
// ERROR TaintedSql: Detected tainted SQL
});
```

Taint analysis also works across helper functions, service classes, and any number of call layers.

```php
// UserController.php
$user->siteSettinsg['articles_sort'] = $request->input('sort'); // Tainted source: user input from HTTP request
$user->save();

// ArticlesConstoller.php
Articles::query()
    ->orderBy($user->siteSettinsg['articles_sort']) // 🚨 Tainted sink: unvalidated user input used in query builder
    ->get();

// Psalm output:
// ERROR TaintedSql: Detected tainted SQL
```

You can read more about how the plugin's taint analysis works and what vulnerabilities it detects in [docs/security.md](docs/security.md).

## Quickstart

### Step 1: Install

Since [Psalm 7.x](https://github.com/vimeo/psalm/releases) is currently in beta, allow dev (or beta) packages first:

```bash
composer config minimum-stability dev && composer config prefer-stable true
composer require --dev psalm/plugin-laravel
```

### Step 2: Generate a Laravel-tailored `psalm.xml`

```bash
./vendor/bin/psalm-laravel init
```

This writes a `psalm.xml` at the project root with the plugin already enabled, sensible `errorLevel`, and Laravel-friendly issue handler defaults. Pass `--level 1` (strictest) through `--level 8` (most lenient) to pick a starting strictness. Pass `--force` to overwrite an existing `psalm.xml` without prompting.

### Step 3: Run

```bash
./vendor/bin/psalm-laravel analyze
```

`analyze` delegates to `vendor/bin/psalm` and passes the exit code through, so you can also invoke `./vendor/bin/psalm` directly. Security taint analysis runs automatically, no extra flags needed.

**Existing projects:** the first run will likely report many issues. Create a [baseline](https://psalm.dev/docs/running_psalm/dealing_with_code_issues/#using-a-baseline-file) to suppress them and focus only on new code:

```bash
./vendor/bin/psalm --set-baseline=psalm-baseline.xml
```

From here, gradually increase `errorLevel` (start at `4`, work toward `1`) and shrink the baseline over time.

### Optional: wire up CI in one command

```bash
./vendor/bin/psalm-laravel add github
```

Writes a ready-to-commit `.github/workflows/psalm.yml` that runs the plugin on every push and pull request. See [docs/github-actions.md](docs/github-actions.md) for what the generated workflow does and how to customize it.

## Configuration

You can customize Psalm configuration using [XML config](https://psalm.dev/docs/running_psalm/configuration/)
and/or [cli parameters](https://psalm.dev/docs/running_psalm/command_line_usage/).

See [docs/config.md](docs/config.md) for all configuration options.

## Custom checks

The plugin ships advanced Laravel-aware static analysis checks that extend Psalm's built-in diagnostics.
See [docs/issues/index.md](docs/issues/index.md) for the full catalog.

## Versions & Dependencies

Maintained versions:

| Laravel Psalm Plugin                         | PHP  | Laravel   | Psalm | Status |
|----------------------------------------------|------|-----------|-------|--------|
| 4.x                                          | ^8.2 | 12, 13    | 7     | Stable |
| 3.x ([v4 upgrade guide](docs/upgrade-v4.md)) | ^8.2 | 11, 12    | 6     | Stable |
| 2.12+                                        | ^8.0 | 9, 10, 11 | 5, 6  | Legacy |

_(Older versions of Laravel, PHP, and Psalm were supported by version 1.x of the plugin, but they are no longer maintained)_

See [releases](https://github.com/psalm/psalm-plugin-laravel/releases) for more details about supported PHP, Laravel and Psalm versions.

## How it works

Under the hood it reads Laravel's native `@method` annotations on facade classes and generates alias stubs based on `Illuminate\Foundation\AliasLoader` (including aliases from your `config/app.php` and package discovery). It also ships hand-crafted stubs for taint analysis and special cases.

It also parses SQL schema dumps (`php artisan schema:dump`) and PHP migration files to infer column names and types in your database models.


## Psalm-Laravel or Larastan?

**Use both.** They solve different problems:

- **Larastan** excels at Laravel-specific type rules: `model-property` validation, `view-string` checks, and 17+ custom rules.
- **Psalm-Laravel** in addition to type checks, it provides taint-based security analysis that PHPStan structurally [cannot offer](https://github.com/phpstan/phpstan/issues/8038), plus deep type support for auth guards, Eloquent attributes, scopes, attributes, etc.

Psalm and PHPStan use almost the same annotation syntax, so they work side by side without conflicts.

**Larastan checks your types. We check your security. Use both.**


## Contributing

Maintained by [@alies-dev](https://github.com/sponsors/alies-dev).
There are [contributing docs](docs/contributing/README.md) that may help you (and your agents) with contributions.

Areas where help is especially needed:
- **Taint analysis coverage**: adding a stub is 5 to 15 lines of annotations and protects thousands of apps. See the [authoring guide](docs/contributing/taint-analysis.md).
- **Type inference** for Laravel magic (Eloquent, Facades, Collections).
- **New checks** that enforce Laravel best practices.
