---
title: MissingViewVariable
parent: Custom Issues
nav_order: 12
---

# MissingViewVariable

Emitted when a Blade template declares a variable that the call site rendering it never passes.

A template declares its variables with a `@var` docblock or a `@props` entry, both read by [Blade template analysis](../blade.md):

```blade
<?php /** @var \App\Models\User $user */ ?>
@props(['title' => 'Untitled', 'subtitle'])
```

Every declared variable is required, including `T|null` and `mixed` (a nullable type means "pass `null` explicitly"). A declaration ending in `= null` is optional: `@var string|null $subtitle = null`. A name Blade supplies (`$errors`, `$slot`) or the template binds itself (a `@foreach` alias, an assignment target) is not a declaration. See [Declaring view variables](../blade.md#declaring-view-variables).

A layout's declarations count at the call sites of every view that `@extends` it, since the layout renders with the child's data. The message then names the layout:

```blade
{{-- resources/views/layouts/app.blade.php --}}
<?php /** @var string $siteName */ ?>
<title>{{ $siteName }}</title>
@yield('content')

{{-- resources/views/profile.blade.php --}}
@extends('layouts.app')
```

```php
view('profile', []); // MissingViewVariable: View 'profile' extends layout 'layouts.app', which declares $siteName ...
```

## Why this is a problem

The variable is undefined at render time. Laravel's `HandleExceptions` turns the undefined-variable
warning into an `ErrorException` in every environment, so rendering `{{ $subtitle }}` fails instead of
producing an empty string.

## Examples

```blade
{{-- resources/views/profile.blade.php --}}
<?php
/**
 * @var string $name
 * @var int $age
 */
?>
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
2. Declare it optional if it genuinely is: give it a default in the template's `@props([...])`, or end the declaration with `= null` (`@var string|null $subtitle = null`).
3. Drop the declaration if the template no longer reads that variable.

## Configuration

This check is disabled by default, and needs Blade analysis enabled. Enable it in your `psalm.xml`:

```xml
<plugins>
    <pluginClass class="Psalm\LaravelPlugin\Plugin">
        <blade validateViewData="true" />
    </pluginClass>
</plugins>
```

## Limitations

The check declines rather than guess. It is silent when:

- The view name is not a string literal, or is namespaced (`pkg::view`).
- The template declares nothing, or its `@props([...])` array is not fully literal (the declared set is then only a lower bound).
- The `@props` entry carries a literal default, which Blade fills in itself, or the `@var` declaration ends in `= null`.
- The name is one the framework supplies to every view (`$app`, `$errors`, anything a provider `share()`d at boot): it is never a declaration.
- A view composer or creator is registered for the view, wildcard ones (`composing: *`, `admin.*`) included: it can add any data, so the call site's data set proves nothing.
- For a layout's declaration: the child or a nearer layout declares or binds the name (an assignment, a loop alias, `@inject`); a link of the `@extends` chain is dynamic, namespaced, `@extendsFirst`, or carries its own data argument; a layout in the chain is not a compiled template, or has a composer or creator; or a template in the chain hides which names it binds (`@props`, `extract()`).
- The call site is the `render()` method of an `Illuminate\View\Component` subclass: `render()` is public, and a caller can chain `->with([...])` on its result.
- The supplied key set cannot be proven closed: a spread in the data array, a dynamic `with()` key, a `$mergeData` argument, or a data argument whose type is not a single sealed keyed array.
- The rendering expression is not the whole of an expression or `return` statement, or its chain carries a method this check does not model. Recognized chains are `view()`, `Factory::make()`, `response()->view()`, `Mailable::view()` / `markdown()`, `MailMessage`'s equivalents, and any number of `with()` / `withErrors()` calls on top of them. `with()` is read the way Laravel dispatches it, on `is_array($key)` rather than on the argument count — except on a `MailMessage` chain, where `with()` appends a notification line and binds no template data.

A view rendered from the `render()` of an `Illuminate\View\Component` subclass IS handled: Laravel
merges `Component::data()` — every public non-static property, every public method minus
`ignoredMethods()`, and `$attributes` — into the view's data, so those names count as supplied even
though the `render()` passes only `['component' => $this]`. Property types are kept, so
[InvalidViewVariableType](InvalidViewVariableType.md) still checks them against the template's
declarations. A component with its own `data()` override can add names nothing can enumerate, so its
supplied set is treated as open and this check declines for it.

### Known false positive: variables bound outside the call site

The check reads the data one call site passes. It does not know about the ways Laravel binds a
variable into a view somewhere else entirely:

- A composer or creator that is not registered by the time the plugin boots the application (one added lazily at runtime).
- A `View::share()` that runs per request (middleware, a controller), which layouts commonly rely on.
- A Mailable's public properties (`Mailable::buildViewData()`) and `MailMessage::data()`, which Laravel merges into the view data of a mail call site.
- `@inject('metrics', 'App\Services\Metrics')` inside the template, which resolves from the container rather than from the data array.

A variable that arrives that way is declared by the template but never passed by the call
site, so it is reported. Either drop its `@var` declaration, or silence the rule for the
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
