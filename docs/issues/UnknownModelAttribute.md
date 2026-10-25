---
title: UnknownModelAttribute
parent: Custom Issues
nav_order: 11
---

# UnknownModelAttribute

Emitted when an array passed to an Eloquent mass-assignment method, or to a creation method on a query builder or relation, names a key that is not a known attribute of the model being filled. This catches typos such as `User::create(['nmae' => $name])` and `$user->roles()->create(['nmae' => $name])`.

## Why this is a problem

Mass assignment is key-based, so a misspelled key is silently dropped instead of writing the intended attribute.
`User::create(['nmae' => $value])` persists a `User` with no name and raises no runtime error when the model uses a `$fillable` allowlist, which makes the mistake easy to miss.
On a `$guarded = []` model, or via `forceFill()` / `forceCreate()`, the key is instead written and `save()` fails with an unknown-column database error.

## What is flagged

A string literal key of the attribute array passed to `create()`, `forceCreate()`, `fill()`, `forceFill()`, or `update()` (plus the `Quietly` and `updateOrFail` variants that share the same shape), invoked statically (`User::create([...])`) or on an instance (`$user->fill([...])`), when the key matches none of the model's recognized attributes.

The recognized set is the union of:

- schema columns and their casts (read from migrations),
- accessors, mutators, and relations,
- the `$appends` and `$fillable` arrays,
- every `@property`, `@property-read`, and `@property-write` docblock on the model.

Creation calls on a builder or relation are checked against the model they fill, taken from the receiver's type (`Builder<Post>`, `HasMany<Post, User>`, a custom builder bound to a model):

| Receiver | Methods | Model |
| --- | --- | --- |
| `Builder` and subclasses, including custom builders | `make()`, `create()`, `createQuietly()`, `forceCreate()`, `forceCreateQuietly()` | `TModel` |
| `HasOne`, `HasMany`, `MorphOne`, `MorphMany` (`HasOneOrMany`) | the five above, plus `makeMany()`, `createMany()`, `createManyQuietly()`, `forceCreateMany()`, `forceCreateManyQuietly()` | `TRelatedModel` |
| `BelongsToMany`, `MorphToMany` | `create()`, `createQuietly()`, `createMany()`, `createManyQuietly()` | `TRelatedModel` |

For the `*Many()` methods every literal array in the literal list is checked. For `BelongsToMany` only the first argument is checked: the second (`$joining` / `$joinings`) is pivot data, not model attributes. `update()` on a builder or relation is not checked: it is a query-builder write that bypasses `fill()`, so its keys are plain column names.

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
- **Ambiguous receivers.** A static call on a non-model class, or an instance call whose receiver is not exactly one Eloquent model, is skipped. For a builder or relation, the receiver type must name exactly one concrete model: an unresolved template (`Builder<TModel>` in generic code, `$this` inside a generic custom builder), `Builder<Model>`, a union of different models, a union with `null`, an intersection type, or a custom builder that extends `Builder` without `@extends Builder<Post>` is skipped.
- **Userland creation overrides.** When a class other than Laravel's own builder/relation classes, in the builder or relation's hierarchy, declares any creation method of that receiver kind (for example a custom builder overriding `create()`), the rule skips all of them: the override may map keys before delegating, and Laravel's `createQuietly()` / `createManyQuietly()` go through it. The override is detected by the exact framework class that declares the method, not by namespace. The delegated hooks count as well: a builder overriding `newModelInstance()`, or a `HasOne` / `HasMany` / `MorphOne` / `MorphMany` subclass overriding `setForeignAttributesForCreate()`.
- **Unprovable record lists.** A `*Many()` list containing a spread or an explicit outer key is skipped entirely, because records may be merged or overwritten.
- **Models that customize mass assignment.** When a model's hierarchy (parent classes and traits, such as a custom-fields package trait) redeclares `fill()`, `forceFill()`, `newInstance()`, `isFillable()`, or `fillableFromArray()` outside Laravel's `Model` / `GuardsAttributes`, the model can accept keys that are no column, so it is skipped on every path: static, instance, builder, and relation calls.
- **Migration files.** A mass-assignment call inside a migration file is skipped (a file counts as a migration when its name carries the migrator's `YYYY_MM_DD_HHMMSS_` prefix or it sits in a `migrations` directory). The plugin replays every migration into one final schema, so a column a later migration drops is absent from it, and a migration that legitimately wrote that column when it ran would be wrongly flagged against the final state.

## Known limitation

- Relations inherited from a parent class or a trait are not part of a model's own recognized set, so a key naming such an inherited relation may be flagged. Declare it with a `@property` annotation on the model to resolve this.
- A model that widens mass assignment only through `setAttribute()` (a `__set`-style override that accepts extra keys) is not detected and is still checked against its recognized attributes. Suppress the issue at that call site.
- The model scan reads Psalm's declaring-method map, which is wrong under trait `insteadof` conflicts ([vimeo/psalm#12113](https://github.com/vimeo/psalm/issues/12113): the first-listed trait's method always wins). A model that selects a custom trait's `isFillable()` / `fillableFromArray()` through `insteadof` may still be checked, and one that selects Laravel's may be skipped.
