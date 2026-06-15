---
title: PublicModelAccessor
parent: Custom Issues
nav_order: 9
---

# PublicModelAccessor

A `public` legacy Eloquent attribute accessor or mutator, `getXxxAttribute()` / `setXxxAttribute()`. Laravel's convention is `protected`. Enabled by default; see [How to disable](#how-to-disable).

## Why it matters

It is dispatched indirectly through `__get()` / `__set()` magic and never called by its declared name, so `public` only widens the model's API surface. Unlike a public `#[Scope]`, whose idiomatic static call fatals ([PublicModelScope](PublicModelScope.md)), it breaks nothing on the path anyone writes, so it is a pure convention nit on otherwise-correct code.

Reported as an error only at Psalm's strictest level (1) and downgraded for everyone else, so a hard failure is effectively opt-in through maximum strictness. A method whose visibility is forced by a contract (an interface method, a parent override, or an abstract trait method) is not reported, since it cannot be narrowed.

## Example

```php
class Post extends Model
{
    protected function getTitleAttribute(): string // public here would be reported
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

Only the legacy accessor / mutator forms are detected: `getXxxAttribute()`, `setXxxAttribute()`. The modern `Attribute`-returning accessor form is out of scope, the framework's own `getAttribute()` / `setAttribute()` are never matched, and `private` is left alone. Legacy `scopeXxx()` query scopes are deliberately not reported, since `public` is Laravel's documented idiom for them. The sibling check for `public` `#[Scope]` scopes is [PublicModelScope](PublicModelScope.md).
