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
- The variable is one a view composer or creator supplies (see [Composers and creators](#composers-and-creators)). The other declared variables of that view are still checked; when a composer cannot be read, the whole view is skipped.
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

## Composers and creators

A composer or creator registered for the view (exact name or a wildcard such as `admin.*`) can
supply any key. A creator runs inside `view()` (`Factory::make()`), after the data array is set but
before any chained `->with()`; a composer runs at render time, after the whole call site. The plugin
reads the method instead of running it and exempts only the keys it passes; every other declared
variable is checked as usual. [InvalidViewVariableType](InvalidViewVariableType.md) skips those keys
too, since the composer or creator may overwrite the passed value (a chained `->with()` beats a
creator, so skipping it there is merely conservative).

What is read:

- Class composers and creators: `View::composer('x', Foo::class)`, `'Foo@method'`, `View::creator(...)`, wildcard names included. The method may be inherited or come from a trait. The keys of several composers on one view are unioned.
- Every `$view->with('key', ...)` and `$view->with(['key' => ..., ...])` with string literal keys, chained or not, as a statement of its own.
- `$view->name()`, `$view->getName()` and `$view->getPath()`, which only read the view: a composer may compute a value from them and pass it to `with()`.

The whole view is skipped, as if every key were composer-supplied, when any composer reaching it:

- is a closure, or a listener registered straight on the event dispatcher (`Event::listen('composing: x', ...)`, `composing: *` included);
- passes a key that is not a string literal: a variable, `with($array)`, a spread or named arguments;
- uses `$view` any other way: passes it on (`$this->decorate($view)`, `parent::compose($view)`), assigns, returns or captures it, uses the result of `with()`, sets a property or offset on it, calls another method on it (`withTitle()`, `nest()`, `getData()`), or calls it through `?->`;
- calls `share()`, `extract()`, `get_defined_vars()`, `func_get_args()` or `func_get_arg()`, or a `compact()` naming `$view` or a name that is not a string literal (they reach `$view` by name or position), uses a variable variable, or calls a method or function through a computed name;
- has no method body the plugin can find.

**Accepted residual.** The composer class is read as written. A class the container rebinds, extends
or hooks before resolving, a view factory or dispatcher subclass, a constructor that shares data, a
protected method served by `__call()`, and data reaching the view through `call_user_func()` or an
`include` are not modelled. If the keys such a composer really supplies differ from what its method
shows, the call site reports; the fix is `= null` on the declaration or a suppression (below).

**Limitation: composers registered after boot.** Only composers registered while the application
boots (service providers) are read. A composer registered later, in a middleware or a controller, is
invisible: a view whose only composer is registered at runtime is checked as if it had none, and a
view with a boot composer plus a runtime one is checked against the boot composer's keys only. A
`share()` inside a method the composer calls, or inside a dependency it receives, is not seen either.

### Known false positive: variables bound outside the call site

The check reads the data one call site passes. It does not know about the ways Laravel binds a
variable into a view somewhere else entirely:

- A composer or creator that is not registered by the time the plugin boots the application (one added lazily at runtime).
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

Teaching the check to read `share()` and `@inject` bindings is tracked as a follow-up.
