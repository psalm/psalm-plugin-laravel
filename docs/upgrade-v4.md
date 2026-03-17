# Upgrading from v3 to v4

## Requirements

| Dependency | v3           | v4           |
|------------|--------------|--------------|
| PHP        | ^8.2         | **^8.3**     |
| Laravel    | 11, 12       | **12, 13**   |
| Psalm      | 6, 7 (beta)  | **7 only**   |

Laravel 11 and Psalm 6 are no longer supported. If you need them, stay on v3.

## Breaking changes

### Psalm 7 is required

v4 requires `vimeo/psalm ^7.0.0-beta17` or later. If your project still uses Psalm 6, upgrade Psalm first:

```bash
composer require --dev vimeo/psalm:^7.0.0-beta17
```

Psalm 7 is still in beta. You may need to add this to your project's `composer.json`:

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

### Taint analysis runs automatically

In Psalm 6 you had to pass `--taint-analysis` as a separate flag.
Psalm 7 combines type analysis and taint analysis into a single run by default.
No flags needed — just run `./vendor/bin/psalm`.

### `barryvdh/laravel-ide-helper` is no longer a dependency

v3 used ide-helper internally to generate facade and model stubs. v4 replaces this with its own lightweight alias and property resolution. This means:

- **Fewer dependencies** — installing the plugin no longer pulls in ide-helper and its transitive dependencies
- **No action needed** — if you use ide-helper in your own project, it continues to work independently
- **If you relied on ide-helper being pulled in transitively**, add it as a direct dependency: `composer require --dev barryvdh/laravel-ide-helper`

## New features in v4

### Console command argument/option validation

The plugin now reads your Artisan command's `$signature` and reports errors when `argument()` or `option()` references an undefined name:

```php
// Reports InvalidConsoleArgumentName
$this->argument('nonexistent');

// Reports InvalidConsoleOptionName
$this->option('nonexistent');
```

To suppress these if needed:

```xml
<issueHandlers>
    <PluginIssue name="InvalidConsoleArgumentName" errorLevel="suppress" />
    <PluginIssue name="InvalidConsoleOptionName" errorLevel="suppress" />
</issueHandlers>
```

### `env()` outside config detection

Calling `env()` outside the `config/` directory is reported as `NoEnvOutsideConfig`, because `env()` returns `null` when the config is cached.

```xml
<issueHandlers>
    <PluginIssue name="NoEnvOutsideConfig" errorLevel="suppress" />
</issueHandlers>
```

### `#[Scope]` attribute support

Laravel 12+ scope detection via the `#[Scope]` attribute is now supported alongside the traditional `scope` method prefix.

### Improved model property inference

- AST-based cast parsing (reads `casts()` method without executing it)
- Write-type registration (`pseudo_property_set_types`) for model properties
- Support for `Attribute<TGet, TSet>` accessor templates
- `after()` closures, `Blueprint::rename()`, `addColumn()`, and more migration methods supported

### Taint annotations on Eloquent Builder

`where()`, `orWhere()`, and other query builder methods now have `@psalm-taint-sink sql` annotations, catching SQL injection via dynamic column names.

### Auto-discovery of migration directories

Migration directories registered via `loadMigrationsFrom()` are now auto-discovered, in addition to the default `database/migrations`.

## Upgrade steps

```bash
# 1. Update PHP to 8.3+ and Laravel to 12+ if needed

# 2. Upgrade Psalm to v7
composer require --dev vimeo/psalm:^7.0.0-beta17

# 3. Upgrade the plugin
composer require --dev psalm/plugin-laravel:^4.0

# 4. Run Psalm and update your baseline
./vendor/bin/psalm --set-baseline=psalm-baseline.xml

# 5. Review new issues
#    - InvalidConsoleArgumentName / InvalidConsoleOptionName are real bugs — fix them
#    - NoEnvOutsideConfig — move env() calls into config files
#    - TaintedSql on Builder::where() — review for actual SQL injection risk
```
