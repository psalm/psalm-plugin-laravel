---
title: MissingView
parent: Custom Issues
nav_order: 4
---

# MissingView

Emitted when `view()` or `View::make()` references a Blade template that does not exist on disk.

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
View::make('admin.old-dashboard'); // MissingView

// Good
View::make('admin.dashboard');
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
        <detectMissingViews value="true" />
    </pluginClass>
</plugins>
```

## Limitations

- Only string literal view names are checked — dynamic or concatenated names are skipped
- Namespaced views (e.g., `mail::html.header`) are skipped
- Only `.blade.php` and `.php` extensions are checked
- Only view paths known at boot time are searched (`config('view.paths')` plus paths added by service providers)
