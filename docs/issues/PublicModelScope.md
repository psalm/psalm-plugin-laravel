---
title: PublicModelScope
parent: Custom Issues
nav_order: 8
---

# PublicModelScope

Emitted when an Eloquent model exposes a `public` query scope: a legacy `scopeXxx()` method or a
`#[Scope]`-attributed method.

This check is enabled by default. See [How to disable](#how-to-disable) to turn it off.

## Why this is a problem

Laravel dispatches scopes indirectly through the query builder (`Post::query()->published()`), which
forwards to the model. The call site never names the method, so `public` adds no usable entry point and
only widens the model's API surface.

A `public` `#[Scope]` is worse than a smell: calling it statically (`Post::published()`) is a runtime
fatal, because PHP resolves the accessible non-static method and throws before `__callStatic` can route
it through the builder (see [#634](https://github.com/psalm/psalm-plugin-laravel/issues/634) and
[vimeo/psalm#11876](https://github.com/vimeo/psalm/issues/11876)).

The issue is reported at the method's own declaration, so a scope hosted on a trait is flagged once, on
the trait, regardless of how many models compose it.

## Examples

```php
// Bad: public scopes
class Post extends Model
{
    public function scopePublished(Builder $query): Builder { return $query->whereNotNull('published_at'); }

    #[Scope]
    public function active(Builder $query): Builder { return $query->where('active', true); }
}
```

```php
// Good: protected, matching Laravel's convention
class Post extends Model
{
    protected function scopePublished(Builder $query): Builder { return $query->whereNotNull('published_at'); }

    #[Scope]
    protected function active(Builder $query): Builder { return $query->where('active', true); }
}
```

## How to fix

Change the `public` keyword to `protected` on the reported scope. Call sites are unaffected: scopes are
still reached through the query builder.

## Why only `public`

Only `public` scopes are reported. `private` is deliberately left alone: a `private` `#[Scope]` is
rejected by Laravel itself (and surfaces elsewhere), and a `private` legacy scope is a separate dead-code
question. Larastan's `NoPublicModelScopeAndAccessorRule` additionally flags `private`.

## How to disable

The check is on by default. To silence it project-wide, add this to your `psalm.xml`:

```xml
<issueHandlers>
    <PluginIssue name="PublicModelScope" errorLevel="suppress" />
</issueHandlers>
```

Use `errorLevel="info"` instead of `suppress` to keep it visible but non-failing.

## Related

[PublicModelAccessor](PublicModelAccessor.md) is the sibling check for legacy attribute accessors and
mutators.
