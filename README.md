# Laravel Psalm Plugin

<p align="center">
<a href="https://packagist.org/packages/psalm/plugin-laravel"><img src="https://img.shields.io/packagist/v/psalm/plugin-laravel.svg" alt="Packagist version"></a>
<a href="https://packagist.org/packages/psalm/plugin-laravel"><img src="https://img.shields.io/packagist/dt/psalm/plugin-laravel.svg" alt="Packagist downloads"></a>
<a href="https://shepherd.dev/github/psalm/psalm-plugin-laravel"><img src="https://shepherd.dev/github/psalm/psalm-plugin-laravel/coverage.svg" alt="Type coverage"></a>
<a href="https://github.com/psalm/psalm-plugin-laravel/actions/workflows/tests.yml"><img src="https://github.com/psalm/psalm-plugin-laravel/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
</p>

Laravel static analysis with built-in security scanning.

The only free tool that combines deep Laravel static analysis with taint-based vulnerability detection that traces user input from request to sink: SQL injection, XSS, shell injection, file traversal, SSRF, open redirects, timing-unsafe secret comparisons, and LLM prompt injection (`laravel/ai` agents).
Everything runs inside your project and your CI. No account, no cloud upload, no code leaves your machine.

```php
$sortBy = $request->input('sort');      // tainted source: user input

User::query()->orderBy($sortBy)->get(); // tainted sink: reaches a column name
```

<p align="center">
    <img src="https://raw.githubusercontent.com/psalm/psalm-plugin-laravel/master/docs/assets/screenshot-taint.png" alt="Psalm reporting a tainted SQL finding, tracing $sortBy from Request::input() into orderBy()" width="100%">
</p>

Real output on a fresh Laravel app, tracing the two lines above from source to sink.

