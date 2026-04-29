---
title: NoEnvOutsideConfig
parent: Custom Issues
nav_order: 1
---

# NoEnvOutsideConfig

Emitted when `env()` is called outside the application's config directory (by default the booted app's [`config_path()`](../config.md#configdirectory); configurable via `<configDirectory>` for non-standard layouts).

## Why this is a problem

When you run `php artisan config:cache`, Laravel loads all config files once and caches the result.
After that, the `.env` file is **not loaded** — so any `env()` call outside `config/` returns `null`.

This is a [documented Laravel behavior](https://laravel.com/docs/configuration#configuration-caching):

> You should be confident that you are only calling the `env` function from within your configuration files. [...] If you cache your configuration, the `env` function will only return `null`.

## Examples

```php
// Bad — will return null when config is cached
class PaymentService
{
    public function getKey(): string
    {
        return env('STRIPE_SECRET'); // NoEnvOutsideConfig
    }
}
```

```php
// Good — read env in config, use config() elsewhere

// config/services.php
return [
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
    ],
];

// app/Services/PaymentService.php
class PaymentService
{
    public function getKey(): string
    {
        return config('services.stripe.secret');
    }
}
```

## How to fix

1. Move the `env()` call into a config file (e.g. `config/services.php`)
2. Reference the value via `config()` in your application code

## Custom config directories

By default the plugin treats only the Laravel app's `config_path()` as a config directory. If your project keeps configuration elsewhere (for example BookStack's `app/Config/`) or you want to allow `env()` inside vendor packages, add `<configDirectory>` elements to your `psalm.xml`:

```xml
<pluginClass class="Psalm\LaravelPlugin\Plugin">
    <configDirectory name="app/Config" />
    <configDirectory name="packages/*/config" />
</pluginClass>
```

See [Configuration](../config.md#configdirectory) for the full reference.
