---
title: MissingViewVariable
parent: Custom Issues
nav_order: 12
---

# MissingViewVariable

Emitted when a Blade template declares a variable that the call site rendering it never passes.

A template declares its variables two ways, both read by [Blade template analysis](../blade.md):

```blade
{{-- @var \App\Models\User $user --}}
@props(['title' => 'Untitled', 'subtitle'])
```

## Why this is a problem

The variable is undefined at render time. Blade does not fail loudly for it: `{{ $subtitle }}` renders
an empty string under the default error handler, so the page ships with a hole in it instead of an
exception anyone would notice.

## Examples

```blade
{{-- resources/views/profile.blade.php --}}
{{-- @var string $name --}}
{{-- @var int $age --}}
<p>{{ $name }} ({{ $age }})</p>
```

```php
// Bad: the template declares two variables, the call passes neither
view('profile', []); // MissingViewVariable, twice

// Good
view('profile', ['name' => 'Ada', 'age' => 36]);

// Good: a with() chain counts, the whole chain is read at once
view('profile', ['name' => 'Ada'])->with('age', 36);
```

## How to fix

1. Pass the declared variable at the call site.
2. Give it a default in the template's `@props([...])` if it is genuinely optional (`@props(['subtitle' => ''])`).
3. Drop the `{{-- @var --}}` declaration if the template no longer reads that variable.

## Configuration

This check is disabled by default, and needs Blade analysis enabled. Enable it in your `psalm.xml`:

```xml
<plugins>
    <pluginClass class="Psalm\LaravelPlugin\Plugin">
        <blade enabled="true" validateViewData="true" />
    </pluginClass>
</plugins>
```

## Limitations

The check declines rather than guess. It is silent when:

- The view name is not a string literal, or is namespaced (`pkg::view`).
- The template declares nothing, or its `@props([...])` array is not fully literal (the declared set is then only a lower bound).
- The `@props` entry carries a literal default, which Blade fills in itself.
- The supplied key set cannot be proven closed: a spread in the data array, a dynamic `with()` key, a `$mergeData` argument, or a data argument whose type is not a single sealed keyed array.
- The rendering expression is not the whole of an expression or `return` statement, or its chain carries a method this check does not model. Recognized chains are `view()`, `Factory::make()`, `response()->view()`, `Mailable::view()` / `markdown()`, `MailMessage`'s equivalents, and any number of `with()` / `withErrors()` calls on top of them.
- Psalm is running `--taint-analysis`, which on Psalm 6 reports taint issues only.

### Known false positive: variables bound outside the call site

The check reads the data one call site passes. It does not know about the ways Laravel binds a
variable into a view somewhere else entirely:

- `View::composer('profile', ...)` and `View::creator(...)`, which bind at render time from a service provider.
- `View::share('siteName', ...)`, which binds into every view in the application.
- `@inject('metrics', 'App\Services\Metrics')` inside the template, which resolves from the container rather than from the data array.

A variable that arrives one of those ways is declared by the template but never passed by the call
site, so it is reported. Either drop its `{{-- @var --}}` declaration, or silence the rule for the
call sites it affects:

```php
/** @psalm-suppress MissingViewVariable */
return view('profile', []);
```

Application wide, suppress it through `issueHandlers` in your `psalm.xml`:

```xml
<issueHandlers>
    <MissingViewVariable errorLevel="suppress" />
</issueHandlers>
```

Teaching the check to read `composer()`, `creator()`, `share()` and `@inject` bindings is tracked
as a follow-up.
