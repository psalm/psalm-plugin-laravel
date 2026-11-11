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

## Fixing with Psalter

Psalter rewrites the calls for you (the rule must be switched on in `psalm.xml`):

```bash
# Preview the diff, then apply
./vendor/bin/psalter --issues=ImplicitQueryBuilderCall --dry-run
./vendor/bin/psalter --issues=ImplicitQueryBuilderCall
```

One insertion per flagged call, right before the method name; the rest of the source is kept as written:

- `User::where('active', 1)` becomes `User::query()->where('active', 1)`.
- `$user->where('active', 1)` becomes `$user->newQuery()->where('active', 1)`.
- In `User::where(...)->orderBy(...)` only the first call is forwarded, so only it gets the insertion.

The path is the one Laravel already takes: `__callStatic` -> `__call` -> `newQuery()`, and `query()` is `(new static)->newQuery()`. The instance form uses `newQuery()` rather than `query()` so the query keeps the instance's connection and table. That holds only while nothing customizes the route, so Psalter rewrites only the calls it can show take that route, and reports the rest (see "Left alone, still reported" below).

Where the rewrite is made, one difference remains, and it is the same one you get by applying the advice by hand: the rewrite creates the query builder before the arguments are evaluated, while the magic call creates it after. The two differ only when an argument expression itself changes the default connection, or other global state the builder reads, as in `User::where('id', switchDefaultConnection())`.

- **Left alone, still reported.**
  - A first-class callable (`User::where(...)`).
  - Calls through `self::`, `static::` or `parent::`: in an instance method `static::where(...)` runs `$this->__call()` and keeps the instance's connection and table, while `static::query()` builds a fresh model and drops them.
  - A named static call such as `User::where(...)` inside a non-static method or closure of a class that is the model, one of its subclasses or one of its ancestors. PHP keeps the object context there, so the call runs `$this->__call()` with the instance's table and connection, while `User::query()` builds a fresh model. The same call in a static method, a static closure, or an unrelated class is rewritten.
  - A model whose class chain (ancestors and traits included) declares `__call`, `__callStatic`, `forwardCallTo()`, `forwardDecoratedCallTo()`, `query` or `newQuery` itself rather than inheriting Eloquent's: the inserted `query()` / `newQuery()` would bypass that code.
  - A named static call such as `User::where(...)` inside a non-static closure or arrow function, whatever its `$this` is today: `Closure::call()`, `bind()` and `bindTo()` can rebind it to any model, which turns it into that model's `__call('where')`. A `static` closure cannot be rebound, so its call is rewritten. An instance call on a captured model (`$user->where(...)` inside `function () use ($user)`) reads no `$this` and is rewritten.
  - Any call inside a trait: the trait's one source is shared by every class that uses it, and an edit that is right for one consumer can be wrong for another.
- **Suppressed calls** (`@psalm-suppress ImplicitQueryBuilderCall`, or `<PluginIssue name="ImplicitQueryBuilderCall" errorLevel="suppress" />` in `psalm.xml`) are not rewritten.
- **Calls in Blade templates** are reported but never rewritten.

## Known gaps

Psalter cannot see these at analysis time, so a rewrite can still change behavior in them. Review the diff, or suppress the call:

- A relation registered at runtime with `Model::resolveRelationUsing('where', ...)`, under the called method's name: `User::where(...)` then returns that relation, `User::query()->where(...)` returns a builder.
- A trait initializer (`initialize<TraitName>()`) with side effects that count instances, such as `++static::$counter`: the forwarded call and its rewrite may create a different number of model objects, so such a counter can differ.

## Known limitation

A `public` `#[Scope]` method is accessible from every call site, so its forwarded form cannot be distinguished from a legitimate direct call by visibility alone, and is therefore not flagged. Laravel's convention wants scopes `protected` (which this rule does flag when forwarded); a `public` `#[Scope]` is independently reported as [PublicModelScope](PublicModelScope.md).
