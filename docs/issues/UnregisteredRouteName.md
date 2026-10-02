---
title: UnregisteredRouteName
parent: Custom Issues
nav_order: 12
---

# UnregisteredRouteName

Emitted when `route()`, `to_route()`, `URL::route()` / `signedRoute()` / `temporarySignedRoute()`, `Redirect::route()`, `redirect()->route()`, or `url()->route()` references a route name that is not registered anywhere in the booted application.

Controlled by the `findUnregisteredRouteNames` flag (see [Configuration](../config.md)).

## Why this is a problem

Laravel throws a `RouteNotFoundException` at runtime when `route()` or `URL::route()` is called with a name that isn't registered and the application has no missing-named-route resolver (see below). This check catches typos and stale references to renamed or removed routes during static analysis.

## Examples

```php
// Bad: stale reference to a removed route
route('dashboard-legacy'); // UnregisteredRouteName

// Good — the route is registered
route('dashboard');
```

```php
// Named arguments are resolved by parameter name, not by position
route(absolute: false, name: 'dashboard-legacy'); // UnregisteredRouteName
```

```php
// Bad — the route was renamed and the redirect wasn't updated
return redirect()->route('users.show', $user); // UnregisteredRouteName, if the route is now 'members.show'

// Good
return redirect()->route('members.show', $user);
```

## How to fix

1. Check that the route is registered under that exact name in your route files
2. Fix any typos in the route name
3. If the route is registered conditionally (a feature flag, an env-gated route file, or a package that registers it only in certain configurations), see the limitations below

## Configuration

This check is disabled by default. Enable it in your `psalm.xml`:

```xml
<plugins>
    <pluginClass class="Psalm\LaravelPlugin\Plugin">
        <findUnregisteredRouteNames value="true" />
    </pluginClass>
</plugins>
```

## Missing-named-route resolvers

`UrlGenerator::route()` consults a callback registered through `resolveMissingNamedRoutesUsing()` before it throws, so in an application that registers one, a name absent from the route table can still produce a working URL at runtime. The plugin detects that during boot and disables this check entirely (silently, since registering the resolver is an explicit opt-in to dynamic route resolution, not a degradation). A project-specific `url` service that is not an `Illuminate\Routing\UrlGenerator` cannot be probed, so it disables the check as well, rather than reporting names the plugin cannot judge.

A resolver registered after boot, for instance inside middleware or a controller, is invisible to static analysis and remains a false-positive source.

## When the check bails

The check runs only when the plugin boots the project's own `bootstrap/app.php`. A package/library project analysed through the Testbench fallback is skipped without a warning: that boot never loads the project's route files, so its route table (which on Laravel 12 still carries framework routes such as `storage.local`) says nothing about the names the package uses.

Under a real boot, the plugin also bails, with no findings at all, when the application resolves zero named routes, rather than reporting every route name as missing. When that empty table comes from a route cache carrying no named routes, it warns and names `route:cache` and `route:clear`, since a real application with real routes silently going unchecked is worth flagging.

## Limitations

- Only string literal route names are checked — dynamic or concatenated names are skipped
- `\BackedEnum` route names (Laravel 11+) are skipped
- An empty name (`route('')`) is skipped by design. It can never match a registered route, so a finding would restate a mistake that is already plain at the call site, and an empty literal usually means unfinished scaffolding rather than a typo'd name
- A call site guarded by `Route::has('name')` is not tracked — the guarded branch still reports if the name is unregistered in the analysed boot
- Routes registered conditionally (behind a feature flag, an env check, or a package's own conditional registration) can produce a false positive if the plugin's boot doesn't register them the same way production does
- Blade templates are out of scope — only PHP call sites are checked
- When `bootstrap/cache/routes-v7.php` is present, named routes are read from it, the same as from a live route-file boot
- A missing-named-route resolver registered after boot (in middleware, a controller, or a test) cannot be detected, so names it would have resolved are still reported
- A stale route cache (one written before a route was added, renamed, or given a name) can produce a false positive, reporting a route that does exist because the cache predates it. Running `php artisan route:cache` again, or `php artisan route:clear`, resolves it. This is a known, accepted limitation of checking against whatever route table the analysed boot actually resolves
