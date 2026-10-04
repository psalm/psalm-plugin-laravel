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

Disabled by default; enable it in your `psalm.xml` (or via `<experimental value="true" />`, see [Configuration](../config.md#findunregisteredroutenames)):

```xml
<plugins>
    <pluginClass class="Psalm\LaravelPlugin\Plugin">
        <findUnregisteredRouteNames value="true" />
    </pluginClass>
</plugins>
```

Once enabled it reports at Psalm's normal `error` level; override per issue with `<issueHandlers>`.

## When the check stays off

The plugin arms the check only when the route table can be trusted, and otherwise stays silent:

- The plugin booted through the Testbench fallback (a package/library project): that boot never loads the project's route files, and on Laravel 12 its table still carries framework routes such as `storage.local`.
- The project's `bootstrap/app.php` threw during boot, so the table may be partial.
- The booted application resolves zero named routes, including a route cache (`bootstrap/cache/routes-v7.php`) that carries none.
- The application registers a missing-named-route resolver (`UrlGenerator::resolveMissingNamedRoutesUsing()`), which `route()` consults before throwing. A `url` service that is not Laravel's `UrlGenerator` cannot be probed and counts as having one.

## Limitations

- Names are checked when they are string literals, string-backed enum cases, or class constants typed as one string literal (`self::` included). Variables, concatenation, int-backed and pure enum cases, `static::` / `parent::` constants, and global constants are skipped
- A call site guarded by `Route::has('name')` is not tracked
- Routes registered conditionally (feature flag, env check, package-specific registration) can produce a false positive if the analysed boot does not register them like production does
- A stale route cache (written before a route was added, renamed, or named) can produce a false positive; `php artisan route:cache` or `route:clear` fixes it
- A missing-named-route resolver registered after boot (middleware, controller, test) cannot be detected
- Blade templates are out of scope
