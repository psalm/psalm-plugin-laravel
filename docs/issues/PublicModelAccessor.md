---
title: PublicModelAccessor
parent: Custom Issues
nav_order: 9
---

# PublicModelAccessor

A `public` legacy Eloquent convention method: a legacy `scopeXxx()` query scope, or a legacy `getXxxAttribute()` / `setXxxAttribute()` accessor or mutator. Laravel's convention is `protected`. Enabled by default; see [How to disable](#how-to-disable).

## Why it matters

These are dispatched indirectly (scopes through the query builder, accessors through `__get()` / `__set()` magic) and never called by their declared name, so `public` only widens the model's API surface. Unlike a public `#[Scope]`, whose idiomatic static call fatals ([PublicModelScope](PublicModelScope.md)), these break nothing on the path anyone writes, so each is a pure convention nit on otherwise-correct code.

Reported as an error only at Psalm's strictest level (1) and downgraded for everyone else, so a hard failure is effectively opt-in through maximum strictness. A method whose visibility is forced by a contract (an interface method, a parent override, or an abstract trait method) is not reported, since it cannot be narrowed.

## Example

```php
class Post extends Model
{
    protected function scopePublished(Builder $query): Builder // public here would be reported
    {
        return $query->whereNotNull('published_at');
    }

    protected function getTitleAttribute(): string // and so would a public accessor
    {
        return ucfirst($this->attributes['title']);
    }
}
```

New code should prefer the modern accessor form, which is out of scope for this check:

```php
protected function title(): Attribute
{
    return Attribute::get(fn (mixed $value, array $attributes): string => ucfirst($attributes['title']));
}
```

## How to fix

Change `public` to `protected`, or migrate an accessor to the modern `Attribute` form above. Call sites are unaffected.

## How to disable

```xml
<issueHandlers>
    <PluginIssue name="PublicModelAccessor" errorLevel="suppress" />
</issueHandlers>
```

## Scope

Only the legacy forms are detected: `scopeXxx()`, `getXxxAttribute()`, `setXxxAttribute()`. The modern `Attribute`-returning accessor form is out of scope, the framework's own `getAttribute()` / `setAttribute()` are never matched, and `private` is left alone. The sibling check for `public` `#[Scope]` scopes is [PublicModelScope](PublicModelScope.md).
