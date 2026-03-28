---
title: MissingTranslation
parent: Custom Issues
nav_order: 5
---

# MissingTranslation

Emitted when `__()` or `trans()` references a translation key that does not exist in the application's language files.

## Why this is a problem

If the translation key doesn't exist, Laravel returns the key itself as a string instead of the translated text.
This silently produces untranslated output at runtime. This check catches typos and missing keys during static analysis.

## Examples

```php
// Bad -- typo in the translation key
echo __('mesages.welcome'); // MissingTranslation

// Good -- the key exists in lang/en/messages.php
echo __('messages.welcome');
```

```php
// Bad -- key was removed from language files
echo trans('auth.old_message'); // MissingTranslation

// Good
echo trans('auth.failed');
```

## How to fix

1. Check that the translation key exists in your language files (e.g., `lang/en/messages.php` or `lang/en.json`)
2. Fix any typos in the key name
3. If the translation is provided by a package, use the namespaced syntax (e.g., `__('package::file.key')`) -- namespaced keys are not checked by this rule

## Configuration

This check is disabled by default. Enable it in your `psalm.xml`:

```xml
<plugins>
    <pluginClass class="Psalm\LaravelPlugin\Plugin">
        <findMissingTranslations value="true" />
    </pluginClass>
</plugins>
```

## Limitations

- Only string literal keys are checked -- dynamic or concatenated keys are skipped
- Namespaced package keys (e.g., `pagination::pages.next`) are skipped
- Only `__()` and `trans()` are checked -- `trans_choice()`, `Lang::get()`, and Blade `@lang` directives are not detected
- Uses Laravel's Translator to resolve keys, which respects the configured locale and fallback locale
