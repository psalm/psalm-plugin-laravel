---
title: UnresolvableAppendedAttribute
parent: Custom Issues
nav_order: 11
---

# UnresolvableAppendedAttribute

An Eloquent `$appends` entry that no accessor or class cast backs. Enabled by default; see [How to disable](#how-to-disable).

## Why it matters

`Model::attributesToArray()` runs `mutateAttributeForArray($key, null)` for every `$appends` entry, with no existence guard. That call resolves a value through exactly one of three paths: a class cast (`isClassCastable()`), a new `Attribute`-returning accessor, or a legacy `getXxxAttribute()`. When none of them exist it falls through to `$this->{'get'.Str::studly($key).'Attribute'}()`, which reaches `Model::__call()`, forwards to a fresh query builder, and throws `BadMethodCallException` the moment the model is arrayed or JSON encoded.

So a typo in `$appends`, or a forgotten accessor, is not a silent `null`. It is a runtime fatal on `toArray()` / `toJson()` (and therefore on any API resource or `response()->json($model)`).

## Example

```php
class User extends Model
{
    protected $appends = ['full_name', 'avatar_url'];

    // Backs `full_name`.
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->first_name} {$this->last_name}");
    }

    // `avatar_url` has no accessor and no cast: reported, and User::first()->toArray() throws.
}
```

## How to fix

Add the missing accessor (either form resolves it):

```php
// modern Attribute form
protected function avatarUrl(): Attribute
{
    return Attribute::get(fn (): string => "https://cdn.example.test/{$this->id}.png");
}

// or the legacy form
protected function getAvatarUrlAttribute(): string
{
    return "https://cdn.example.test/{$this->id}.png";
}
```

A declared cast for the attribute also satisfies the rule (a value object backed by a custom cast computes the appended value the same way an accessor does). If the entry was a mistake, remove it from `$appends`.

## How to disable

```xml
<issueHandlers>
    <PluginIssue name="UnresolvableAppendedAttribute" errorLevel="suppress" />
</issueHandlers>
```

## Scope

The rule reports an entry only when the model has neither an accessor nor a declared cast for it. A plain column or a relation does NOT back an appended attribute: the serialization loop passes `null` as the value and ignores the stored attribute, so appending a column name without a matching accessor throws all the same. An entry removed by `$hidden`, or excluded by a non-empty `$visible`, is not reported, since Eloquent drops it before that loop runs. At runtime only a class cast (not a primitive one) resolves an appended value, but the rule treats any declared cast as backing, a deliberately conservative choice that keeps it free of false positives (the cost is a rare missed case: a built-in-cast column listed in `$appends` with no accessor).

Accessor names are matched the way Eloquent resolves them (case insensitive, with separators stripped), so `full_name`, `fullName`, and `getFullNameAttribute()` all line up. Abstract base classes are validated through their concrete descendants, which carry the complete accessor set (a child can supply the accessor for an attribute a base appends). An accessor the plugin cannot detect statically (for example one synthesized at runtime) can be silenced with an inline `@psalm-suppress UnresolvableAppendedAttribute` on the model.
