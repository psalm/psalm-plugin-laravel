---
title: ImplicitQueryBuilderCall
parent: Custom Issues
nav_order: 10
---

# ImplicitQueryBuilderCall

Opt-in. Emitted when a query builder or local scope method is invoked directly on an Eloquent model, statically (`User::where(...)`, `User::active()`) or on an instance (`$user->where(...)`), instead of through an explicit `query()` entry point.

This issue is **disabled by default**. Enable it with `<reportImplicitQueryBuilderCalls value="true" />` (see [Configuration](../config.md#reportimplicitquerybuildercalls)).

## Why this is a problem

Calls like `User::where(...)`, `User::find(...)`, or `User::active()` do not exist on the model. Laravel forwards them through the `__callStatic` / `__call` magic methods to a freshly created query builder. Teams that prefer to minimise this magic enable this rule to require the explicit `User::query()->...` form, which keeps the entry point concrete and the call chain easy to follow for both readers and tooling.

## What is flagged

- **Query builder methods**, both Eloquent `Builder` methods (`where`, `find`, `create`, `first`, `get`) and `Query\Builder` methods (`orderBy`, `whereIn`), plus resolvable dynamic `where{Column}()` clauses.
- **Custom builder methods**, declared on a model's dedicated Eloquent builder (registered via `newEloquentBuilder()` or `#[UseEloquentBuilder]`).
- **Local scopes**, both legacy `scopeActive()` invoked by its forwarded bare name `active()`, and modern `#[Scope]` attribute methods.

A real method declared on the framework `Model` base (`save()`, `all()`, `with()`, `query()`, `destroy()`, ...) and any real user-defined method are left alone, since they are genuine methods rather than magic forwarding. A genuinely undefined method is reported as `UndefinedMagicMethod` rather than mislabelled by this rule.

## Examples

```php
// Bad — forwarded through magic to a new query builder
$users = User::where('active', 1)->get();
$user  = User::find($id);
$recent = User::active()->latest()->get();
```

```php
// Good — explicit query() entry point
$users = User::query()->where('active', 1)->get();
$user  = User::query()->find($id);
$recent = User::query()->active()->latest()->get();
```

## How to fix

Route the call through `Model::query()` (static) or `$model->newQuery()` (instance) before the builder or scope method.

## Known limitation

A `public` `#[Scope]` method is accessible from every call site, so its forwarded form cannot be distinguished from a legitimate direct call by visibility alone, and is therefore not flagged. Laravel's convention wants scopes `protected` (which this rule does flag when forwarded); a `public` `#[Scope]` is independently reported as [PublicModelScope](PublicModelScope.md).
