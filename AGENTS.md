# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Psalm plugin for Laravel — provides static analysis support for Laravel projects by hooking into Psalm's event system.
Current Plugin version 4.x supports PHP 8.2+, Laravel 12-13, Psalm 7 (see README.md for more precise supported versions matrix).

## Structure
- `src/Handlers/` - Psalm hook handlers (Application, Auth, Collections, Console, Eloquent, Helpers, Rules, Validation, SuppressHandler)
- `src/Issues/` - Custom Psalm issue types
- `src/Providers/` - Service providers
- `src/Util/` - Utilities
- `stubs/` - Psalm stub files
- `tests/Type/` - Type-level tests (psalm-tester)
- `tests/Unit/` - Unit tests (phpunit)
- `tests/Application/` - Integration tests with real Laravel app


## Commands

- `composer test:unit -- --no-progress --colors=never --display-errors --display-warnings` - PHPUnit unit tests
- `composer test:type -- --no-progress` - PHPUnit type tests (psalm-tester)
- `composer test:app` - Run Psalm with this Plugin on a just created fresh Laravel app with basic classes
- `composer psalm -- --no-progress --no-suggestions --output-format=compact` - Run psalm self-analysis
- `composer cs -- --show-progress=none --no-ansi -n` - Auto-fix coding style issues
- `composer rector -- --no-progress-bar --no-ansi` - Run rector refactoring

```bash
# Run a single test file
./vendor/bin/phpunit tests/Unit/Handlers/Auth/AuthHandlerTest.php
./vendor/bin/phpunit --filter=AuthTest tests/Type/
```

For important changes, you can run a slow integration test (~6 minutes) on a real large Laravel project (14k PHP files, ~150 Models):

```shell
cd /Users/alies/code/psalm/IxDF-as-example
php -d memory_limit=-1 vendor/bin/psalm -c psalm.xml --no-suggestions --no-cache --no-progress
```

This project loads the plugin via symlink to `/Users/alies/code/psalm/psalm-plugin-laravel`.
Always ensure the path in `/Users/alies/code/psalm/IxDF-as-example/composer.json` is valid (expecially if you work in a git worktree):

```json
{
  "name": "psalm-plugin-laravel",
  "type": "path",
  "url": "../psalm-plugin-laravel",
  "options": {
    "symlink": true
  }
}
```

## Architecture

### Plugin Initialization (`src/Plugin.php`)

The plugin boots a real Laravel application instance (via Orchestra Testbench), then:

1. Generates handlers for support some Laravel magic (Eloquent Model attributes and casting, Facades, etc)
2. Registers event handlers with Psalm
3. Registers stub files (common + version-specific)

### Handlers (`src/Handlers/`)

Handlers implement Psalm event interfaces (`FunctionReturnTypeProviderInterface`, `MethodReturnTypeProviderInterface`, `PropertyExistenceProviderInterface`, etc.) to override type inference for Laravel APIs.

