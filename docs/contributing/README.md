---
title: Contributing
nav_order: 3
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
    A["Plugin::__invoke"] --> B["Boot Laravel app"]
    B --> C["Build schema\n(if columnFallback=migrations)"]
    C --> D["Generate alias stubs\n(from AliasLoader)"]
    D --> E["Register handlers"]
    E --> F["Register stubs"]

    E --- handlers["
        Application — ContainerHandler, OffsetHandler
        Auth — AuthHandler, GuardHandler, RequestHandler
        Collections — CollectionPluckHandler, CollectionFilterHandler
        Console — CommandArgumentHandler
        Eloquent — ModelRegistrationHandler,
        ModelMethodHandler, BuilderScopeHandler, CustomCollectionHandler
        Magic — MethodForwardingHandler
        Helpers — CacheHandler, PathHandler, TransHandler
        Translations — TranslationKeyHandler
        Views — MissingViewHandler
        Rules — NoEnvOutsideConfigHandler, ModelMakeHandler
        Validation — ValidatedTypeHandler, ValidationTaintHandler
        SuppressHandler
    "]

    F --- stubs["
        stubs/common/ (types + taint annotations)
        stubs/12/, stubs/13/ (version-specific)
        aliases.stubphp (generated)
    "]

    G["Psalm scans all project files"] -.->|afterCodebasePopulated| H["ModelRegistrationHandler"]
    H --- models["
        Discover Model subclasses
        Register property handlers:
        relationship > factory > accessor > column
    "]
```

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
LARAVEL_INSTALLER_VERSION=12.11.2 composer test:app # run over a specific Laravel version

# single test file
./vendor/bin/phpunit tests/Unit/Handlers/Auth/AuthHandlerTest.php
./vendor/bin/phpunit --filter=AuthTest tests/Type/
```

## Code style

- PER Coding Style 3.0 (powered by php-cs-fixer: run `composer cs` to apply fixes)
- Never use `@psalm-suppress` — fix the issue or add to `psalm-baseline.xml`
- Explain decisions and ideas in comments

```bash
composer cs     # auto-fix style issues
composer rector # run rector refactoring
```

## How to add a stub

Stubs override Laravel's type signatures. Place them in:

- `stubs/common/` — shared across Laravel versions (includes both type stubs and taint annotations)
- `stubs/12/`, `stubs/13/` — version-specific overrides

Rules:
- Verify signatures against actual Laravel code (not against Laravel PHPDoc or method signatures)
- Add a type test in `tests/Type/tests/` to prevent regression
- For taint annotations, see [Taint Analysis Stubs](taint-analysis.md)

### Stub merging: how Psalm combines annotations

When **multiple stub files declare the same method on the same class**, Psalm reuses a single MethodStorage object and re-applies docblock parsing. The merging rules differ by annotation kind:

- **Type annotations** (`@return`, `@param`): last-loaded file wins (direct assignment `=`)
- **Taint annotations** (`@psalm-taint-*`): all files accumulate (bitwise OR `|=`)

This means splitting type and taint annotations for the same method across two stub files is fragile -- the type that "wins" depends on file loading order. Always put both in the same file.

When a **class stub and a trait stub** both declare the same method, Psalm creates **separate** MethodStorage objects -- one per class/trait. There is no cross-merging: if `Connection.stubphp` overrides a method defined in `ManagesTransactions.stubphp`, the trait's annotations (including taints) are ignored for that method. To keep both type and taint annotations, put them on the class stub.

Stub files are loaded in alphabetical order (sorted by full path) to ensure deterministic results across OSes.

## How to add a handler

Handlers implement Psalm event interfaces to override type inference.
Create the handler class in the appropriate `src/Handlers/` subdirectory, then register it in `Plugin::registerHandlers()`.

### Psalm hooks used by the plugin

Psalm processes code in phases. Each hook fires at a specific phase and has different data available.
Analysis hooks are hot paths — they fire on every matching expression. Scanning hooks fire once per class or once total.

```mermaid
flowchart LR
    subgraph scanning ["Phase 1: Scanning"]
        direction TB
        S1["AfterClassLikeVisitInterface
        fires after each class/trait/interface
        ----
        data: ClassLikeStorage
        (direct parent only), AST statements
        ----
        ContainerHandler
        ModelMethodHandler
        SuppressHandler"]
    end

    subgraph populated ["Phase 2: Codebase populated"]
        direction TB
        P1["AfterCodebasePopulatedInterface
        fires once, after all classes are known
        ----
        data: full Codebase, complete
        ClassLikeStorage (full parent chain)
        ----
        ModelRegistrationHandler
        SuppressHandler"]
    end

    subgraph analysis ["Phase 3: Analysis (hot path)"]
        direction TB
        A1["FunctionReturnTypeProvider
        on each global function call
        ----
        args, call location, file path
        ----
        CacheHandler, PathHandler
        TranslationKeyHandler, TransHandler
        MissingViewHandler
        NoEnvOutsideConfigHandler"]

        A2["MethodReturnTypeProvider
        on each method call
        ----
        args, class FQCN, method name
        ----
        AuthHandler, GuardHandler
        RequestHandler, ContainerHandler
        CommandArgumentHandler
        MethodForwardingHandler
        ModelMethodHandler
        BuilderScopeHandler, PathHandler
        CollectionPluckHandler
        CollectionFilterHandler
        MissingViewHandler
        ValidatedTypeHandler"]

        A3["MethodParamsProvider
        before type-checking args
        ----
        overrides parameter types
        ----
        AuthHandler"]

        A4["Property providers
        Existence / Type / Visibility
        ----
        property name, class FQCN
        read/write context
        ----
        Model property handlers
        (registered via closures)"]

        A5["AddTaints / RemoveTaints
        on each expression with data flow
        ----
        expression, node type info
        ----
        ValidationTaintHandler"]

        A6["AfterExpressionAnalysis
        on each expression
        ----
        expression AST, codebase
        ----
        ModelMakeHandler"]
    end

    scanning --> populated --> analysis
```

### Registering handlers

There are two ways to register:

1. **Class-level** (most handlers): implement the interface, register via `$registration->registerHooksFromClass(MyHandler::class)` in `Plugin::registerHandlers()`
2. **Closure-level** (model property handlers): register via `$providers->property_type_provider->registerClosure(...)` — used by `ModelRegistrationHandler` to bind property handlers per-model after codebase is populated

See [Architecture Decisions](decisions.md) for design rationale, [Laravel Magic Call Patterns](laravel-magic-call-patterns.md) for how Laravel's __call/__callStatic chains work, [Psalm Type Annotations](types.md) for a quick reference of all supported types and annotations, and [Debugging with Xdebug](xdebug.md) for stepping through handler code.

## External resources

- [Authoring Psalm Plugins](https://psalm.dev/docs/running_psalm/plugins/authoring_plugins/)
