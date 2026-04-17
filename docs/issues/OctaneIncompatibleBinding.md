---
title: OctaneIncompatibleBinding
parent: Custom Issues
nav_order: 7
---

# OctaneIncompatibleBinding

Emitted when a `singleton()` or `singletonIf()` binding closure resolves a request-scoped Laravel service such as `Request`, `Session`, or `Auth`.

**Opt-in.** Enable via `<findOctaneIncompatibleBinding value="true" />` in `psalm.xml`. See [Configuration](../config.md#findoctaneincompatiblebinding).

`bind()`, `bindIf()`, `scoped()`, and `scopedIf()` are NOT flagged:

- `bind()` / `bindIf()` re-execute the closure on every resolution.
- `scoped()` / `scopedIf()` are flushed between requests under Octane (via `Container::forgetScopedInstances()`), so captured state does not leak across requests. These are the Octane-safe alternative to `singleton()`.

## Why this is a problem

Under traditional PHP-FPM, every request boots a fresh application instance, so even a "shared" binding is really re-created per request.

Under [Laravel Octane](https://laravel.com/docs/octane), the application instance is **reused across requests**. A shared binding closure runs once and the result is kept for the rest of the worker's lifetime. If that closure captures request-scoped state (a `Request`, the current `Auth` user, a `Session`), every subsequent request sees stale state from the first resolution.

This is a [documented Octane caveat](https://laravel.com/docs/octane#dependency-injection-and-octane):

> You should avoid injecting the application container or HTTP request into the constructor of any object you register as a singleton.

## Examples

```php
// Bad. The Request is captured once and reused for every future request.
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MyService::class, function ($app) {
            return new MyService($app->make(Request::class)); // OctaneIncompatibleBinding
        });
    }
}
```

```php
// Also bad. Same issue, facade variant.
$this->app->singleton(MyService::class, function () {
    return new MyService(App::make(Request::class)); // OctaneIncompatibleBinding
});
```

```php
// Good. Use bind() so the closure re-runs on every resolution.
$this->app->bind(MyService::class, function ($app) {
    return new MyService($app->make(Request::class));
});
```

```php
// Also good. Keep the singleton, but resolve the request-scoped service at the
// point of use instead of constructor injection.
class MyService
{
    public function __construct(private \Illuminate\Contracts\Container\Container $container) {}

    public function handle(): void
    {
        $request = $this->container->make(Request::class);
        // ...
    }
}
```

## Detected services

The following abstracts are treated as request-scoped:

- `Illuminate\Http\Request` and its Symfony parent `Symfony\Component\HttpFoundation\Request`, alias `'request'`
- `Illuminate\Session\Store`, `Illuminate\Session\SessionManager`, `Illuminate\Contracts\Session\Session`, aliases `'session'`, `'session.store'`
- `Illuminate\Auth\AuthManager`, `Illuminate\Contracts\Auth\Factory`, `Illuminate\Contracts\Auth\Guard`, `Illuminate\Contracts\Auth\Authenticatable`, aliases `'auth'`, `'auth.driver'`
- `Illuminate\Cookie\CookieJar`, alias `'cookie'`
- `Illuminate\Config\Repository`, `Illuminate\Contracts\Config\Repository`, alias `'config'` (Octane clones config per request)
- `Illuminate\Routing\UrlGenerator`, `Illuminate\Contracts\Routing\UrlGenerator`, alias `'url'`
- `Illuminate\Routing\Redirector`, alias `'redirect'`

## Known gaps

- `Container::getInstance()->make(...)` inside a closure is not detected.
- The rule does not trace transitive dependencies. Singleton A that depends on singleton B which captures `Request` is flagged only at B.
- Named-argument call sites like `singleton(concrete: fn() => ..., abstract: X::class)` are missed (the second positional slot is read positionally).

## How to fix

- Change `singleton()` to `scoped()`. Octane flushes scoped instances between requests, so the closure runs once per request instead of once per worker.
- Or change `singleton()` to `bind()`. The closure will re-run on every resolution (simpler but no intra-request caching).
- Or keep the singleton and move the request-scoped resolution out of the constructor: inject the container and resolve lazily inside the method that actually uses it.
