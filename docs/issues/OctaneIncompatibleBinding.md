---
title: OctaneIncompatibleBinding
parent: Custom Issues
nav_order: 7
---

# OctaneIncompatibleBinding

Emitted when a `singleton()` or `singletonIf()` binding closure resolves a request-scoped Laravel service such as `Request`, `Session`, `Auth`, or `Config`.

**Auto-enabled when `laravel/octane` is installed.** Projects that don't depend on the package directly can opt in via `<findOctaneIncompatibleBinding value="true" />` in `psalm.xml`. To opt out even when `laravel/octane` is installed, set `<findOctaneIncompatibleBinding value="false" />`. See [Configuration](../config.md#findoctaneincompatiblebinding).

`bind()`, `bindIf()`, `scoped()`, and `scopedIf()` are NOT flagged. `bind()` re-executes the closure on every resolution; `scoped()` instances are flushed between requests under Octane (via `Container::forgetScopedInstances()`), so neither leaks captured state.

## Why this is a problem

Under traditional PHP-FPM, every request boots a fresh application instance, so even a "shared" binding is really re-created per request.

Under [Laravel Octane](https://laravel.com/docs/octane), the application instance is **reused across requests**. A shared binding closure runs once and the result is kept for the rest of the worker's lifetime. If that closure captures request-scoped state (a `Request`, the current `Auth` user, a `Session`), every subsequent request sees stale state from the first resolution.

This is a [documented Octane caveat](https://laravel.com/docs/octane#dependency-injection-and-octane):

> You should avoid injecting the application container or HTTP request into the constructor of any object you register as a singleton.

`Config` is a special case. The repository binding itself is a singleton, but Octane resets its state between requests, so values read inside a singleton closure freeze at first-resolution time even though the repository instance is reused.

## Examples

```php
// Bad. The Request is captured once and reused for every future request.
$this->app->singleton(MyService::class, function ($app) {
    return new MyService($app->make(Request::class)); // OctaneIncompatibleBinding
});

// Good. Use bind() so the closure re-runs on every resolution.
$this->app->bind(MyService::class, function ($app) {
    return new MyService($app->make(Request::class));
});

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

```php
// Bad. Config values read inside a singleton closure are frozen at first resolution.
$this->app->singleton(MyService::class, function ($app) {
    $config = $app->make('config'); // OctaneIncompatibleBinding
    return new MyService($config->get('myservice.endpoint'));
});

// Good. Read config via the facade at call site so the lookup happens on each resolution.
$this->app->singleton(MyService::class, function () {
    return new MyService(Config::string('myservice.endpoint'));
});
```

## How to fix

- Change `singleton()` to `scoped()`. Octane flushes scoped instances between requests, so the closure runs once per request instead of once per worker.
- Or change `singleton()` to `bind()`. The closure will re-run on every resolution (simpler but no intra-request caching).
- Or keep the singleton and move the request-scoped resolution out of the constructor: inject the container and resolve lazily inside the method that actually uses it.
- For `config` specifically, replace `$app->make('config')` with the `Config` facade inside the closure.

## Fixing with Psalter

Psalter can apply the `scoped()` fix for you:

```bash
# Preview the diff, then apply
./vendor/bin/psalter --issues=OctaneIncompatibleBinding --dry-run
./vendor/bin/psalter --issues=OctaneIncompatibleBinding
```

`singleton()` becomes `scoped()` and `singletonIf()` becomes `scopedIf()`. Only the method name is rewritten: the abstract, the closure and the receiver stay as written, so it works for `$this->app` and for a container typed as `Illuminate\Contracts\Container\Container` alike. A closure that resolves several request-scoped services is renamed once. The rename deliberately changes the binding's lifetime from one instance per worker to one per request; that is the fix, not a side effect.

- **Why `scoped()` and not `bind()`.** `scoped()` keeps one instance per request (or per queue job): Octane flushes it between requests through `forgetScopedInstances()`, so nothing leaks, and repeated resolutions within the request still share the instance. `bind()` would rebuild the service on every resolution. Outside Octane, a scoped binding behaves like a singleton for the lifetime of the request.
- **Suppressed calls** (`@psalm-suppress OctaneIncompatibleBinding`, or `<PluginIssue name="OctaneIncompatibleBinding" errorLevel="suppress" />` in `psalm.xml`) are not rewritten.
- **Calls in Blade templates** are reported but never rewritten.
- Only `$container->singleton()` / `singletonIf()` method calls are fixed, the same calls the rule reports.
- **Left alone, still reported.** A binding on a container whose `scoped()` is not Laravel's own, or, for `singletonIf()`, whose `scopedIf()` or `scoped()` is not (Laravel's `scopedIf()` calls `$this->scoped()`): a container subclass may give them other parameters, and the call's named arguments (`singleton(abstract: ..., concrete: ...)`) would then throw after the rename. The rename is made only when these methods are `Illuminate\Container\Container`'s or the `Illuminate\Contracts\Container\Container` contract's on every class the receiver can be: a union such as `PlainContainer|ContainerWithOwnScoped` is left alone, because one edit renames the call for all of its members.

## Known gaps

The stock check uses the receiver's declared type. A runtime subclass of that type that overrides `scoped()` or `scopedIf()` is not detected, so `singleton(abstract: ..., concrete: ...)` on such an instance would still throw after the rename. Type the receiver as the concrete container, or review the diff.
