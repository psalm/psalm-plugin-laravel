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
- The variable is one a view composer or creator provably supplies (see [Composers and creators](#composers-and-creators)). The other declared variables of that view are still checked; when a composer cannot be read, the whole view is skipped.
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

A composer or creator registered for the view (exact name or wildcard such as `admin.*`) runs after
the call site, so it can supply any key. The plugin reads the composer's body instead of running it,
and exempts only the keys it provably passes: every `$view->with('key', ...)`,
`$view->with(['key' => ...])` and named form `$view->with(key: 'key', value: ...)` with a literal key (also as a top-level `return $view->with(...)` of the composer method, since Laravel ignores a composer's return value), in a class composer (`View::composer('x', Foo::class)`,
`Foo@method`) or a creator. `compose()` / `create()` may be inherited or come from a trait. When
several composers reach a view, their keys are unioned. Everything else about the view is checked as
usual; the composer-supplied keys are also skipped by
[InvalidViewVariableType](InvalidViewVariableType.md), since the composer may overwrite the value.

The whole view is skipped, as if every key were composer-supplied, when any composer reaching it:

- is a closure, or a listener registered straight on the event dispatcher (`Event::listen('composing: x', ...)`, including `composing: *`);
- is a class the container binds, aliases or extends, a class a before-resolving callback can rebind (one registered for the class, a parent or an interface of it, or any global `App::beforeResolving(fn)` callback, which declines every composer of the application), a class carrying a `#[Bind]` or `#[BindWhen]` attribute, a listener registered raw under a wildcard key, or the dispatcher or view factory is a subclass that could add behaviour (a dispatcher other than exactly `Illuminate\Events\Dispatcher`, a factory overriding `callComposer()`/`callCreator()`, or `viewInstance()`, whose View subclass could add keys in `with()`);
- has no readable `compose()` / `create()` body (not declared, not public, constructed through a `new` default value, or two traits declaring it) or a constructor that shares data or delegates to `parent::__construct()`;
- passes a key that is not a string literal, passes `with($array)`, `compact()` or a spread, calls `share()` (however spelled), or uses `extract()`, `get_defined_vars()`, `func_get_args()`, `debug_backtrace()` or `call_user_func()`, or calls any `__construct()` (`parent::__construct()`, `Other::__construct()`, `$this->__construct()`);
- lets `$view` out of reach of the walker: passes it to a method or function (`$this->decorate($view)`, `parent::compose($view)`), stores or reassigns it (`return $view;`, or a `return` inside a branch or closure), captures it in a closure, takes a first-class callable of it (`$view->with(...)`, `$view->getName(...)`), sets a property or array offset on it (`$view->title = ...`), calls anything but `with()` on it (`withTitle()`, `nest()`, `getData()`, `getFactory()`). Only `name()`, `getName()` and `getPath()` are read-only and allowed, so a composer that does `$view->with('path', $view->getName())` stays readable.

A listener built by another view factory on the same dispatcher, or one that names an unknown `with()` parameter, also makes the view unknown.

**Limitation: composers registered after boot.** Only composers registered while the application boots (service providers) are read. A composer registered later, in a middleware or a controller, is invisible: a view whose only composer is registered at runtime is checked as if it had none (as since #1821), and a view with a boot composer plus a runtime one is checked against the boot composer's keys only, so a key only the runtime composer supplies is reported as missing.

A `share()` hidden inside a method the composer calls, or inside the constructor of a class it receives as a dependency, is still missed: only the composer's own constructor and method are read.

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
