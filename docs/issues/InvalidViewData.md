---
title: InvalidViewData
parent: Custom Issues
nav_order: 12
---

# InvalidViewData

Emitted when the value a call site passes for a Blade template variable does not satisfy the type
that template declares for it in a `@var` docblock.

## Why this is a problem

The template was written against the declared type and reads the value accordingly (a property
fetch, a method call, a format string). A value of the wrong type either fatals at render time or
renders something nobody intended, and the failure surfaces in a template rather than at the call
site that caused it.

## Examples

```blade
{{-- resources/views/greeting.blade.php --}}
<?php /** @var string $name */ ?>
<p>{{ $name }}</p>
```

```php
// Bad
view('greeting', ['name' => 123]);              // InvalidViewData
view('greeting')->with('name', 123);            // InvalidViewData
view('greeting')->with('name');                 // InvalidViewData: a single-key with() assigns null
response()->view('greeting', ['name' => 123]);  // InvalidViewData

// Good
view('greeting', ['name' => 'Ada']);
```

In a template, the keys a literal data array passes to `@include`, `@includeIf`, `@includeWhen`, `@includeUnless`, `@includeFirst` and `@extendsFirst` (against the first candidate that exists), `@includeIsolated` or `@extends` are checked the same way. See [Included templates](../blade.md#included-templates).

## How to fix

1. Pass a value of the declared type, or cast it at the call site.
2. Widen the template's declaration if the template really does handle both (`@var string|int $name`).

## Reporting level

Runs whenever [Blade analysis](../blade.md) is on. Without an explicit issue handler, it is reported as `info` by default: hidden unless Psalm runs with `--show-info=true`, never failing the run, and left out of a baseline. Promote that default to an error in `psalm.xml`:

```xml
<pluginClass class="Psalm\LaravelPlugin\Plugin">
    <blade />
    <experimental value="true" />
</pluginClass>
```

Or promote this issue alone:

```xml
<issueHandlers>
    <PluginIssue name="InvalidViewData" errorLevel="error" />
</issueHandlers>
```

Any explicit `<PluginIssue>` entry owns this issue's complete reporting policy, regardless of `<experimental>`.

## Limitations

The check declines rather than guess. It is silent when:

- The view name is not a string literal, or names a template that is not analyzed: a namespace hint (`pkg::view`) under your vendor directory, or an unknown namespace.
- The declared type is `mixed`, which is every `@props([...])` entry (a literal default types the template body, never what a caller may pass).
- The passed value is `mixed`, which satisfies any declaration.
- The passed value is of a class that does not exist, which Psalm already reports where it is named.
- The passed value is a `Renderable` (a view, for example) and the declaration accepts a `string`, because Laravel renders it to a string before the template sees it.
- The declared type does not parse. A `@var` docblock is free text and is never validated.
- The variable is one a view composer or creator supplies, which may overwrite the passed value: a composer runs at render time, a creator inside `view()` once the data array is set (a chained `->with()` still beats a creator, so skipping it there is merely conservative). Other variables of that view are still checked, and a view with a composer the plugin cannot read is skipped entirely; see [Composers and creators](MissingViewData.md#composers-and-creators) for what is read and the accepted residual. A composer registered after boot (middleware, controller) is not seen, so its keys are still type-checked.
- The rendering expression is not the whole of an expression or `return` statement, or its chain carries a method this check does not model. Recognized chains are `view()`, `Factory::make()`, `response()->view()`, `Mailable::view()` / `markdown()`, `MailMessage`'s equivalents, and any number of `with()` / `withErrors()` calls on top of them. `with()` is read the way Laravel dispatches it, on `is_array($key)` rather than on the argument count — except on a `MailMessage` chain, where `with()` appends a notification line and binds no template data.
