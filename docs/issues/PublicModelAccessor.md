---
title: PublicModelAccessor
parent: Custom Issues
nav_order: 9
---

# PublicModelAccessor

Emitted when an Eloquent model exposes a `public` legacy attribute accessor or mutator:
`getXxxAttribute()` / `setXxxAttribute()`.

This check is enabled by default. See [How to disable](#how-to-disable) to turn it off.

## Why this is a problem

Laravel dispatches these through `__get()` / `__set()` magic (`$post->title`), which resolves the method
on `$this`. The call site never names the method, so `public` adds no usable entry point and only widens
the model's API surface. Laravel's convention is `protected`.

The issue is reported at the method's own declaration, so an accessor hosted on a trait is flagged once,
on the trait, regardless of how many models compose it.

## Examples

```php
// Bad: public accessor / mutator
class Post extends Model
{
    public function getTitleAttribute(): string { return ucfirst($this->attributes['title']); }

    public function setTitleAttribute(string $value): void { $this->attributes['title'] = trim($value); }
}
```

```php
// Good: protected, matching Laravel's convention
class Post extends Model
{
    protected function getTitleAttribute(): string { return ucfirst($this->attributes['title']); }

    protected function setTitleAttribute(string $value): void { $this->attributes['title'] = trim($value); }
}
```

## How to fix

Change the `public` keyword to `protected` on the reported accessor or mutator. Call sites are
unaffected: accessors and mutators are still reached through property access.

## Scope and limitations

- Only the legacy `getXxxAttribute()` / `setXxxAttribute()` form is detected. The modern attribute form
  (`protected function title(): Attribute`) is out of scope, so a `public` modern accessor is not flagged.
- The framework's own `getAttribute()` / `setAttribute()` (no middle segment) are never matched.
- Only `public` is reported; `private` is a separate dead-code question.

Larastan's `NoPublicModelScopeAndAccessorRule` covers a deliberately different set: it flags non-protected
visibility (public and private) and targets the modern `Attribute`-returning accessor form. The two checks
are complementary rather than equivalent.

## How to disable

The check is on by default. To silence it project-wide, add this to your `psalm.xml`:

```xml
<issueHandlers>
    <PluginIssue name="PublicModelAccessor" errorLevel="suppress" />
</issueHandlers>
```

Use `errorLevel="info"` instead of `suppress` to keep it visible but non-failing.

## Related

[PublicModelScope](PublicModelScope.md) is the sibling check for `public` query scopes.
