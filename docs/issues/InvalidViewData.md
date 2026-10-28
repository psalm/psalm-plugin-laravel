---
title: InvalidViewData
parent: Custom Issues
nav_order: 12
---

# InvalidViewData

Emitted when the value a call site passes for a Blade template variable does not satisfy the type that template declares for it in a `@var` docblock. A type mismatch can fail during rendering or produce incorrect output, and the failure surfaces in the template rather than at the call site that caused it.

## Example

```blade
{{-- resources/views/greeting.blade.php --}}
<?php /** @var string $name */ ?>
<p>{{ $name }}</p>
```

```php
view('greeting', ['name' => 123]);              // InvalidViewData
view('greeting')->with('name');                 // InvalidViewData: a single-key with() assigns null

view('greeting', ['name' => 'Ada']);            // OK
```

The same applies to `->with('name', 123)` and `response()->view()`. In a template, the keys a literal data array passes to an include directive or `@extends` are checked the same way (see [supported call sites](../blade.md#supported-call-sites)).

## How to fix

1. Pass a value of the declared type, or cast it at the call site.
2. Widen the template's declaration if the template really does handle both (`@var string|int $name`).

## Limitations

The check declines rather than guess. On top of the shared [supported call sites](../blade.md#supported-call-sites), it is silent when:

- The declared type is `mixed`, which is every `@props([...])` entry (a literal default types the template body, never what a caller may pass).
- The passed value is `mixed`, which satisfies any declaration.
- The passed value is of a class that does not exist, which Psalm already reports where it is named.
- The passed value is a `Renderable` (a view, for example) and the declaration accepts a `string`, because Laravel renders it to a string before the template sees it.
- The declared type does not parse. An unparseable declared type is excluded from this comparison; invalid optional declarations (`@var string $x = null`) can separately report `InvalidDocblock`.
- The variable is supplied by a view composer or creator, which may overwrite the passed value. Other variables of that view are still checked, and a view with a composer the plugin cannot read is skipped. A composer registered after boot is not seen, so its keys are still type-checked. See [Composers and creators](../blade.md#composers-and-creators).

Framework-supplied properties (class components, Mailable, Livewire) are checked against their declared type: see [Framework-supplied data](../blade.md#framework-supplied-data). For promotion and suppression, see the shared [reporting level](../blade.md#reporting-level).
