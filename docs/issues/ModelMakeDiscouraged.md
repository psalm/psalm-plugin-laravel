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

Replace `Model::make($attributes)` with `new Model($attributes)`.
