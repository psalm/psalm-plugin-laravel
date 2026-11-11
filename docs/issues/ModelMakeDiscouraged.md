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

Replace `Model::make($attributes)` with `new Model($attributes)`. `static::make()` and `self::make()` inside a model are reported too: use `new static()` / `new self()`.

## Fixing with Psalter

Psalter rewrites the calls for you (needs Psalm 7.3 or newer):

```bash
# Preview the diff, then apply
./vendor/bin/psalter --issues=ModelMakeDiscouraged --dry-run
./vendor/bin/psalter --issues=ModelMakeDiscouraged
```

`Post::make($attributes)` becomes `new Post($attributes)`: the class name (alias, import or fully qualified) and the arguments are kept as written, and `static::make()` / `self::make()` inside a model become `new static()` / `new self()`.

- **Parentheses on older PHP targets.** Below PHP 8.4, `new Post()->save()` is a syntax error, so a call that is followed by `->`, `?->`, `::`, `[` or `(` becomes `(new Post())->save()`. Psalter reads the target from `--php-version`, or from `composer.json` when it is not passed.
- **Left alone, still reported.** `Post::make(...)` used as a first-class callable, and a model whose constructor is not Eloquent's own (`new` would run different code than `make()`).
- **Suppressed calls** (`@psalm-suppress ModelMakeDiscouraged`, or `<PluginIssue name="ModelMakeDiscouraged" errorLevel="suppress" />` in `psalm.xml`) are not rewritten.
- **Calls in Blade templates** are reported but never rewritten.

One behavior differs: `make()` pins the connection name that is the default at that moment onto the model, while `new Post()` leaves the connection unset, so it resolves when the model is used. The two differ only when the default connection changes at runtime between creating the model and using it.
