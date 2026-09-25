---
title: MassAssignmentFromRequest
parent: Custom Issues
nav_order: 12
---

# MassAssignmentFromRequest

Emitted when an Eloquent mass-assignment call (`create()` / `fill()` / `update()`, statically, on an instance, or through a `Builder`/relation forwarding form) is passed an argument whose provenance proves it is raw, unfiltered request data.

## Why this is a problem

`create()`, `fill()`, and `update()` write every key of the given array onto the model, subject only to `$fillable`/`$guarded`. Passing `$request->all()` (or an equivalent) hands that array to an attacker who controls the request body: any field they add to the form data is written, including ones the application never intended to expose, such as `is_admin` or `role`.

```php
// Bad: an attacker can add `is_admin=1` to the request body
$user->fill($request->all())->save();

Post::create($request->all());
```

## What is flagged

The first argument of `create()`, `createQuietly()`, `fill()`, `update()`, `updateQuietly()`, or `updateOrFail()`, called on a model instance, a model class (`self`/`static` included), or the `Builder`/`Relation` forwarding form (`$post->comments()->create(...)`, `Model::query()->update(...)`), when the argument's provenance is proven to be one of:

- `$request->all()` / `request()->all()`, where the receiver is `Illuminate\Http\Request` or a subclass (`FormRequest` included) — the receiver check is by TYPE, not by variable or property name, so an injected `$this->request` (or any other property directly typed `Request`) is covered the same as a local variable.
- `$request->query->all()` / `$request->request->all()` — Symfony's `InputBag` properties.
- `$request->json()->all()`.
- `$request->input()` / `$request->post()` / `$request->query()`, called with **no arguments** — each returns the whole request payload, the identical hole as `all()`. Any argument (a key, a default, a key list) narrows the result and is not flagged.

Each shape is recognized directly as the argument, or through exactly one local variable assignment earlier in the same function.

`forceFill()` and `forceCreate()` (and `forceCreateQuietly()`) are deliberately **not** flagged — bypassing the guard is an explicit choice the author already made.

When the receiving model provably declares neither `$fillable` nor `$guarded` (every column is writable this way), the message notes it.

## Example

```php
// Bad: an attacker-controlled field can be written to any column
Post::create($request->all());
$user->fill(request()->all())->save();
$post->update($request->all());
Post::create($request->input()); // bare input()/post()/query() are the same hole

// Good
Post::create($request->validated());
$user->fill($request->safe()->only(['name', 'email']))->save();
$post->update($request->only(['title', 'body']));
```

## How to fix

Use `$request->validated()` (on a `FormRequest`) or `$request->safe()->only([...])` / `$request->only([...])` to pass an explicit, bounded set of keys instead of the whole request payload.

## When it stays silent (false-positive guards)

Because this is a security rule about a specific data flow, it errs toward silence whenever the provenance is not exact:

- **Key-filtered forms.** `only()`, `except()`, `safe()->only([...])`, and any array built with `array_merge()` are not flagged — the key set is bounded, even if it might still be too broad. This also covers `all()`/`input()`/`post()`/`query()` called WITH an argument (`$request->input('name')`, `$request->all(['name'])`) — only the bare, zero-argument form returns the whole payload.
- **The recommended fix itself.** `validated()` and `safe()->all()` are not flagged; a future rule may separately flag the `validated()`/`safe()->all()` distinction, but this one does not.
- **`forceFill()` / `forceCreate()` / `forceCreateQuietly()`.** An explicit author opt-out, not a mistake to flag.
- **Unproven variables.** A variable is only followed through one local, unconditional, top-level assignment in the same function; anything else (a second write, a conditional branch, a loop, a captured closure, `extract()`/`compact()`) is left unresolved and not flagged.
- **Ambiguous or non-model receivers.** A receiver that does not resolve to exactly one known Eloquent model (a union of distinct models, or a model mixed with a `Builder`/`Relation`) is skipped, mirroring [UnknownModelAttribute](UnknownModelAttribute.md).
- **A non-`Request` receiver with the same `all()` shape** (for example `Collection::all()`) is not flagged — the rule is restricted to `Illuminate\Http\Request` and its subclasses.

## Known limitation

The rule only follows a call argument through one local variable hop. A value threaded through a helper method, an intermediate DTO, or more than one reassignment is not traced and stays silent.
