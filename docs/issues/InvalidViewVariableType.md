---
title: InvalidViewVariableType
parent: Custom Issues
nav_order: 12
---

# InvalidViewVariableType

Emitted when the value a call site passes for a Blade template variable does not satisfy the type
that template declares for it in a `{{-- @var --}}` comment.

## Why this is a problem

The template was written against the declared type and reads the value accordingly (a property
fetch, a method call, a format string). A value of the wrong type either fatals at render time or
renders something nobody intended, and the failure surfaces in a template rather than at the call
site that caused it.

## Examples

```blade
{{-- resources/views/greeting.blade.php --}}
{{-- @var string $name --}}
<p>{{ $name }}</p>
```

```php
// Bad
view('greeting', ['name' => 123]);              // InvalidViewVariableType
view('greeting')->with('name', 123);            // InvalidViewVariableType
response()->view('greeting', ['name' => 123]);  // InvalidViewVariableType

// Good
view('greeting', ['name' => 'Ada']);
```

## How to fix

1. Pass a value of the declared type, or cast it at the call site.
2. Widen the template's declaration if the template really does handle both (`{{-- @var string|int $name --}}`).

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
- The declared type is `mixed`, which is every `@props([...])` entry (`@props` records names, not types).
- The passed value is `mixed`, which satisfies any declaration.
- The declared type does not parse. A `{{-- @var --}}` comment is free text and is never validated.
- The rendering expression is not the whole of an expression or `return` statement, or its chain carries a method this check does not model. Recognized chains are `view()`, `Factory::make()`, `response()->view()`, `Mailable::view()` / `markdown()`, `MailMessage`'s equivalents, and any number of `with()` / `withErrors()` calls on top of them.
- Psalm is running `--taint-analysis`, which on Psalm 6 reports taint issues only.
