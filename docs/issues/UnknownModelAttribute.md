---
title: UnknownModelAttribute
parent: Custom Issues
nav_order: 11
---

# UnknownModelAttribute

Emitted when an array passed to an Eloquent mass-assignment method names a key that is not a known attribute of the receiving model. This catches typos such as `User::create(['nmae' => $name])`.

## Why this is a problem

Mass assignment is key based, so a misspelled key is silently dropped instead of writing the intended attribute. `User::create(['nmae' => $value])` persists a `User` with no name and raises no runtime error, which makes the mistake easy to miss.

## What is flagged

A string literal key of the attribute array passed to `create()`, `forceCreate()`, `fill()`, `forceFill()`, or `update()` (plus the `Quietly` and `updateOrFail` variants that share the same shape), invoked statically (`User::create([...])`) or on an instance (`$user->fill([...])`), when the key matches none of the model's recognized attributes.

The recognized set is the union of:

- schema columns and their casts (read from migrations),
- accessors, mutators, and relations,
- the `$appends` and `$fillable` arrays,
- every `@property`, `@property-read`, and `@property-write` docblock on the model.

Keys are matched case insensitively and ignoring separators, so `fullName`, `full_name`, and `fullname` all resolve to the same attribute. A JSON path key such as `options->theme` is validated by its base column `options`, the column Laravel writes the nested value into.

## Example

```php
// Bad: 'nmae' is a typo, so the name is never set
User::create([
    'nmae'  => $name,
    'email' => $email,
]);

// Good
User::create([
    'name'  => $name,
    'email' => $email,
]);
```

## How to fix

Correct the key to the intended attribute name. If the attribute is genuinely dynamic (set through `__set` with no column or docblock), document it with a `@property` annotation on the model, or add it to `$fillable`, so the analyzer recognizes it.

## When it stays silent (false-positive guards)

Because the rule is registered by default, it errs toward silence whenever it cannot be certain a key is wrong:

- **No column schema.** When migrations are disabled, or a model's table is not parsed, the column set is unknown, so the rule skips that model entirely rather than flag valid columns. With the default `columnFallback="migrations"` the columns come from your migration files.
- **Non-literal arrays.** A variable array, a spread (`[...$attributes]`), or a dynamic key carries no statically known key names, so it is never inspected.
- **Ambiguous receivers.** A static call on a non-model class, or an instance call whose receiver is not exactly one Eloquent model (for example a `Builder` or `Relation`, which is how a mass `update()` on a query is typed), is skipped.

## Known limitation

Relations inherited from a parent class or a trait are not part of a model's own recognized set, so a key naming such an inherited relation may be flagged. Declare it with a `@property` annotation on the model to resolve this.
