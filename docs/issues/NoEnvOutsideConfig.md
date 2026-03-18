---
title: NoEnvOutsideConfig
parent: Custom Issues
nav_order: 1
---

# NoEnvOutsideConfig

Emitted when `env()` is called outside the `config/` directory.

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
