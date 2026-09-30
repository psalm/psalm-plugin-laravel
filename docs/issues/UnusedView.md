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

## Suppressing it

`{{-- @psalm-suppress UnusedView --}}` anywhere in the template suppresses it — this is a file-level issue with no single call site to attach a comment to, so the position of the comment inside the template does not matter. An `<issueHandlers>` entry scoped to the view directory works too, as for any other issue.

## Limitations

- The `view()` helper, `View::make()` (including through an aliased `use ... as X` import), and any `->make()`/`->view()` instance call — `Factory::make()`, `response()->view()`, `Mailable::view()`, and similar — are read from plain PHP files, by method name only: an unrelated `->view()`/`->make()` call that happens to take a string first argument is over-collected as a "used" reference (harmless) but never disables the check. `Route::view()` (whose view name is its SECOND argument) and view-name bindings passed anywhere but the first argument (`Mailable::markdown()`/`text()`/`html()`, `MailMessage`, `Mailables\Content`) are not recognized in this release.
- `@include`, `@extends`, `@includeFirst`, `@each`, `@includeWhen`, `@includeUnless`, and the `@component('name')` directive are followed from a template. A component TAG (`<x-foo>`, `<x-dynamic-component>`) turns the whole check off for the run instead of being followed, because its resolved view name is never available as a literal in the compiled shadow.
- One reference this plugin cannot resolve statically (a dynamic `view($name)` or `@include($name)`) anywhere in the project turns the check off for the *entire* run, not just for that one template — a single dynamic call site can make every UnusedView verdict unreliable.
- Namespaced views (`package::view.name`) and vendor/package templates are not enumerated as candidates.
