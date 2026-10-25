---
title: UnusedViewData
parent: Custom Issues
nav_order: 12
---

# UnusedViewData

Emitted when a `view()` call site passes a data key that the rendered template neither reads nor declares, and neither does any template it hands its whole scope to (`@include`, `@extends`). A key nothing reads is work done for nothing, and usually a symptom: a variable renamed in the template and not at the call site, a key that survived a refactor, a `compact()` list that grew past what the view uses.

## Example

```blade
{{-- resources/views/profile.blade.php --}}
<p>{{ $name }}</p>
```

```php
// nothing in profile.blade.php reads $subtitle
view('profile', ['name' => $user->name, 'subtitle' => $this->expensiveSubtitle()]);  // UnusedViewData

view('profile', ['name' => $user->name]);                                              // OK
```

A key read or declared by any template reached through an ordinary `@include` or `@extends` counts as used, because the include inherits the whole scope. A `@var` docblock or `@props` entry states a template's interface, so declaring a key counts as using it.

## How to fix

1. Drop the key from the call site.
2. If the template should be using it, use it, or declare it (`<?php /** @var T $name */ ?>` or `@props`). This is also the better fix when the key is part of the template's contract but only reached by code the plugin cannot follow.
3. Otherwise suppress it at the call site: `@psalm-suppress UnusedViewData` on the statement or the enclosing function.

## Limitations

Every gate below silences the check for that call site rather than guessing:

- **The template's read set must be provable.** A `$$name` write, `extract()`, a `compact()` with a non-literal argument, or a use of the engine's `$__data` array other than a literal key anywhere in the compiled output makes the set a lower bound. `$__data['name']` counts as a read of `name`. `@props` and `@aware` both compile to `$$name` writes, so **component templates are never checked**.
- **The include chain must be literal.** A dynamic `@include($name)` at any depth declines for the whole chain. `@includeIsolated` and `@each` render with a fresh scope, so a key only their template reads is still reported, which is correct.
- **`@props` must be readable.** A `@props($array)` the plugin cannot read as a literal list makes the declared set a lower bound, so nothing is reported for that template.
- **Ambient and framework-supplied names are never reported.** `$errors`, `$slot`, `$attributes`, `$component`, `$loop` and `$__env` are Blade's own; see also [Framework-supplied data](../blade.md#framework-supplied-data).
- **Loop aliases count as read.** `@foreach ($items as $item)` marks both `items` and `item` as read: the read set is deliberately template-wide rather than scope-aware, and over-counting a name as used is the safe direction.
- **A write-only variable does not count as a read.** `@php $key = 1; @endphp` with `key` passed in is reported, because the passed value is provably discarded.
- Only literal string keys in the data array or a `with()` call are checked, on the same [supported call sites](../blade.md#supported-call-sites) as the other view data issues. A view with a composer the plugin cannot read is skipped, since that composer may read a passed key the template never mentions ([composers](../blade.md#composers-and-creators)).

For promotion and suppression, see the shared [reporting level](../blade.md#reporting-level).
