---
title: MissingView
parent: Custom Issues
nav_order: 4
---

# MissingView

Emitted when a view name passed to any of the following does not correspond to an existing Blade template on disk:

- `view()` helper, `Factory::make()`/`first()`/`renderWhen()`/`renderUnless()`/`renderEach()`/`composer()`/`creator()`, and their `View` facade forms (concrete, contract-typed, and aliases)
- `ResponseFactory::view()` (`response()->view()`, `Illuminate\Contracts\Routing\ResponseFactory`, and the `Response` facade)
- `Router::view()` and the `Route` facade
- `Illuminate\Notifications\Messages\MailMessage::view()`/`markdown()`
- `Illuminate\Mail\Mailable::view()`/`markdown()`/`text()`
- `Illuminate\Mail\Mailables\Content`'s `view`, `html`, `text`, and `markdown` constructor arguments
- `Illuminate\Testing\TestResponse::assertViewIs()`

## Why this is a problem

If the referenced view file doesn't exist, Laravel throws an `InvalidArgumentException` at runtime.
This check catches typos and missing templates during static analysis.

## Examples

```php
// Bad — typo in the view name
view('emails.welcom'); // MissingView

// Good — the view file exists
view('emails.welcome');
```

```php
// Bad — referencing a deleted template
view('admin.old-dashboard'); // MissingView

// Good
view('admin.dashboard');
```

## How to fix

1. Check that the Blade file exists at the expected path (e.g., `resources/views/emails/welcome.blade.php`)
2. Fix any typos in the view name
3. If the view is provided by a package, use the namespaced syntax (e.g., `view('package::view.name')`) — namespaced views are not checked by this rule

## Configuration

This check is disabled by default. Enable it in your `psalm.xml`:

```xml
<plugins>
    <pluginClass class="Psalm\LaravelPlugin\Plugin">
        <findMissingViews value="true" />
    </pluginClass>
</plugins>
```

## Limitations

- Only string literal view names are checked — dynamic or concatenated names are skipped
- Namespaced views (e.g., `mail::html.header`) are skipped
- Only `.blade.php` and `.php` extensions are checked
- Only view paths known at boot time are searched (`config('view.paths')` plus paths added by service providers)
- `Factory::first()` and `ResponseFactory::view()`'s array form only flag the call when every candidate is a literal AND all of them are missing. A non-literal candidate anywhere in the list, or one that resolves, skips the check
- `renderEach()`'s `$empty` argument is skipped when it starts with `raw|` (Laravel treats that as raw text, not a view name)
- `Factory::composer()`/`creator()` wildcard patterns are skipped because they are event patterns, not concrete template names
