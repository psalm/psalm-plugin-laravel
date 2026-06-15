---
title: PublicModelScope
parent: Custom Issues
nav_order: 8
---

# PublicModelScope

A `public` `#[Scope]`-attributed Eloquent query scope. Laravel's convention is `protected`. Enabled by default; see [How to disable](#how-to-disable).

## Why it matters

A `public` `#[Scope]` is a runtime hazard, not just a convention slip. A static call such as `Post::published()` fatals, because PHP resolves the accessible non-static method before `__callStatic` can forward it to the query builder (see #634 and vimeo/psalm#11876). Keeping it `protected` leaves the static call routed to the builder.

Reported as an error at project error levels 1 to 4, the range most analysis-serious codebases use; downgraded at looser levels (5 to 8). A scope whose visibility is forced by a contract (an interface method, a parent override, or an abstract trait method) is not reported, since it cannot be narrowed.

Legacy `scopeXxx()` scopes are not reported at all: `public` is Laravel's documented idiom for them, and their `$builder->active()` dispatch is unaffected by visibility, so there is nothing to flag. Only the `#[Scope]` form, whose static call is a genuine runtime fatal, is checked.

## Example

```php
class Post extends Model
{
    #[Scope]
    protected function published(Builder $query): Builder // public here would be reported
    {
        return $query->whereNotNull('published_at');
    }
}
```

## How to fix

Change `public` to `protected`. Call sites are unaffected.

## How to disable

```xml
<issueHandlers>
    <PluginIssue name="PublicModelScope" errorLevel="suppress" />
</issueHandlers>
```

`private` is intentionally not flagged; a private `#[Scope]` is rejected by Laravel and surfaces elsewhere. The sibling check for legacy accessors and mutators is [PublicModelAccessor](PublicModelAccessor.md).