> [!NOTE]
> Already using Larastan? psalm-laravel **complements** it with security analysis that PHPStan cannot provide. See [the comparison](#psalm-laravel-or-larastan) below.

## Install

```bash
composer config minimum-stability dev && composer config prefer-stable true
composer require --dev psalm/plugin-laravel:^4.15
./vendor/bin/psalm-laravel init
./vendor/bin/psalm-laravel analyze
```

Requires PHP 8.2+ and Laravel 12 or 13.
Full matrix under [Versions & Dependencies](#versions--dependencies).

* [Psalm 7.x](https://github.com/vimeo/psalm/releases) is currently in beta, which is the only reason dev stability is needed. `prefer-stable true` keeps every other package in your project on stable releases, so Psalm itself is the single beta you pull in.
* Want zero beta packages? The 3.x line runs on stable Psalm 6 and needs no stability flags at all: `composer require --dev psalm/plugin-laravel:^3`. It carries the same security checks, and additionally supports Laravel 11.
* `init` writes a `psalm.xml` at the project root with the plugin enabled, `errorLevel="4"` by default (`--level 1` is strictest, `--level 8` the most lenient), Laravel-friendly issue handler defaults, and `runTaintAnalysis="true"`. Pass `--force` to overwrite an existing `psalm.xml` without prompting.
* `analyze` delegates to `vendor/bin/psalm` and passes the exit code through, so you can invoke `./vendor/bin/psalm` directly instead.

On the 3.x line (Psalm 6) security scanning is a separate mode rather than an extra check: enabling it makes Psalm report `Tainted...` issues and suppress every type issue. Keep `runTaintAnalysis` out of your `psalm.xml` there, which is why `init` on 3.x omits it, and pass the flag only for the security pass. Putting it in the config turns every run taint-only, including the `--set-baseline` run below.

```bash
./vendor/bin/psalm                  # types only
./vendor/bin/psalm --taint-analysis # security only
```

Psalm 7, and therefore the 4.x plugin line, merged the two: one run reports both.

## Security scanning

Plugin ships Laravel-specific taint stubs that track user input from source to sink across your entire codebase.
Unlike pattern-matching tools, Psalm follows dataflow across function boundaries, so input that travels through helper functions, service classes, and any number of call layers is still caught.

| Vulnerability           | OWASP    | Example sinks                                                                              |
|-------------------------|----------|--------------------------------------------------------------------------------------------|
| SQL injection           | A03:2021 | `orderBy()` column, `orderByRaw()`, `DB::select()`, `DB::statement()`, `DB::unprepared()`  |
| XSS                     | A03:2021 | `response()`, `new HtmlString()`, mailable `html()`                                        |
| Shell injection         | A03:2021 | `Process::path()->run()`, `app(Kernel::class)->call()`                                     |
| File traversal          | A01:2021 | `Storage::disk()->get()`, `->put()`, `->delete()`                                          |
| Open redirect           | A01:2021 | `redirect()`, `redirect()->to()`                                                           |
| SSRF                    | A10:2021 | `Http::withOptions()->get()` and the rest of `PendingRequest`                              |
| Crypto misuse           | A02:2021 | encryption and hashing taint escape or unescape                                            |
| Timing attack (CWE-208) | A02:2021 | a secret compared with `===`, `<=>`, or `strcmp()`                                         |

You can read more about how the plugin's taint analysis works and what vulnerabilities it detects in [docs/security.md](docs/security.md).

## Custom checks

13 Laravel-aware checks on top of Psalm's built-in diagnostics, each with a docs page explaining what it detects and how to fix it:

* [UndefinedModelRelation](docs/issues/UndefinedModelRelation.md): a relation name in `with()`, `load()`, or `whereHas()` that resolves to no relationship on the model.
* [UnknownModelAttribute](docs/issues/UnknownModelAttribute.md): a typo'd key passed to `create()`, `fill()`, or `update()` that matches no known attribute.
* [UnresolvableAppendedModelAttribute](docs/issues/UnresolvableAppendedModelAttribute.md): an `$appends` entry with no backing accessor, which is a runtime `BadMethodCallException` on `toArray()`.
* [OctaneIncompatibleBinding](docs/issues/OctaneIncompatibleBinding.md): a `singleton()` closure that resolves a request-scoped service, auto-enabled when `laravel/octane` is installed.
* [NoEnvOutsideConfig](docs/issues/NoEnvOutsideConfig.md): `env()` called outside the config directory, where it returns `null` once the config is cached.

See [docs/issues/index.md](docs/issues/index.md) for the full catalog.

## Adopting it on an existing codebase

The first run on an untouched project will report a lot. Fix the security findings first, then park the type issues in a [baseline](https://psalm.dev/docs/running_psalm/dealing_with_code_issues/#using-a-baseline-file) so only new code is checked. The noisier checks are opt-in and off by default, so nothing here depends on rewriting your codebase.

```bash
./vendor/bin/psalm --set-baseline=psalm-baseline.xml
```

> [!IMPORTANT]
> `--set-baseline` records **every** issue it sees, security findings included, and a baselined `TaintedSql` stops being reported.
> After generating the baseline, delete the `<Tainted...>` blocks from `psalm-baseline.xml`, otherwise the vulnerabilities you just found go quiet.

Full playbook, including the strictness ramp and how to turn down noise: [docs/adoption.md](docs/adoption.md).

## Continuous integration

```bash
./vendor/bin/psalm-laravel add github
```

Writes a ready-to-commit `.github/workflows/psalm.yml` that runs the plugin on pull requests and on pushes to your default branch, and uploads security findings to GitHub Code Scanning. See [docs/github-actions.md](docs/github-actions.md) for what the generated workflow does and how to customize it.

## Configuration

You can customize Psalm configuration using [XML config](https://psalm.dev/docs/running_psalm/configuration/)
and/or [cli parameters](https://psalm.dev/docs/running_psalm/command_line_usage/).

For plugin configuration options, see [docs/config.md](docs/config.md).

## Versions & Dependencies

Maintained versions:

| Laravel Psalm Plugin                 | PHP  |      Laravel |   Psalm | Plugin Status |
|--------------------------------------|------|-------------:|--------:|---------------|
| **4.x** (recommended)                | 8.2+ |       12, 13 |  7-beta | Stable        |
| 3.x ([upgrade](UPGRADING.md#3x--4x)) | 8.2+ |   11, 12, 13 |       6 | Stable        |
| 2.x ([upgrade](UPGRADING.md#2x--3x)) | 8.0+ | 8, 9, 10, 11 | 4, 5, 6 | Unmaintained  |
| 1.x ([upgrade](UPGRADING.md#1x--2x)) | 7.1+ |   5, 6, 7, 8 |    3, 4 | Unmaintained  |

See [releases](https://github.com/psalm/psalm-plugin-laravel/releases) for more details about supported PHP, Laravel and Psalm versions.

<details>
<summary><b>How it works</b></summary>

Under the hood the plugin boots your actual Laravel application (or an [Orchestra Testbench](https://github.com/orchestral/testbench) skeleton when analyzing a package).
This is not a just a classic static read of your code: config is loaded, facade aliases are resolved via `Illuminate\Foundation\AliasLoader` (including aliases from `config/app.php` and package discovery), and service providers run.
It also ships hand-crafted stubs for taint analysis and special cases.

For Eloquent model metadata (casts, appended attributes, relations), the plugin goes a step further and instantiates each model class, constructor-less, via reflection, replaying its trait and attribute initializers to read the runtime-computed fields.
This never needs a database connection: the model is never booted and no query runs. Column names and types instead come from parsing SQL schema dumps (`php artisan schema:dump`) and PHP migration files.

What that does and does not execute: booting the framework runs your service providers, exactly as any `php artisan` command does, so the plugin needs the same trust level you already give artisan.
It never handles an HTTP request, never boots a model, never opens a database connection, and never runs a query.

</details>

## Psalm-Laravel or Larastan?

**Use both.** They solve different problems:

- **Larastan** excels at Laravel-specific type rules: `model-property` validation, `view-string` checks, and 17+ custom rules.
- **Psalm-Laravel** in addition to type checks, it provides taint-based security analysis that PHPStan structurally [cannot offer](https://github.com/phpstan/phpstan/issues/8038), plus deep type support for Request data, Eloquent attributes, scopes, attributes, etc.

| Tool              | PHP types | Laravel types | Taint analysis      | Free      |
|-------------------|-----------|---------------|---------------------|-----------|
| **Psalm-Laravel** | Yes       | Yes           | Yes, dataflow       | Yes       |
| Larastan          | Yes       | Yes           | No                  | Yes       |
| Mago              | Yes       | No            | Superglobals only   | Yes       |
| SonarQube         | Partial   | No            | Yes, generic        | Paid only |
| Semgrep           | No        | No            | Yes, interfile paid | Free tier |
| Snyk Code         | No        | Claimed       | Yes, generic        | Freemium  |

The first three rows are from our own testing. The commercial rows summarize vendor documentation, so check their current tiers before relying on them.

Psalm and PHPStan use almost the same annotation syntax, so they work side by side without conflicts.


## Contributing

There are [contributing docs](docs/contributing/README.md) that may help you with contributions.
