---
title: Upgrading to v4
nav_order: 5
---

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

Psalm 7 introduces new issue types that may surface in your codebase. These catch real design problems – fixing them improves your code, but you can suppress them during the upgrade and address them later:

- `MissingPureAnnotation` -- a method has no side effects but lacks `@psalm-pure`. Adding it lets Psalm verify the method stays side-effect-free and enables callers to use it in pure contexts.
- `MissingAbstractPureAnnotation` -- an abstract method should be declared `@psalm-pure` so all implementations are guaranteed pure.
- `MissingImmutableAnnotation` -- a class has no mutable state but lacks `@psalm-immutable`. Marking it immutable prevents accidental mutation in future changes.
- `MissingInterfaceImmutableAnnotation` -- an interface should be `@psalm-immutable` so all implementations are guaranteed immutable.

```xml
<issueHandlers>
    <MissingPureAnnotation errorLevel="suppress" />
</issueHandlers>
```

### Eloquent relation generics now require a declaring model parameter

All Eloquent relation stubs gained additional template parameters. If your codebase has `@psalm-return` (or `@return`) annotations with relation generics, they must be updated:

| Relation type      | v3 signature                    | v4 signature                                          |
|--------------------|---------------------------------|-------------------------------------------------------|
| `BelongsTo`        | `BelongsTo<TRelated>`           | `BelongsTo<TRelated, TDeclaringModel>`                |
| `HasOne`           | `HasOne<TRelated>`              | `HasOne<TRelated, TDeclaringModel>`                   |
| `HasMany`          | `HasMany<TRelated>`             | `HasMany<TRelated, TDeclaringModel>`                  |
| `BelongsToMany`    | `BelongsToMany<TRelated>`       | `BelongsToMany<TRelated, TDeclaringModel>`            |
| `MorphOne`         | `MorphOne<TRelated>`            | `MorphOne<TRelated, TDeclaringModel>`                 |
| `MorphMany`        | `MorphMany<TRelated>`           | `MorphMany<TRelated, TDeclaringModel>`                |
| `MorphTo`          | `MorphTo<TRelated>`             | `MorphTo<TRelated, TDeclaringModel>`                  |
| `MorphToMany`      | `MorphToMany<TRelated>`         | `MorphToMany<TRelated, TDeclaringModel>`              |
| `HasOneThrough`    | `HasOneThrough<TRelated>`       | `HasOneThrough<TRelated, TIntermediate, TDeclaring>`  |
| `HasManyThrough`   | `HasManyThrough<TRelated>`      | `HasManyThrough<TRelated, TIntermediate, TDeclaring>` |

`Collection`, `EloquentCollection`, and `Builder` are **unchanged**.

### Taint analysis runs automatically

In Psalm 6 you had to pass `--taint-analysis` as a separate flag.
Psalm 7 combines type analysis and taint analysis into a single run by default.
No flags needed — just run `./vendor/bin/psalm`.

## New features in v4

### New issue types

**Plugin issues** (suppressible via `<PluginIssue>`):

- `InvalidConsoleArgumentName` -- `argument()` references an undefined name in the command's `$signature`
- `InvalidConsoleOptionName` -- `option()` references an undefined name in the command's `$signature`
- `NoEnvOutsideConfig` -- `env()` called outside the `config/` directory (`env()` returns `null` when the config is cached)

```xml
<issueHandlers>
    <PluginIssue name="InvalidConsoleArgumentName" errorLevel="suppress" />
    <PluginIssue name="InvalidConsoleOptionName" errorLevel="suppress" />
    <PluginIssue name="NoEnvOutsideConfig" errorLevel="suppress" />
</issueHandlers>
```

**Psalm built-in issues** (new detections via taint analysis):

- `TaintedSql` -- `where()`, `orWhere()`, and other query builder methods now have `@psalm-taint-sink sql` annotations, catching SQL injection via dynamic column names

### Other improvements

- `#[Scope]` attribute support -- Laravel 12+ scope detection alongside the traditional `scope` method prefix
- AST-based cast parsing (reads `casts()` method without executing it)
- Write-type registration (`pseudo_property_set_types`) for model properties
- Support for `Attribute<TGet, TSet>` accessor templates
- `after()` closures, `Blueprint::rename()`, `addColumn()`, and more migration methods supported
- Auto-discovery of migration directories registered via `loadMigrationsFrom()`

## Upgrade steps

```bash
# 1. Update PHP to 8.3+ and Laravel to 12+ if needed

# 2. Upgrade Psalm to v7
composer require --dev vimeo/psalm:^7.0.0-beta17

# 3. Upgrade the plugin
composer require --dev psalm/plugin-laravel:^4.0

# 4. Update relation generic annotations (add declaring model parameter)
#
#    Option A — Psalter plugin (handles @return and @psalm-return, AST-aware):
vendor/bin/psalter --plugin=vendor/psalm/plugin-laravel/tools/psalter/UpgradeRelationAnnotations.php --dry-run
vendor/bin/psalter --plugin=vendor/psalm/plugin-laravel/tools/psalter/UpgradeRelationAnnotations.php
#    HasManyThrough / HasOneThrough are flagged with a warning — fix those manually.
#
#    Option B — sed (handles @psalm-return only, run from project root):
find app -name '*.php' -exec grep -l '@psalm-return \(BelongsTo\|HasMany\|HasOne\|BelongsToMany\|MorphOne\|MorphMany\|MorphTo\|MorphToMany\)<' {} \; \
  | xargs sed -i 's/@psalm-return \(BelongsTo\|HasMany\|HasOne\|BelongsToMany\|MorphOne\|MorphMany\|MorphTo\|MorphToMany\)<\([^>]*\)>/@psalm-return \1<\2, self>/g'
#    HasManyThrough / HasOneThrough need manual edits (add intermediate model).

# 5. Run Psalm and update your baseline
./vendor/bin/psalm --set-baseline=psalm-baseline.xml

# 6. Review new issues
#    - InvalidConsoleArgumentName / InvalidConsoleOptionName are real bugs — fix them
#    - NoEnvOutsideConfig — move env() calls into config files
#    - TaintedSql on Builder::where() — review for actual SQL injection risk
```
