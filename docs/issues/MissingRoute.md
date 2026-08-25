---
title: MissingRoute
parent: Custom Issues
nav_order: 12
---

# MissingRoute

Emitted when `route()`, `to_route()`, `URL::route()` / `signedRoute()` / `temporarySignedRoute()`, `Redirect::route()`, or `redirect()->route()` references a route name that is not registered anywhere in the booted application.

Controlled by the `findMissingRoutes` flag (see [Configuration](../config.md)).

## Why this is a problem

Laravel throws a `RouteNotFoundException` at runtime when `route()` or `URL::route()` is called with a name that isn't registered. This check catches typos and stale references to renamed or removed routes during static analysis.

## Examples

```php
// Bad — typo in the route name
route('dashbaord'); // MissingRoute

// Good — the route is registered
route('dashboard');
```

```php
// Bad — the route was renamed and the redirect wasn't updated
return redirect()->route('users.show', $user); // MissingRoute, if the route is now 'members.show'

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
        <findMissingRoutes value="true" />
    </pluginClass>
</plugins>
```

The plugin bails on the check entirely, with no findings at all, when the booted application resolves zero named routes. This avoids reporting every route name as missing when the plugin simply has no route table to check against. Two situations produce that empty table: a package/library project analysed through the Testbench fallback (which never loads an application's route files, and produces no warning, since that is the expected shape for a non-application analysis target) and an application whose route cache itself carries no named routes (which does produce a warning naming `route:cache` and `route:clear`, since a real application with real routes silently going unchecked is worth flagging).

## Limitations

- Only string literal route names are checked — dynamic or concatenated names are skipped
- `\BackedEnum` route names (Laravel 11+) are skipped
- A call site guarded by `Route::has('name')` is not tracked — the guarded branch still reports if the name is unregistered in the analysed boot
- Routes registered conditionally (behind a feature flag, an env check, or a package's own conditional registration) can produce a false positive if the plugin's boot doesn't register them the same way production does
- Blade templates are out of scope — only PHP call sites are checked
- When `bootstrap/cache/routes-v7.php` is present, named routes are read from it, the same as from a live route-file boot
- A stale route cache (one written before a route was added, renamed, or given a name) can produce a false positive, reporting a route that does exist because the cache predates it. Running `php artisan route:cache` again, or `php artisan route:clear`, resolves it. This is a known, accepted limitation of checking against whatever route table the analysed boot actually resolves
