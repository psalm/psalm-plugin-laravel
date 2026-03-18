[![Packagist version](https://img.shields.io/packagist/v/psalm/plugin-laravel.svg)](https://packagist.org/packages/psalm/plugin-laravel)
[![Packagist downloads](https://img.shields.io/packagist/dt/psalm/plugin-laravel.svg)](https://packagist.org/packages/psalm/plugin-laravel)
[![Type coverage](https://shepherd.dev/github/psalm/psalm-plugin-laravel/coverage.svg)](https://shepherd.dev/github/psalm/psalm-plugin-laravel)
[![Tests](https://github.com/psalm/psalm-plugin-laravel/actions/workflows/test.yml/badge.svg)](https://github.com/psalm/psalm-plugin-laravel/actions/workflows/test.yml)
[![Tests](https://github.com/psalm/psalm-plugin-laravel/actions/workflows/test-laravel.yml/badge.svg)](https://github.com/psalm/psalm-plugin-laravel/actions/workflows/test-laravel.yml)

# Psalm plugin for Laravel

Laravel static analysis with built-in security scanning.

The only free tool that combines deep Laravel type analysis with taint-based vulnerability detection.
Catches SQL injection, XSS, SSRF, shell injection, file traversal, and open redirects — without running your code.

> Already using Larastan? psalm-plugin-laravel **complements** it with security analysis that PHPStan cannot provide.

![Screenshot](/docs/assets/screenshot.png)


## Security scanning

Plugin ships Laravel-specific taint stubs that track user input from source to sink across your entire codebase.
Unlike pattern-matching tools, Psalm follows dataflow across function boundaries — catching vulnerabilities that simpler scanners miss.

```php
// psalm-plugin-laravel catches this:
Route::get('/search', function (Request $request) {
    $query = $request->input('q');
    DB::statement("SELECT * FROM users WHERE name = '$query'");
    // TaintedSql: user input flows directly to the SQL query
});
```

Taint analysis also works across helper functions, service classes, and any number of call layers:

```php
// Cross-function taint flow — pattern-matching tools miss this:
function getUserQuery(Request $request): string {
    return "SELECT * FROM users WHERE name = '" . $request->input('name') . "'";
}

Route::get('/users', function (Request $request) {
    DB::statement(getUserQuery($request));
    // Psalm catches this: taint flows Request -> getUserQuery() -> DB::statement()
});
```

### What it detects

| Vulnerability   | OWASP    | Examples                                                      |
|-----------------|----------|---------------------------------------------------------------|
| SQL Injection   | A03:2021 | `DB::statement()`, `DB::unprepared()`, raw query methods      |
| Shell Injection | A03:2021 | `Process::run()`, `Process::command()`                        |
| XSS             | A03:2021 | `Response::make()` with unescaped content                     |
| SSRF            | A10:2021 | `Http::get()`, `Http::post()` with user-controlled URLs       |
| File Traversal  | A01:2021 | `Storage::get()`, `File::delete()` with user-controlled paths |
| Open Redirect   | A01:2021 | `redirect()`, `Redirect::to()` with user-controlled URLs      |
| Crypto misuse   | A02:2021 | Tracks encryption/hashing taint escape and unescape           |

Security scanning runs automatically alongside type analysis — no extra configuration needed.

### How it compares

| Tool                     | Laravel-aware types | Taint analysis     | Free               |
|--------------------------|---------------------|--------------------|--------------------|
| **psalm-plugin-laravel** | Yes                 | Yes (dataflow)     | Yes                |
| Larastan                 | Yes                 | No (PHPStan can't) | Yes                |
| Enlightn Pro             | Partial             | No (rule-based)    | $99+/project       |
| SonarQube                | Generic PHP         | Yes (generic)      | Paid editions only |
| Semgrep                  | Pro tier only       | Pattern-based      | Limited free tier  |
| Snyk Code                | Generic             | Yes (generic)      | Freemium           |


## Versions & Dependencies

Maintained versions:

| Laravel Psalm Plugin | PHP   | Laravel   | Psalm | Status |
|----------------------|-------|-----------|-------|--------|
| 4.x                  | ^8.3  | 12, 13    | 7     | Beta   |
| 3.x                  | ^8.2  | 11, 12    | 6, 7  | Stable |
| 2.12+                | ^8.0  | 9, 10, 11 | 5, 6  | Legacy |

_(Older versions of Laravel, PHP, and Psalm were supported by version 1.x of the plugin, but they are no longer maintained)_

See [releases](https://github.com/psalm/psalm-plugin-laravel/releases) for more details about supported PHP, Laravel and Psalm versions.
Upgrading from v3? See the [v3 → v4 upgrade guide](docs/upgrade-v4.md).


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

### Step 4: Run
Run your usual Psalm command:
```bash
./vendor/bin/psalm
```

Security taint analysis runs automatically as part of the standard analysis in Psalm 7.
No extra flags are needed.

### Step 5 (existing projects): Create a baseline

On an existing codebase, the first run will likely report many issues.
A [baseline file](https://psalm.dev/docs/running_psalm/dealing_with_code_issues/#using-a-baseline-file) lets you suppress all current issues and focus only on new code:

```bash
./vendor/bin/psalm --set-baseline=psalm-baseline.xml
```

From here, gradually increase `errorLevel` (start at `4`, work toward `1`) and shrink the baseline over time.

## Configuration

You can customize Psalm configuration using [XML config](https://psalm.dev/docs/running_psalm/configuration/)
and/or [cli parameters](https://psalm.dev/docs/running_psalm/command_line_usage/).

See [docs/config.md](docs/config.md) for all configuration options.

## Custom issues

The plugin emits custom issues beyond Psalm's built-in checks:

- [NoEnvOutsideConfig](docs/issues/NoEnvOutsideConfig.md) — `env()` called outside `config/` directory
- [InvalidConsoleArgumentName](docs/issues/InvalidConsoleArgumentName.md) — `argument()` references undefined command argument
- [InvalidConsoleOptionName](docs/issues/InvalidConsoleOptionName.md) — `option()` references undefined command option


## How it works

Under the hood it reads Laravel's native `@method` annotations on facade classes and generates alias stubs based on `Illuminate\Foundation\AliasLoader` (including aliases from your `config/app.php` and package discovery). It also ships hand-crafted stubs for taint analysis and special cases.

It also parses any database migrations it can find to try to understand property types in your database models.


## psalm-plugin-laravel or Larastan?

**Use both.** They solve different problems:

- **Larastan** excels at Laravel-specific type rules: `model-property` validation, `view-string` checks, `NoUnnecessaryCollectionCall`, Blade analysis via Bladestan, and 17 custom rules.
- **psalm-plugin-laravel** in addition to type checks, it provides taint-based security analysis that PHPStan structurally [cannot offer](https://github.com/phpstan/phpstan/issues/8038), plus deep type support for auth guards, Eloquent attributes, scopes, attributes, etc.

Psalm and PHPStan use almost the same annotation syntax, so they work side by side without conflicts.

**Larastan checks your types. We check your security. Use both.**


## Looking for contributors

This package is maintained by [@alies-dev](https://github.com/sponsors/alies-dev) and is open to new contributors.
If you're passionate about Laravel internals and static analysis, consider joining the effort.

Areas where help is especially welcome:
 - [ ] Expanding taint analysis coverage (new Laravel security surfaces)
 - [ ] Support `.sql` migration files for attribute discovery
 - [ ] Full support for custom Model Query Builders

Contributing a taint stub is one of the highest-impact contributions you can make — each stub protects thousands of Laravel apps.
