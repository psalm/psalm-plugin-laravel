---
title: ModelMakeDiscouraged
parent: Custom Issues
nav_order: 5
---

# ModelMakeDiscouraged

Emitted when `Model::make()` is used instead of `new Model()`.

## Why this is a problem

`Model::make()` is forwarded through `__callStatic` to `Builder::make()`, which just creates a new instance via `newModelInstance()`. Using `new Model($attributes)` is clearer, avoids the indirection, and is easier for both developers and static analysis tools to follow.

## Examples

```php
// Bad — unnecessary indirection through __callStatic and Builder
$post = Post::make(['title' => 'Hello']);
```

```php
// Good — direct construction, easy to follow
$post = new Post(['title' => 'Hello']);
```

## How to fix

Replace `Model::make($attributes)` with `new Model($attributes)`.
