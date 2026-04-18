---
title: Upgrading to v4
nav_order: 5
---

# Upgrading from v3 to v4

## Requirements

| Dependency | v3           | v4         |
|------------|--------------|------------|
| PHP        | ^8.2         | **^8.2**   |
| Laravel    | 11, 12       | **12, 13** |
| Psalm      | 6, 7 (beta)  | **7 only** |

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

Psalm 7 introduces new issue types that may surface in your codebase:

- `MissingPureAnnotation` -- a method has no side effects but lacks `@psalm-pure`.
- `MissingAbstractPureAnnotation` -- an abstract method should be declared `@psalm-pure` so all implementations are guaranteed pure.
- `MissingInterfaceImmutableAnnotation` -- an interface should be `@psalm-immutable` so all implementations are guaranteed immutable.

Additionally, `MissingImmutableAnnotation` (introduced in Psalm v3) fires when a class has no mutable state but lacks `@psalm-immutable`.

**Why these annotations matter beyond documentation:**

- `@psalm-pure` enables *taint specialization* — Psalm tracks whether a pure function's return value is tainted based on whether its arguments are tainted. Without it, taint can be lost or incorrectly propagated through the function.
- `@psalm-immutable` enables *per-instance property taint tracking* — without it, a tainted property on one instance can pollute type inference across all instances of the class.

If you use taint analysis (security scanning), fixing these is recommended. Otherwise, suppress them during the upgrade and address later:

```xml
<issueHandlers>
    <MissingAbstractPureAnnotation errorLevel="suppress" />
    <MissingImmutableAnnotation errorLevel="suppress" />
    <MissingInterfaceImmutableAnnotation errorLevel="suppress" />
    <MissingPureAnnotation errorLevel="suppress" />
</issueHandlers>
```

### Eloquent relation generics now require a declaring model parameter

All Eloquent relation stubs gained additional template parameters. If your codebase has `@psalm-return` (or `@return`) annotations with relation generics, they must be updated:

| Relation type    | v3 signature               | v4 signature                                                                        |
|------------------|----------------------------|-------------------------------------------------------------------------------------|
| `BelongsTo`      | `BelongsTo<TRelated>`      | `BelongsTo<TRelatedModel, TDeclaringModel>`                                         |
| `HasOne`         | `HasOne<TRelated>`         | `HasOne<TRelatedModel, TDeclaringModel>`                                            |
| `HasMany`        | `HasMany<TRelated>`        | `HasMany<TRelatedModel, TDeclaringModel>`                                           |
| `BelongsToMany`  | `BelongsToMany<TRelated>` or `BelongsToMany<TRelated, TDeclaringModel>` | `BelongsToMany<TRelatedModel, TDeclaringModel, TPivotModel, TAccessor>` (upd. v4.7) |
| `MorphOne`       | `MorphOne<TRelated>`       | `MorphOne<TRelatedModel, TDeclaringModel>`                                          |
| `MorphMany`      | `MorphMany<TRelated>`      | `MorphMany<TRelatedModel, TDeclaringModel>`                                         |
| `MorphTo`        | `MorphTo<TRelated>`        | `MorphTo<TRelatedModel, TDeclaringModel>`                                           |
| `MorphToMany`    | `MorphToMany<TRelated>` or `MorphToMany<TRelated, TDeclaringModel>` | `MorphToMany<TRelatedModel, TDeclaringModel, TPivotModel, TAccessor>` (upd. v4.7)   |
| `HasOneThrough`  | `HasOneThrough<TRelated>`  | `HasOneThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>`                 |
| `HasManyThrough` | `HasManyThrough<TRelated>` | `HasManyThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>`                |

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
# 1. Update PHP to 8.2+ and Laravel to 12+ if needed

# 2. Upgrade Psalm to v7
composer require --dev vimeo/psalm:^7.0.0-beta17

# 3. Upgrade the plugin
composer require --dev psalm/plugin-laravel:^4.0

# 4. Update relation generic annotations (add declaring model parameter)
#
#    Option A — Psalter plugin (handles @return and @psalm-return, AST-aware):
./vendor/bin/psalter --plugin=vendor/psalm/plugin-laravel/tools/psalter/UpgradeRelationAnnotations.php --dry-run
./vendor/bin/psalter --plugin=vendor/psalm/plugin-laravel/tools/psalter/UpgradeRelationAnnotations.php
#
#    Option B — AI prompt (paste into Claude Code / Cursor / Copilot):
#
#      Update all Eloquent relation @return / @psalm-return annotations in app/ to
#      match the psalm-plugin-laravel v4 signatures. Use grep to find affected files,
#      then edit each one with sed or direct file edits.
#
#      Rules (apply to both @return and @psalm-return lines):
#        BelongsTo<T>     → BelongsTo<T, self>
#        HasOne<T>        → HasOne<T, self>
#        HasMany<T>       → HasMany<T, self>
#        MorphOne<T>      → MorphOne<T, self>
#        MorphMany<T>     → MorphMany<T, self>
#        MorphTo<T>       → MorphTo<T, self>
#        BelongsToMany<T>        → BelongsToMany<T, self, \Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
#        BelongsToMany<T, self>   → BelongsToMany<T, self, \Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
#        BelongsToMany<T, $this>  → BelongsToMany<T, $this, \Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
#        MorphToMany<T>           → MorphToMany<T, self, \Illuminate\Database\Eloquent\Relations\MorphPivot, 'pivot'>
#        MorphToMany<T, self>     → MorphToMany<T, self, \Illuminate\Database\Eloquent\Relations\MorphPivot, 'pivot'>
#        MorphToMany<T, $this>    → MorphToMany<T, $this, \Illuminate\Database\Eloquent\Relations\MorphPivot, 'pivot'>
#        HasManyThrough<T> → HasManyThrough<T, IntermediateModel, self>  (read the method body to find IntermediateModel)
#        HasOneThrough<T>  → HasOneThrough<T, IntermediateModel, self>   (read the method body to find IntermediateModel)
#
#      Do not touch annotations that already have the correct number of type params.
#      Do not touch @param or @var annotations.

# 5. Run Psalm and update your baseline
./vendor/bin/psalm --set-baseline=psalm-baseline.xml

# 6. Review new issues
#    - InvalidConsoleArgumentName / InvalidConsoleOptionName are real bugs — fix them
#    - NoEnvOutsideConfig — move env() calls into config files
#    - TaintedSql on Builder::where() — review for actual SQL injection risk
```
