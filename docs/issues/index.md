---
title: Custom Issues
nav_order: 4
has_children: true
---

# Custom Issues

The plugin ships advanced Laravel-aware static analysis checks that extend Psalm's built-in diagnostics:

- [NoEnvOutsideConfig](NoEnvOutsideConfig.md) — `env()` called outside the application's config directory
- [InvalidConsoleArgumentName](InvalidConsoleArgumentName.md) — `argument()` references undefined console command argument
- [InvalidConsoleOptionName](InvalidConsoleOptionName.md) — `option()` references undefined console command option
- [MissingView](MissingView.md) — `view()` references a non-existent Blade template (opt-in)
- [MissingTranslation](MissingTranslation.md) — `__()` or `trans()` references an undefined translation key (opt-in)
- [ModelMakeDiscouraged](ModelMakeDiscouraged.md) — `Model::make()` used instead of `new Model()`
- [OctaneIncompatibleBinding](OctaneIncompatibleBinding.md) — `singleton()` closure resolves a request-scoped service such as Request, Session, or Auth (auto-enabled when `laravel/octane` is installed)
- [PublicModelScope](PublicModelScope.md) — `public` `#[Scope]` Eloquent query scope, whose static call is a runtime fatal (reported at error levels 1 to 4)
- [PublicModelAccessor](PublicModelAccessor.md) — `public` legacy `getXxxAttribute()` / `setXxxAttribute()` accessor or mutator, a pure convention nit (reported at error level 1)
- [ImplicitQueryBuilderCall](ImplicitQueryBuilderCall.md) — a query builder or local scope method called directly on a model instead of through an explicit `query()` entry point (opt-in)
- [UnknownModelAttribute](UnknownModelAttribute.md) — a typo'd key passed to a model's `create()` / `fill()` / `update()` that matches no known attribute
- [UnresolvableAppendedModelAttribute](UnresolvableAppendedModelAttribute.md) — an Eloquent `$appends` entry with no backing accessor or class cast, a runtime `BadMethodCallException` on `toArray()` / `toJson()`
- [UndefinedModelRelation](UndefinedModelRelation.md) — a relation name passed to `with()`, `load()`, `has()`, `whereHas()`, and similar methods that does not resolve to a relationship on the model

Each issue page explains what it detects, why it matters, and how to fix it.
