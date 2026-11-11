---
title: ModelMakeDiscouraged
parent: Custom Issues
nav_order: 6
---

# ModelMakeDiscouraged

Emitted when `Model::make()` is used instead of `new Model()`.

## Why this is a problem

`Model::make()` is forwarded through magic methods (`__callStatic` -> `__call` -> `forwardCallTo`) to `Builder::make()`, which just creates a new instance via `newModelInstance()`. Using `new Model($attributes)` is clearer and avoids the indirection.

## Examples

```php
// Bad — unnecessary indirection through magic methods and Builder
$post = Post::make(['title' => 'Hello']);
```

```php
// Good — direct construction, easy to follow
$post = new Post(['title' => 'Hello']);
```

## How to fix

Replace `Model::make($attributes)` with `new Model($attributes)`. Only calls that name the model class are reported; `static::make()` / `self::make()` / `parent::make()` inside a model are not.

## Fixing with Psalter

Psalter rewrites the calls for you:

```bash
# Preview the diff, then apply
./vendor/bin/psalter --issues=ModelMakeDiscouraged --dry-run
./vendor/bin/psalter --issues=ModelMakeDiscouraged
```

`Post::make($attributes)` becomes `new Post($attributes)`: the class name (alias, import or fully qualified) and the arguments are kept as written.

- **Parentheses on older PHP targets.** Below PHP 8.4, `new Post()->save()` is a syntax error, so a call that is followed by `->`, `?->`, `::`, `[` or `(` becomes `(new Post())->save()`. Psalter reads the target from `--php-version`, or from `composer.json` when it is not passed.
- **Left alone, still reported.** Psalter rewrites only when it can show that `new Post()` runs what `make()` runs. `make()` goes through `__callStatic` -> `__call` -> `newQuery()` -> the Eloquent builder's `make()` -> `newInstance()`, so these are reported and not rewritten:
  - `Post::make(...)` used as a first-class callable.
  - A named `Post::make()` inside a non-static method or closure of a class that is the model, one of its subclasses or one of its ancestors. PHP keeps the object context there, so the call runs `$this->__call('make')` on the current instance, which keeps that instance's class, table and connection, while `new Post()` builds a fresh base model. The same call in a static method, a static closure, or an unrelated class is rewritten.
  - A model whose creation pipeline is customized: a constructor that is not Eloquent's own, an overridden `newInstance()`, or an Eloquent builder that overrides `make()` or `newModelInstance()`.
  - A model whose class chain (ancestors and traits included) declares `__call`, `__callStatic`, `forwardCallTo()`, `forwardDecoratedCallTo()`, `newQuery()`, `newModelQuery()`, `newEloquentBuilder()` or `newQueryWithoutScopes()` itself. This includes a `newEloquentBuilder()` override with no native return type: the plugin cannot tell which builder it returns, so it cannot prove the builder's `make()` is Eloquent's own.
  - A model that customizes booting or initializing: it declares `boot()`, `booting()`, `booted()` or a trait's `boot<TraitName>()` method (so every model using `SoftDeletes` is left alone), has a method marked `#[Boot]` or `#[Initialize]` under any name, or carries `#[ScopedBy]` (or a subclass of it) on itself, an ancestor, or a trait any of them uses. A global scope registered there can add pending attributes through its `extend()`, which `make()` applies and `new` does not.
  - Any call inside a non-static closure or arrow function, whatever its `$this` is today: `Closure::call()`, `bind()` and `bindTo()` can rebind it to any model, which turns `Post::make()` into that model's `__call('make')`. A `static` closure cannot be rebound, so its call is rewritten.
  - Any call inside a trait: the trait's one source is shared by every class that uses it, and an edit that is right for one consumer can be wrong for another.
- **Never touched.** `self::make()`, `static::make()` and `parent::make()` are neither reported nor rewritten: `self::make()` in an inherited factory creates the called child class (late static binding) and `static::make()` in an instance method keeps the instance's connection, and `new self` / `new static` would change both.
- **Suppressed calls** (`@psalm-suppress ModelMakeDiscouraged`, or `<PluginIssue name="ModelMakeDiscouraged" errorLevel="suppress" />` in `psalm.xml`) are not rewritten.
- **Calls in Blade templates** are reported but never rewritten.

Where the rewrite is made, one behavior still differs, and only in one situation: `Post::make()` pins the default connection name onto the model at the moment it is created, while `new Post()` leaves the connection unset and resolves it on first use. So the rewrite changes behavior only if the application switches the default connection between creating the model and using it (for example `DB::setDefaultConnection()` called in between): `make()` would keep using the old default, `new Post()` the new one. If your code does that, review the rewrite or suppress the call.

## Known gaps

Psalter cannot see these at analysis time, so a rewrite can still change behavior in them. Review the diff, or suppress the call:

- A relation registered at runtime with `Model::resolveRelationUsing('make', ...)`, under the called method's name: `Post::make()` then returns that relation, `new Post()` does not.
- A trait initializer (`initialize<TraitName>()`) with side effects that count instances, such as `++static::$counter`: `make()` creates more than one object (it builds a model for the builder and another for the result), `new` creates one.
