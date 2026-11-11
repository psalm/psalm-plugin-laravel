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
- **Left alone, still reported.** `Post::make(...)` used as a first-class callable, and a model whose creation pipeline is customized: a constructor that is not Eloquent's own, an overridden `newInstance()`, or an Eloquent builder that overrides `make()` or `newModelInstance()` (`new` would skip that code, which `make()` runs).
- **Never touched.** `self::make()`, `static::make()` and `parent::make()` are neither reported nor rewritten: `self::make()` in an inherited factory creates the called child class (late static binding) and `static::make()` in an instance method keeps the instance's connection, and `new self` / `new static` would change both.
- **Suppressed calls** (`@psalm-suppress ModelMakeDiscouraged`, or `<PluginIssue name="ModelMakeDiscouraged" errorLevel="suppress" />` in `psalm.xml`) are not rewritten.
- **Calls in Blade templates** are reported but never rewritten.

One behavior differs: `make()` pins the connection name that is the default at that moment onto the model, while `new Post()` leaves the connection unset, so it resolves when the model is used. The two differ only when the default connection changes at runtime between creating the model and using it.
