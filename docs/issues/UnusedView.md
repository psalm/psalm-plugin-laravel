---
title: UnusedView
parent: Custom Issues
nav_order: 4
---

# UnusedView

Emitted when a Blade template has no statically-provable reference anywhere in the project: no `view()` / `View::make()` call site, and no `@include` / `@extends` from another template.

## Why this is a problem

An orphaned template is dead weight: it costs nothing at runtime, but it costs a reviewer's attention every time they read it, and it silently rots (a variable it references may stop existing without anyone noticing).

## Examples

```blade
{{-- resources/views/old-dashboard.blade.php --}}
{{-- Nothing in the project renders this anymore. --}}
<div>...</div>
```

```php
// Good — a call site or another template references it
view('dashboard');
```

## How to fix

1. Delete the template if it is genuinely unused.
2. If it is reached dynamically (`view($name)`, `@include($name)`), that reference alone turns the check off for the whole run (see Limitations) — remove the dynamic reference or accept the trade-off.
3. If it is a package view referenced by a namespaced name (`package::view.name`), this check does not follow that reference; see Limitations.

## Configuration

This check is disabled by default. Enable it alongside Blade analysis in your `psalm.xml`:

```xml
<plugins>
    <pluginClass class="Psalm\LaravelPlugin\Plugin">
        <blade enabled="true" reportUnusedViews="true" />
    </pluginClass>
</plugins>
```

## Limitations

- Only the `view()` helper and the static `View::make()` / `Factory::make()` forms are read from plain PHP files. `response()->view()`, `Route::view()`, Mailable and `MailMessage` view bindings, and any other method-call form are not recognized as references in this release.
- Only `@include` and `@extends` (and the templates their compiled form shares a mechanism with) are read from a template. `@includeWhen`, `@includeUnless`, `@each`, `@component`, and component tags (`<x-foo>`, `<x-dynamic-component>`) are not followed.
- One reference this plugin cannot resolve statically (a dynamic `view($name)` or `@include($name)`) anywhere in the project turns the check off for the *entire* run, not just for that one template — a single dynamic call site can make every UnusedView verdict unreliable.
- Namespaced views (`package::view.name`) and vendor/package templates are not enumerated as candidates.
