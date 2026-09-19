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
        <findPromptInjection value="true" />
        <experimental value="true" />
        <failOnInternalError value="true" />
        <configDirectory name="app/Config" />
        <blade enabled="true" />
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

When enabled, the plugin checks that a view name passed to `view()`, `Factory::make()`/`first()`/`renderWhen()`/`renderUnless()`/`renderEach()` (and the `View` facade), `ResponseFactory::view()`/`response()->view()` (and the `Response` facade), `Router::view()` (and the `Route` facade), `MailMessage::view()`/`markdown()`, or `TestResponse::assertViewIs()` references a Blade template that exists on disk.
Only string literal view names are validated — dynamic names and namespaced views (e.g., `mail::html.header`) are skipped.

See [MissingView](issues/MissingView.md) for details.

### Example

```xml
<findMissingViews value="true" />
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

Controls the reporting level of `TaintedLlmPrompt`, raised when untrusted input reaches a `laravel/ai` prompt (`Agent::prompt()`, `stream()`, `queue()`, `broadcast*()`, and the other sinks listed in [Security checks](security.md)). It is only relevant when the supported `laravel/ai` integration is installed; the plugin leaves the level alone otherwise.

- element omitted (`auto`): enforced when the supported `laravel/ai` integration is installed (`>=0.11.0 <1.0.0`). The plugin leaves the issue at Psalm's normal error level.
- `value="false"`: explicit opt-out. Only `TaintedLlmPrompt` is suppressed; model-output taint sources and their ordinary SQL/HTML/shell findings remain errors.
- `value="true"`: enforced inside the same integration gate. It does not enable the rule when `laravel/ai` is absent or unsupported.

If your agents already run prompt-injection middleware, annotate the guard instead of opting out globally: [Marking prompt-guard middleware as trusted](security.md#marking-prompt-guard-middleware-as-trusted).

Use the explicit opt-out only where direct prompt flows are intentional. In security-sensitive applications, the default auto mode reports prompts assembled from data the user did not knowingly submit (retrieved documents, scraped pages, webhook bodies, tool results). An explicit `<TaintedLlmPrompt errorLevel="..." />` in your `issueHandlers` always wins over this setting.

This governs the prompt sink direction only. Model output as a taint source (an agent's answer reaching SQL, HTML, a shell command, a header, or a file path) is reported as the usual `Tainted*` issues at their usual levels, on by default, because those findings do have an ordinary fix.

### Example

```xml
<findPromptInjection value="false" />
```

## `blade`

See [Blade template analysis](blade.md) for the full user guide (enabling it, suppression, ambient variables, taint reporting, and known limits).

**default**: off. Omit the element, or write `<blade enabled="false" />`.

```xml
<blade enabled="true" />
```

Opt in to analyzing Blade templates. The plugin compiles every `*.blade.php` file under the view paths of the booted application (`config('view.paths')` plus whatever service providers added) into a PHP "shadow" file, and adds those shadows to the Psalm run. The templates themselves are never handed to Psalm as PHP; only the compiled shadows are analyzed.

Opt-in because the compile pass costs time proportional to the number of templates, and because template analysis is new.

Notes on this release:

- Every template variable the plugin cannot prove a type for is `mixed`, silently. Contract annotations (`{{-- @var \App\Models\User $user --}}`, `@props([...])`) do not type the template's own body yet; they are read for the call-site checks below.
- Each template is analyzed on its own. `@include`, `@extends` and components are not followed.
- `{{-- @psalm-suppress SomeIssue --}}` in a template is carried into the compiled shadow.

### `cacheDir`

**default**: `blade/` inside the [plugin cache directory](#cache-directory)

```xml
<blade enabled="true" cacheDir="build/blade-shadows" />
```

Where the compiled shadows and their manifest are written. Absolute, or relative to the directory Psalm runs in. The plugin creates the directory, reuses a shadow whose template has not changed, and deletes shadows whose template is gone.

The default deliberately sits outside your project tree. A shadow file that one of your `<projectFiles>` patterns happens to match is treated by Psalm as a file of your own, which both reports issues at their compiled locations instead of the template's and makes Psalm drop taint flows that start in it. If you point `cacheDir` inside the project, exclude it from `<projectFiles>` (and from version control).

### `validateViewData`

**default**: off

```xml
<blade enabled="true" validateViewData="true" />
```

Check `view()` call sites against the contract their template declares, and report a declared variable the call never passes ([MissingViewVariable](issues/MissingViewVariable.md)) or a value that does not satisfy the declared type ([InvalidViewVariableType](issues/InvalidViewVariableType.md)).

A template declares its variables with `{{-- @var \App\Models\User $user --}}` comments and `@props([...])` entries. A template that declares nothing is never checked, so the rule costs you nothing until you annotate a template.

Recognized call shapes: the `view()` helper, `Factory::make()` and its `View` facade forms, `response()->view()`, `Mailable::view()` / `markdown()`, `MailMessage`'s equivalents, and any number of `with()` / `withErrors()` calls chained on top of them. The whole chain is read at once, so `view('profile')->with('name', $n)` is checked against the data the chain supplies in total, not against the empty data of its inner call.

Both checks decline rather than guess. The per-issue pages list every gate; the short version is that a dynamic view name, an unreadable `@props` array, an open data set (a spread, a dynamic key, `$mergeData`), a `mixed` on either side, and an unmodeled method in the chain each silence the check for that call.

### `reportUnusedViews`

**default**: off

```xml
<blade enabled="true" reportUnusedViews="true" />
```

Report a template ([UnusedView](issues/UnusedView.md)) that no statically-provable `view()` / `View::make()` call site and no `@include` / `@extends` from another template ever names.

One reference this plugin cannot resolve statically (a dynamic `view($name)` or `@include($name)`) anywhere in the project turns the check off for the whole run, printed as one warning: a lower bound on "used" is not enough to prove a template unused. See the issue page for the full list of call shapes this release recognizes.

Needs `enabled="true"`: the contracts only exist once the compile pass has read the templates.

### `reportUnusedViewData`

**default**: off

```xml
<blade enabled="true" reportUnusedViewData="true" />
```

Report a data key ([UnusedViewData](issues/UnusedViewData.md)) that the rendered template neither reads nor declares. Independent of `validateViewData`: same call shapes, opposite direction (that rule checks what the template asks for, this one checks what the call site hands over).

A key that a template reached through `@include` or `@extends` reads or declares counts as consumed, because those directives inherit the including template's whole scope. The chain is followed as far as every include in it names a literal template; one dynamic `@include($name)` at any depth silences the check for that call site alone, not for the run.

Enabling it makes every template recompile once, because the read set and the include graph are collected during compilation and a cache warmed without the flag holds neither. Declines rather than guesses: the issue page lists every gate, the load-bearing one being that a template whose compiled body does something that hides which names it reads (`@props`, `@aware`, `extract()`, a non-literal `compact()`) is never checked.

Needs `enabled="true"`: the read sets only exist once the compile pass has read the templates.

### Degradation

Blade analysis never fails a run. If the analyzed application binds no Blade compiler or no view finder (common for a package, or a trimmed-down bootstrap), if the cache directory cannot be written, or if Psalm's internals have moved under the plugin, the feature turns itself off for that run and prints one warning naming the cause. Templates that fail to compile are skipped and summarized in a single warning; run with `--debug` for the individual causes.

Psalm's `--no-progress` installs a progress implementation that discards warnings, so a degradation is invisible under that flag.

## Cache directory

**default**: `<psalm-cache-dir>/plugin-laravel` (inside Psalm's project-specific cache directory)

The plugin stores generated files (alias stubs) and cached migration schemas in this directory. By default, it uses a subdirectory inside Psalm's own cache, so `--clear-cache` removes plugin caches along with Psalm's.

### Migration schema cache

When `columnFallback="migrations"` is active, the plugin caches the parsed migration schema to disk so subsequent Psalm runs skip re-parsing unchanged migrations.

The cache key is a fingerprint of sorted migration and SQL dump file paths, hashes of their contents, and the plugin version. Any file change or plugin upgrade automatically invalidates the cache. Content hashes (rather than modification times) keep the cache usable on CI, where a fresh checkout stamps every file with the checkout time.

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
- [`findSerializedQueuedModels`](#findserializedqueuedmodels), off by default, turns on unless the project sets it explicitly.

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

**Recommended for CI.** Without this, a misconfigured environment causes the plugin to silently disable itself — your pipeline passes but without any plugin analysis.
With `failOnInternalError`, the Psalm run fails immediately, so you know the plugin isn't working.

### Example

```xml
<failOnInternalError value="true" />
```