- **Application/**: Container resolution — types `app()`, `resolve()`, `make()`, and array access on the container
- **Auth/**: Auth facade/guard/request types — reads `auth.php` config to determine authenticatable models
- **Collections/**: Collection method return type narrowing (`pluck()`, `filter()`)
- **Console/**: Console command `argument()` and `option()` return type narrowing, emits issues for undefined argument/option names
- **Eloquent/**: Model methods, relationships, property accessors, factory types, and schema aggregation from migrations
- **Facades/**: Laravel Facade static call type inference
- **Helpers/**: Return types for `cache()`, path helpers, `trans()`
- **Rules/**: Static analysis rules (e.g., `NoEnvOutsideConfigHandler` — reports `env()` calls outside `config/` directory)
- **Validation/**: `validated()` return type narrowing based on validation rules, taint removal for validated fields
- **SuppressHandler.php**: Suppresses known false positives in Laravel framework classes

### Stubs (`stubs/`)

Override Laravel's type signatures for better analysis. Organized as:

- `stubs/common/` — shared across Laravel versions (includes both type stubs and taint annotations)
- `stubs/12/`, `stubs/13/` — version-specific overrides

Generated stubs (aliases.stubphp) are created at runtime by reading the app's `AliasLoader`.

**Stub loading:**

- `getCommonStubs()` scans `stubs/common/` recursively using `RecursiveDirectoryIterator`.
- Stubs are registered via `$registration->addStubFile()`.

**Stub authoring rules:**

- Always verify stub signatures against the actual Laravel source in `vendor/laravel/framework/src/Illuminate/`.
- When fixing a type issue, add or update a type test in `tests/Type/tests/` to prevent regression.
- Taint annotations (`@psalm-taint-source`, `@psalm-taint-sink`, `@psalm-taint-escape`, `@psalm-taint-unescape`, `@psalm-flow`) are placed directly in `stubs/common/` alongside type annotations. See `docs/contributing/taint-analysis.md` for the authoring guide.
- **Class declarations wipe reflected metadata.** When a stub re-declares a class (`class Foo { ... }`), Psalm's `ClassLikeNodeScanner` resets the class's `class_implements` / `parent_interfaces` list and re-populates it only from the stub's own `implements` / `extends` clauses. A stub written as `class MessageBag { ... }` without the `implements Jsonable, JsonSerializable, MessageBagContract, MessageProvider, Stringable` clause will silently strip those interfaces, breaking every caller typed on the contract. Always copy the full `implements` / `extends` / `use` declaration verbatim from Laravel source into the stub — even if you only want to add one method. Check existing siblings in `stubs/common/Support/` (Collection, HtmlString, Js, LazyCollection, Optional, Stringable, ValidatedInput) for the convention.

### Providers (`src/Providers/`)

- **ApplicationProvider**: Boots the Laravel app via Testbench
- **ApplicationInterfaceProvider**: Provides Application contract interface
- **ConfigRepositoryProvider**: Provides config repository access
- **SchemaStateProvider**: Static holder for parsed migration schema state, shared between Plugin and handlers
- **ModelMetadataRegistry**: Read-only per-model metadata store. Phase 1 populates schema, casts, trait flags, primary key (with HasUuids/HasUlids overrides), `fillable` / `guarded` / `appends` / `hidden` / `with` / `withCount`, connection, and morph alias. Populated during `AfterCodebasePopulated` by `ModelRegistrationHandler` (piggy-backs on the existing model iteration). Handlers query `ModelMetadataRegistry::for($fqcn)` inside event callbacks. The sibling `@internal ModelMetadataRegistryBuilder` owns mutation (`warmUp`, `overrideForTesting`, `reset`). Phase 2 will add accessors, mutators, relations, scopes, custom builder, and custom collection. Phase 3 will add `knownProperties()` for unknown-key detection. See `.alies/docs/model-metadata-registry.md` for the full design.

### Tests

priority:high
priority:low
priority:medium

- **Type tests** (`tests/Type/tests/*.phpt`): PHPT format files tested via `phpyh/psalm-tester`. Each file contains PHP code and expected Psalm output. Use `@psalm-check-type-exact` annotations to assert inferred types. See `tests/Type/README.md` for deatils
- **Unit tests** (`tests/Unit/`): Standard PHPUnit tests for internal logic (auth config parsing, schema aggregation, etc.). You can skip them if setup is too complex: Type > Unit
- **Application tests** (`tests/Application/`): Integration tests against a minimal Laravel app with models, run via `laravel-test.sh`.  See `tests/Unit/README.md` for deatils
- **Application models** (`tests/Application/app/Models/`): Shared archetype models reused across tests. Models represent PK/trait archetypes, not individual test cases. Reuse existing models before creating new ones:
  - `User` — standard int PK (Authenticatable)
  - `UuidModel` — HasUuids trait (string PK)
  - `UlidModel` — HasUlids trait (string PK)
  - `CustomPkUuidModel` — HasUuids with custom `$primaryKey`

## Code Style

- PER Coding Style 3.0. Do not spend tokens on it: run `composer cs:fix`
- Unit test methods are exempt from the camelCase naming requirement
- Never use @psalm-suppress — try to fix the issue at the source
- Never add new entries to `psalm-baseline.xml`. The baseline is frozen — treat every new Psalm finding as something to fix or rework, not suppress. If the annotation Psalm demands is semantically imperfect but accepted today, prefer the annotation (with a comment explaining the gap) over a baseline entry
- Explain decisions and ideas in comments to have a better DX for new contributors
- If Rector removes `@var` annotations that are needed for better type coverage, use `@psalm-var` instead (Rector ignores `@psalm-` prefixed annotations)

## Taint Analysis

Taint annotations live in `stubs/common/` alongside type stubs. See `docs/contributing/taint-analysis.md` for the full authoring guide.

### Testing Taint Analysis

The project's own `psalm.xml` is for self-analysis of the plugin source code — it does **not** load the plugin itself (the plugin can't analyze itself).
To test taint analysis stubs, you must create a separate test project that loads the plugin:

```bash
# Create a minimal test project
mkdir -p /tmp/taint-test/app
cat > /tmp/taint-test/psalm.xml << 'XMLEOF'
<?xml version="1.0"?>
<psalm errorLevel="1" findUnusedCode="false"
    xmlns="https://getpsalm.org/schema/config">
    <projectFiles><directory name="app" /></projectFiles>
    <plugins><pluginClass class="Psalm\LaravelPlugin\Plugin"/></plugins>
</psalm>
XMLEOF

# Write test PHP in /tmp/taint-test/app/Test.php, then:
cd /tmp/taint-test && /path/to/vendor/bin/psalm --taint-analysis --no-cache
```

**Known limitation:** Facade static calls (`DB::unprepared(...)`) may not propagate taint because the `__callStatic` magic method loses taint context.
Calling the underlying class directly (`DB::connection()->unprepared(...)`) works correctly.
The generated alias stubs (simple `class X extends Y {}`) don't carry taint annotations.

## Docs

Keep documentation for custom issues from `docs/issues` precise and focused on issues.
