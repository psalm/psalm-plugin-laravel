---
title: Contributing
nav_order: 7
has_children: true
---

# Contributing

## How the plugin works

The plugin boots a Laravel application, then hooks into Psalm's event system to override type inference for Laravel APIs.

The app is needed at boot time to read config values (e.g. `auth.php` guards), resolve facade aliases via `AliasLoader`, and load service providers.
When run inside a Laravel project, the plugin loads the project's own `bootstrap/app.php` — so it sees the real config, routes, and providers.
When no `bootstrap/app.php` is found (e.g. analyzing a Laravel package, or running the plugin's own tests), it falls back to [Orchestra Testbench](https://github.com/orchestral/testbench) which provides a minimal Laravel app skeleton.

See `ApplicationProvider::doGetApp()` for the resolution logic.

```mermaid
flowchart TD
    A["Plugin::__invoke"] --> B["Parse PluginConfig from psalm.xml"]
    B --> C["Boot Laravel app\n(ApplicationProvider::bootApp)"]
    C --> D["Build migration schema\n(only if columnFallback=migrations)"]
    D --> E["Init facade→service map\n(FacadeMapProvider)"]
    E --> F["Init translation / view / env handlers\n(from booted app state)"]
    F --> G["Register handlers\n(Plugin::registerHandlers)"]
    G --> H["Register stubs\n(Plugin::registerStubs)"]

    H --- stubs["
        stubs/common/ (types + taint annotations)
        versioned dirs, ascending (e.g. stubs/12.42.0/, stubs/13.5.0/, stubs/13.8.0/)
        stubs/integrations/carbon/ (gated on installed nesbot/carbon version)
        aliases.phpstub (generated here from AliasLoader)
    "]

    I["Psalm scans all project files"] -.->|afterCodebasePopulated| J["ModelRegistrationHandler"]
    I -.->|afterCodebasePopulated| K["Eloquent Builder subclass fix-ups:\nBuilderSubclassQueryMixinHandler (restores dropped Query Builder @mixin)\nBuilderNativeStaticReturnTypeHandler (native ': static' return becomes docblock 'static')"]
    J --- models["
        Discover Model subclasses
        Register per-model property/method closures:
        relationship > factory > accessor > column
    "]
```

The whole `__invoke` body is wrapped in a try/catch: on any internal error the plugin reports a warning and disables itself for the run (or rethrows when `failOnInternalError` is set). See `src/Internal/InternalErrorReporter.php`.

Bootstrap failures are a special case: `ApplicationProvider` swallows a `bootstrap()` throw to keep the run alive (one bad `config/*.php` must not disable the plugin), so they never reach the try/catch above. `Plugin::__invoke` checks `ApplicationProvider::getBootstrapError()` right after boot and routes it through `InternalErrorReporter::reportDegradedBoot()`: a "degraded mode" warning by default, or escalation to the regular internal-error path when `failOnInternalError` is set (issue #1096). Note that Psalm's `--no-progress` flag installs a `VoidProgress`, which silences all `Progress::warning()` output, including these.

## Getting started

```bash
git clone git@github.com:psalm/psalm-plugin-laravel.git
cd psalm-plugin-laravel
composer install
composer test        # lint + psalm + unit + type tests
```

## Running tests

```bash
composer test          # full suite (lint + psalm + unit + type)
composer test:unit     # PHPUnit unit tests only
composer test:type     # type tests only (psalm-tester)
composer psalm         # self-analysis of plugin source
composer test:app      # creates a fresh Laravel project, scaffolds common class types (`make:xxx`), installs the plugin, and runs Psalm on the result
LARAVEL_INSTALLER_VERSION=12.12.2 composer test:app # run over a specific Laravel version

# single test file
./vendor/bin/phpunit tests/Unit/Handlers/Auth/AuthHandlerTest.php
./vendor/bin/phpunit --filter=AuthTest tests/Type/
```

## Code style

- PER Coding Style 3.0 (powered by php-cs-fixer: run `composer cs` to apply fixes)
- Explain decisions and ideas in comments

```bash
composer cs     # auto-fix style issues
composer rector # run rector refactoring
```

## How to add a stub

Stubs override Laravel's type signatures. Place them in:

- `stubs/common/` — shared across Laravel versions (includes both type stubs and taint annotations)
- `stubs/<version>/` — version-specific overrides, loaded when the installed Laravel is `>=` the dir name (`version_compare`). Both major-only (`stubs/13/`) and patch-level (`stubs/13.8.0/`) names work; currently `stubs/12.42.0/`, `stubs/13.5.0/`, and `stubs/13.8.0/` exist
- `stubs/integrations/<package>/` — optional stubs for third-party packages, gated on the package being installed (currently `carbon/`, with a `pre-3.12/` subdir loaded only for older Carbon; see `src/Stubs/CarbonStubProvider.php`)

Rules:
- Verify signatures against actual Laravel code (not against Laravel PHPDoc or method signatures)
- Add a type test in `tests/Type/tests/` to prevent regression
- For taint annotations, see [Taint Analysis Stubs](taint-analysis.md)

### Stub merging: how Psalm combines annotations

When **multiple stub files declare the same method on the same class**, Psalm reuses a single MethodStorage object and re-applies docblock parsing. The merging rules differ by annotation kind:

- **Type annotations** (`@return`, `@param`): last-loaded file wins (direct assignment `=`)
- **Taint annotations** (`@psalm-taint-*`): all files accumulate (bitwise OR `|=`)

This means splitting type and taint annotations for the same method across two stub files is fragile -- the type that "wins" depends on file loading order. Always put both in the same file.

When a **class stub and a trait stub** both declare the same method, Psalm creates **separate** MethodStorage objects -- one per class/trait. There is no cross-merging: if `Connection.phpstub` overrides a method defined in `ManagesTransactions.phpstub`, the trait's annotations (including taints) are ignored for that method. To keep both type and taint annotations, put them on the class stub.

Registration order (`Plugin::registerStubs()`): all `common` files, then version dirs ascending (`array_merge`). Since type annotations are last-loaded-wins, this order (not alphabetical path) decides overrides.

### Version-specific overrides (conditional stub loading)

A file in a version dir (`stubs/13.16.0/...`, loaded when installed Laravel `>=` that version) overrides the same-named `common` file **per method**. Multiple version dirs cascade ascending: per method, the highest dir `<=` the installed version wins; a method it doesn't redeclare falls through to lower dirs, then `common`. (Verified: with `common` + `12.6.0` + `12.8.0` all declaring `MessageBag::has`, `12.8.0` won; `missing()` declared only in `12.6.0` survived; `isEmpty()` came from `common`.)

Authoring an override:

- Declare only the changed methods; the rest merge from `common`.
- Copy the full class header (`extends`/`implements` + `use`) verbatim, because a class re-declaration resets Psalm's interface list and silently strips contracts (see stub-authoring rules).
- Types replace, taints accumulate (OR), so keep both for a method in one file.

**Common vs version dir.** Return narrowing that holds across all versions (Laravel only improved its annotation) goes in `common`. A parameter widened by behavior present only in a newer Laravel (e.g. `firstOrNew`'s `values` taking `\Closure|array` only on 13) must go in the version dir: widening `common` would tell Psalm a call is valid that fatals at runtime on older versions (silent false negative).

### Testing version-specific stubs

A type test that asserts a `stubs/<version>/` override would fail on the lower cells of the CI matrix (`.github/workflows/tests.yml` runs `test:type` over Laravel `^13.0`, `^12.4` and `^11.35`), because the override does not load on the older Laravel. Gate such a test with a `--SKIPIF--` section so it runs only where the stub applies:

```
--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.42.0');
--FILE--
... assertion of the version-specific behavior ...
```

`LaravelVersion::skipBelow($version)` skips when the installed Laravel is older than the stub dir (`skipFrom($version)` does the reverse for behavior only on older lines). The `--SKIPIF--` script runs in a bare process from the project root, so it requires the autoloader via `getcwd()`. See `tests/Type/tests/Http/PendingRequestTest.phpt` for a worked example (the async HTTP client types are 12.42+).

## How to add a handler

Handlers implement Psalm event interfaces to override type inference.
Create the handler class in the appropriate `src/Handlers/` subdirectory, then register it in `Plugin::registerHandlers()`.

### Psalm hooks used by the plugin

Psalm processes code in phases. Each hook fires at a specific phase and has different data available.
Analysis hooks are hot paths — they fire on every matching expression. Scanning hooks fire once per class or once total.
Source of truth for which handler implements which hook: `Plugin::registerHandlers()` plus each handler's `implements` clause.

```mermaid
flowchart TD
    subgraph P1["Phase 1 — Scanning (per class/trait/interface)"]
        A1["AfterClassLikeVisitInterface"]
    end

    subgraph P2["Phase 2 — Codebase populated (fires once)"]
        B1["AfterCodebasePopulatedInterface"]
    end

    subgraph P3["Phase 3 — Analysis (hot path, per file)"]
        direction TB
        C1["BeforeFileAnalysisInterface"] --> C2

        subgraph LOOP["repeats per statement / expression"]
            direction TB
            C2["BeforeStatementAnalysisInterface"] --> C3["BeforeExpressionAnalysisInterface"]
            C3 --> C4["Type/taint providers on the matched expression:
            FunctionReturnTypeProviderInterface
            MethodReturnTypeProviderInterface
            MethodParamsProviderInterface
            MethodExistenceProviderInterface
            MethodVisibilityProviderInterface
            property existence/type/visibility providers
            AddTaintsInterface / RemoveTaintsInterface"]
            C4 --> C5["AfterExpressionAnalysisInterface"]
            C5 --> C6["AfterMethodCallAnalysisInterface"]
        end

        C6 --> C7["AfterFunctionLikeAnalysisInterface"]
        C7 --> C8["AfterFileAnalysisInterface"]
    end

    subgraph P4["Phase 4 — Run complete (fires once)"]
        D1["AfterAnalysisInterface"]
    end

    P1 --> P2 --> P3 --> P4
```

### Registering handlers

There are two ways to register:

1. **Class-level** (most handlers): implement the interface, register via `$registration->registerHooksFromClass(MyHandler::class)` in `Plugin::registerHandlers()`
2. **Closure-level** (model property handlers): register via `$providers->property_type_provider->registerClosure(...)` — used by `ModelRegistrationHandler` to bind property handlers per-model after codebase is populated

See [Architecture Decisions](decisions.md) for design rationale, [Laravel Magic Call Patterns](laravel-magic-call-patterns.md) for how Laravel's __call/__callStatic chains work, [Psalm Type Annotations](types.md) for a quick reference of all supported types and annotations, and [Debugging with Xdebug](xdebug.md) for stepping through handler code.

## External resources

- [Authoring Psalm Plugins](https://psalm.dev/docs/running_psalm/plugins/authoring_plugins/)
