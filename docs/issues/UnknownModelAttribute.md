---
title: UnknownModelAttribute
parent: Custom Issues
nav_order: 11
---

# UnknownModelAttribute

Emitted when an array passed to an Eloquent mass-assignment method, or to a creation method on a query builder or relation, names a key that is not a known attribute of the model being filled. This catches typos such as `User::create(['nmae' => $name])` and `$user->roles()->create(['nmae' => $name])`.

It is also emitted for a literal column name in the column list of a query read, such as `get()`, `first()` or `find()`, that is not a known attribute of the model the query reads: `User::query()->first(['nmae'])`. See [Column lists of query reads](#column-lists-of-query-reads).

## Why this is a problem

Mass assignment is key-based, so a misspelled key is silently dropped instead of writing the intended attribute.
`User::create(['nmae' => $value])` persists a `User` with no name and raises no runtime error when the model uses a `$fillable` allowlist, which makes the mistake easy to miss.
On a `$guarded = []` model, or via `forceFill()` / `forceCreate()`, the key is instead written and `save()` fails with an unknown-column database error.
A typo in a column list compiles to `select "nmae" from "users"`, which fails with an unknown column error on MySQL and PostgreSQL. SQLite with double-quoted string literals enabled (its default) reads the unknown name as a string, so the query runs and every row carries the literal text `nmae` instead of a value.

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

The rule is on by default and reported at error levels 1 to 4 (like Psalm's `UndefinedMagicPropertyFetch`), `info` at looser levels. Because it runs everywhere, it errs toward silence whenever it cannot be certain a key is wrong:

- **No column schema.** When migrations are disabled, or a model's table is not parsed, the column set is unknown, so the rule skips that model entirely rather than flag valid columns. With the default `columnFallback="migrations"` the columns come from your migration files.
- **Non-literal arrays.** A variable array, a spread (`[...$attributes]`), or a dynamic key carries no statically known key names, so it is never inspected.
- **Ambiguous receivers.** A static call on a non-model class, or an instance call whose receiver is not exactly one Eloquent model, is skipped. For a builder or relation, the receiver type must name exactly one concrete model: an unresolved template (`Builder<TModel>` in generic code, `$this` inside a generic custom builder), `Builder<Model>`, a union of different models, a union with `null`, an intersection type, or a custom builder that extends `Builder` without `@extends Builder<Post>` is skipped.
- **Userland creation overrides.** When a class other than Laravel's own builder/relation classes, in the builder or relation's hierarchy, declares any creation method of that receiver kind (for example a custom builder overriding `create()`), the rule skips all of them: the override may map keys before delegating, and Laravel's `createQuietly()` / `createManyQuietly()` go through it. The override is detected by the exact framework class that declares the method, not by namespace. The delegated hooks count as well: a builder overriding `newModelInstance()`, or a `HasOne` / `HasMany` / `MorphOne` / `MorphMany` subclass overriding `setForeignAttributesForCreate()`.
- **Unprovable record lists.** A `*Many()` list containing a spread or an explicit outer key is skipped entirely, because records may be merged or overwritten.
- **Models that customize mass assignment.** When a model's hierarchy (parent classes and traits, such as a custom-fields package trait) redeclares `fill()`, `forceFill()`, `newInstance()`, `isFillable()`, or `fillableFromArray()` outside Laravel's `Model` / `GuardsAttributes`, the model can accept keys that are no column, so it is skipped on every path: static, instance, builder, and relation calls.
- **Migration files.** A mass-assignment call inside a migration file is skipped (a file counts as a migration when its name carries the migrator's `YYYY_MM_DD_HHMMSS_` prefix or it sits in a `migrations` directory). The plugin replays every migration into one final schema, so a column a later migration drops is absent from it, and a migration that legitimately wrote that column when it ran would be wrongly flagged against the final state.

## Column lists of query reads

A string literal in the `$columns` argument of an Eloquent read method is checked against the model the query reads:

| Method | `$columns` is |
| --- | --- |
| `get()`, `first()`, `firstOrFail()`, `firstOr()`, `sole()` | the first argument |
| `find()`, `findOrFail()`, `findOrNew()`, `findMany()`, `findSole()`, `findOr()` | the second argument |
| `paginate()`, `simplePaginate()`, `cursorPaginate()` | the second argument |
| `Model::all()` (static call only) | the first argument |

A named `columns:` argument is checked wherever it sits, unless an unpacked argument (`...$args`) precedes it. The argument may be one string or a literal array.

```php
User::query()->findSole(1, ['id', 'nmae']);   // Builder::findSole() selects an unknown column 'nmae'. ... of User
User::findOr(1, ['nmae'], fn () => null);     // User::findOr() selects an unknown column 'nmae'. ... of User
User::where('active', true)->get(['nmae']);   // Builder::get() selects an unknown column 'nmae'. ... of User
```

### Which calls are checked

Whether a name in a column list must be a column of the model's table depends on the whole query, not on the type of the builder: a join, another `FROM`, or an earlier select changes what the name refers to. So a call is checked only when the plugin can see the query from its start:

- **The start** is a static call on the model class: `Post::query()`, a static read (`Post::find(1, [...])`, `Post::all([...])`), or a static call of one of the methods below (`Post::where(...)`). `self::` and `static::` inside the model count as the model class.
- **Between the start and the read** only these methods may appear, because they never change the select list, the `FROM` or the joins:
  - wheres: `where()`, `orWhere()`, `whereNot()`, `orWhereNot()`, `whereKey()`, `whereKeyNot()`, `whereIn()`, `whereNotIn()`, `whereNull()`, `whereNotNull()` (and their `orWhere` forms), `whereBetween()`, `whereNotBetween()`, `whereDate()`, `whereMonth()`, `whereDay()`, `whereYear()`, `whereTime()`, `whereColumn()`, `whereLike()`, `whereNotLike()`;
  - relation existence: `has()`, `doesntHave()`, `whereHas()`, `orWhereHas()`, `whereDoesntHave()`, `orWhereDoesntHave()`, `whereRelation()`, `orWhereRelation()`, `whereBelongsTo()`, `orWhereBelongsTo()`, `whereMorphedTo()`;
  - ordering and paging: `orderBy()`, `orderByDesc()`, `latest()`, `oldest()`, `inRandomOrder()`, `reorder()`, `limit()`, `take()`, `skip()`, `offset()`, `forPage()`;
  - row locks: `lockForUpdate()`, `sharedLock()`;
  - eager loading: `with()`, `without()`, `withOnly()`;
  - scopes: `withTrashed()`, `onlyTrashed()`, `withoutTrashed()`, `withoutGlobalScope()`, `withoutGlobalScopes()`.

Any other call ends the proof and the read is not checked. That covers a builder held in a variable, property or parameter, a relation (`$user->posts()->get([...])`), a model instance (`$post->newQuery()`), a local scope or macro in the chain, `when()`, `unless()`, `tap()`, `toBase()`, and the methods that change what an unqualified name refers to (`join()` and its variants, `from()`, `union()`).

### Recognized set and entries

A column name is matched against the same recognized set as an attribute key, ignoring case and separators, and a JSON path such as `options->theme` is validated by its base column. A name the migrations cannot see is therefore accepted once it is a cast, a `$fillable` entry or a `@property`.

Each entry of the list is judged on its own. These entries are skipped and never flagged:

- `*` and `table.*`;
- a qualified `table.column`, including the model's own table;
- an alias (`title as heading`) and anything else that is not a plain name, such as `count(*)`;
- `DB::raw()` and other expressions, variables, constants and spread items;
- a closure passed in the `$columns` position (`Post::findOr($id, fn () => null)`).

A list with any explicit key (`['nmae', 0 => 'name']`, `[$i => 'name']`) is skipped as a whole: the key may overwrite another entry before the list reaches SQL.

### When a column list stays silent

The **no column schema** and **migration files** guards above apply. On top of them:

- **Dead argument.** Laravel uses `$columns` only when the query has no select list yet. After `select()`, `addSelect()`, `selectRaw()`, `withCount()` or another aggregate load, or on a model whose `$withCount` property is not empty (a list of relation names or a map of count constraints), the argument is ignored at runtime and never flagged.
- **Global scopes.** A global scope may join or select. When the model, a parent class or a trait carries `#[ScopedBy]` (or an attribute extending it), or its source mentions `addGlobalScope`, the model is skipped. A bare `withoutGlobalScopes()` (no arguments) in the chain drops every registered scope and lifts this, but only when no scope class in the analyzed code declares or inherits an `extend()` method: `extend()` runs when the scope is registered, and what it adds to the builder survives the scope's removal. One such scope anywhere (Laravel's own `SoftDeletingScope` excepted, though not a subclass of it) turns the lift off for the whole run. `withoutGlobalScope($scope)` and `withoutGlobalScopes([...])` never lift it, and neither does anything under the language server (`psalm --language-server`). Laravel's own `SoftDeletes` scope only adds a where and does not skip the model.
- **Custom query paths.** The model is skipped when it overrides how its query is built (`query()`, `newQuery()`, `newModelQuery()`, `newQueryWithoutScopes()`, `newQueryWithoutRelationships()`, `registerGlobalScopes()`, `newEloquentBuilder()`, `newBaseQueryBuilder()`, `__call()`, `__callStatic()`), how its global scopes are found (`getGlobalScopes()`, `bootHasGlobalScopes()`, `resolveGlobalScopeAttributes()`), when its builder cannot be resolved, or when it declares a method named like the start, a chained method or the read itself (a settings model with its own static `get($key)`).
- **Local scopes named like a called method.** A local scope runs instead of the query builder method of the same name, so the call is skipped when a scope is named like a chained method (`scopeOrderBy()`) or like a method the read calls internally: `limit()` for `first()`, `firstOrFail()`, `firstOr()`, `sole()` and the `find*()` methods (except `findMany()`), `forPage()` for `paginate()`, `offset()` and `limit()` for `simplePaginate()`, and `limit()`, `orderBy()`, `setBindings()` and `addBinding()` for `cursorPaginate()`.
- **Custom builders.** A custom builder may add methods, but one that redeclares any method of Laravel's `Eloquent\Builder` (`get()`, `first()`, `__construct()`, `applyScopes()`, ...) or declares a method named like a chained method or like a method the read calls internally (see the previous point) skips the model.

## Known limitation

- Relations inherited from a parent class or a trait are not part of a model's own recognized set, so a key naming such an inherited relation may be flagged. Declare it with a `@property` annotation on the model to resolve this.
- A model that widens mass assignment only through `setAttribute()` (a `__set`-style override that accepts extra keys) is not detected and is still checked against its recognized attributes. Suppress the issue at that call site.
- Column lists: a global scope registered outside the model, its parent classes and its traits (a service provider or a middleware calling `Post::addGlobalScope()`) is not seen. If that scope joins another table or sets a select list, a valid name from the joined table can be flagged. Qualify the name (`authors.name`) or declare it with a `@property` annotation.
- Column lists: the `withoutGlobalScopes()` lift only sees scope classes the analysis loaded. A scope with an `extend()` method that the analyzed code never names (registered from a config string, for example) is not seen.
- Column lists: a column a migration adds through raw SQL (`DB::statement('ALTER TABLE posts ADD ...')`) is missing from the replayed schema, so selecting it is flagged. Declare a cast or a `@property` for it.
- Column lists: a builder macro registered under the name of a chained method listed above, or of a method the read calls internally (macros run before the query builder's own method), is trusted like that method. A macro that joins or selects under such a name is not detected.
- Column lists: a model that overrides `query()` is skipped entirely, even when the override only narrows the return type.
- Column lists: `self::`, `static::` and a named parent class (`ParentPost::where(...)` inside a child's instance method) are checked against the class the code names. When a child model with another table runs that code, Laravel queries the child's table, so a column only the child's table has can be flagged. The attribute map check shares this.
- Column lists: a model that registers a dynamic relation named like a chained method or a static read (`resolveRelationUsing('latest', ...)`) turns `Post::latest()` into a relation query. That registration is not detected.
- Column lists: a typo stays reported when the read never runs SQL, such as `findMany([], [...])` or `paginate(15, [...], total: 0)`. The list is still wrong once the input changes.
