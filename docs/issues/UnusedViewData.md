---
title: UnusedViewData
parent: Custom Issues
nav_order: 12
---

# UnusedViewData

Emitted when a `view()` call site passes a data key that the rendered template neither reads nor declares, and neither does any template it hands its whole scope to (`@include`, `@extends`).

## Why this is a problem

A key nothing reads is work done for nothing, and it is usually a symptom rather than the bug: a variable renamed in the template and not at the call site, a key that survived a refactor, a `compact()` list that grew past what the view uses. Each one also costs whatever built the value.

## Examples

```blade
{{-- resources/views/profile.blade.php --}}
<p>{{ $name }}</p>
```

```php
// Bad — nothing in profile.blade.php reads $subtitle
view('profile', ['name' => $user->name, 'subtitle' => $this->expensiveSubtitle()]);

// Good
view('profile', ['name' => $user->name]);
```

A key an included partial reads — or declares, since `{{-- @var --}}` and `@props` state that template's interface just as they do at the top of the chain — counts as consumed, because the include inherits the whole scope:

```blade
{{-- resources/views/page.blade.php --}}
<h1>{{ $title }}</h1>
@include('partials.footer')

{{-- resources/views/partials/footer.blade.php --}}
<small>{{ $author }}</small>
```

```php
// Fine — 'author' is read two templates down
view('page', ['title' => 'Home', 'author' => 'Ada']);
```

## How to fix

1. Drop the key from the call site.
2. If the template should be using it, use it (or declare it with `{{-- @var --}}` / `@props`, which also silences this check).
3. If it is consumed somewhere this release cannot follow, see Limitations and suppress it.

## Configuration

This check is disabled by default. Enable it alongside Blade analysis in your `psalm.xml`:

```xml
<plugins>
    <pluginClass class="Psalm\LaravelPlugin\Plugin">
        <blade enabled="true" reportUnusedViewData="true" />
    </pluginClass>
</plugins>
```

## Suppressing it

The issue is reported at the call site, so `@psalm-suppress UnusedViewData` on the statement or the enclosing function works, as does an `<issueHandlers>` entry. Declaring the variable in the template (`{{-- @var mixed $key --}}`, or a `@props` entry) is the better fix when the key is part of the template's contract but only reached by code this release cannot follow.

## Limitations

Every gate below silences the check for that call site rather than guessing:

- **The template's read set must be provable.** A `$$name` write, `extract()`, or a `compact()` with a non-literal argument anywhere in the compiled output makes the set a lower bound. `@props` and `@aware` both compile to `$$name` writes, so **component templates are never checked** in this release.
- **The include chain must be literal.** A dynamic `@include($name)` — at any depth — declines for the whole chain. `@includeIsolated` and `@each` render with a fresh scope, so a key only their template reads is still reported, which is correct.
- **`@props` must be readable.** A `@props($array)` the plugin cannot read as a literal list makes the declared set a lower bound, so nothing is reported for that template.
- **Ambient names are never reported.** `$errors`, `$slot`, `$attributes`, `$component`, `$loop`, and `$__env` are Blade's own, so passing one is never wrong about the template.
- **Loop aliases count as read.** `@foreach ($items as $item)` marks both `items` and `item` as read: the read set is deliberately template-wide rather than scope-aware, and over-counting a name as used is the safe direction.
- **A write-only variable does not count as a read.** `@php $key = 1; @endphp` with `key` passed in is reported, because the passed value is provably discarded.
- The call shapes recognized are the same ones [`validateViewData`](../config.md#validateviewdata) recognizes, and a key is only checked when it is a literal string in the data array or a `with()` call.
