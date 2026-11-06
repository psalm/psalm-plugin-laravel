---
title: UnusedViewDeclaration
parent: Custom Issues
nav_order: 12
---

# UnusedViewDeclaration

Emitted, once and at the template, when a `@var` declaration names a variable that nothing reads: not the template itself, and not any template it hands its whole scope to (`@include`, `@extends`). Such a declaration is usually an IDE hint that outlived the code that used it.

It replaces [MissingViewData](MissingViewData.md) for that variable. A call site is never told to pass a value the template does not read, because the right fix is to delete the declaration, not to supply the variable.

## Example

```blade
{{-- resources/views/settings/boosters/create.blade.php --}}
{{-- $boost is declared, but nothing below reads it: UnusedViewDeclaration --}}
<?php
/**
 * @var \App\Models\Campaign $campaign
 * @var \App\Models\CampaignBoost $boost
 */
?>
<h1>{{ $campaign->name }}</h1>
```

```php
// $boost is not passed, and no MissingViewData is reported for it
view('settings.boosters.create', ['campaign' => $campaign]);
```

A declaration read only by an included partial is live, and keeps its `MissingViewData` at call sites that omit it:

```blade
{{-- host.blade.php --}}
<?php /** @var string $note */ ?>
@include('partial')

{{-- partial.blade.php --}}
<p>{{ $note }}</p>
```

## How to fix

1. Delete the declaration.
2. If the template should be using the variable, use it.
3. Otherwise suppress it where it is written: `@psalm-suppress UnusedViewDeclaration` in the declaring docblock, or a Blade `{{-- @psalm-suppress UnusedViewDeclaration --}}` comment above the block that holds it.

## Limitations

The set of names a template reads is the one [UnusedViewData](UnusedViewData.md) uses, and every gate that makes it only a lower bound reports nothing and leaves `MissingViewData` as it was:

- **The read set must be provable.** A `$$name` write, `extract()`, a `compact()` with a non-literal argument, or a PHP `include` / `require` / `eval` makes the set a lower bound. `@props` and `@aware` both compile to `$$name` writes, so a template that uses either is never checked.
- **The include chain must be literal.** A dynamic `@include($name)` at any depth declines for the whole chain, and so does a custom directive that hands its own renderer the whole scope the way Blade's `@include` does (see [UnusedViewData](UnusedViewData.md#limitations)).
- **`@props` must be readable.** A `@props($array)` the plugin cannot read as a literal list makes the declared set a lower bound.
- **Only `@var` declarations are reported**, not `@props` entries.
- **A write-only variable is not a declaration.** A name the template binds itself (a `@foreach` alias, an assignment target) is a type hint on a local, never a contract.
- **Aliased `compact` and `extract` are not recognised.** `use function compact as c; c('x')` reads `$x` without keeping its declaration alive, the same gap [UnusedViewData](UnusedViewData.md) has.
- **`get_defined_vars()` is not tracked.** Blade compiles it into every `@include`, so a hand-written `get_defined_vars()` that reads a declared variable indirectly does not keep the declaration alive. Suppress the issue there.
- **Included partials are judged on their own.** A partial that declares a variable only its host reads is reported in the partial, since the partial's chain never reads it.

For promotion and suppression, see the shared [reporting level](../blade.md#reporting-level).
