---
title: UndefinedModelRelation
parent: Custom Issues
nav_order: 11
---

# UndefinedModelRelation

A relation name passed to an eager-loading or relationship-query method does not resolve to a method on the model. See [Reporting level](#reporting-level).

## Why it matters

Passing a non-existent relation name is one of the most common sources of runtime errors in Laravel apps. A typo such as `User::with('pots')` (for `posts`) is silently accepted by Psalm, then throws `RelationNotFoundException` at runtime. This rule reports it during analysis.

## What is checked

The relation name in the first argument of these methods is validated against the resolved model:

* Eager loading: `with()`, `without()`.
* Existence queries: `has()`, `orHas()`, `doesntHave()`, `orDoesntHave()`, `whereHas()`, `orWhereHas()`, `whereDoesntHave()`, `orWhereDoesntHave()`, `withWhereHas()`, `whereRelation()`, `orWhereRelation()`, `withWhereRelation()`, `whereDoesntHaveRelation()`, `orWhereDoesntHaveRelation()`, `whereMorphRelation()`, `orWhereMorphRelation()`.
* Lazy eager loading: `load()`, `loadMissing()`, `loadCount()`, `loadSum()`, `loadAvg()`, `loadMax()`, `loadMin()`, `loadExists()`.

The model is resolved from the receiver: a `Builder<TModel>` or `Relation<TModel, ...>` generic parameter, a `Model` instance, or the class of a static call (`User::with(...)`).

Supported name syntaxes:

* String: `with('posts')`.
* Dot-notation: `with('posts.comments.author')`, where each segment is resolved against the previous segment's related model.
* Array (list): `with(['posts', 'comments'])`.
* Array (keyed closure): `with(['posts' => fn ($query) => $query])`, where the key is the relation name.
* Select columns: `with('posts:id,title')`, where the `:columns` part is stripped before checking.

## Example

```php
class User extends Model
{
    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}

User::with('posts');      // OK
User::with('postz');      // UndefinedModelRelation: Relation 'postz' is not defined on App\Models\User.
$user->load('comments');  // UndefinedModelRelation: Relation 'comments' is not defined on App\Models\User.
```

## How to fix

Correct the relation name, or define the missing relationship method on the model.

## Known limitations

To keep false positives near zero, the rule reports only when no method (real or `@method`) with that name exists, and defers (stays silent) when it cannot resolve the target with confidence:

* Dynamic relation names (a variable rather than a literal).
* An un-narrowed `Builder` (the bare base `Model`) or an abstract base model.
* Relations registered at runtime via `Model::resolveRelationUsing()` or package macros, which static analysis cannot see.
* Deeper dot-notation segments after a polymorphic `morphTo`, whose related model is not statically known.

The `withCount()` / `withSum()` aggregate family is not covered by this first pass.

## Reporting level

The handler always runs. Without an explicit issue handler, it is reported as `info` by default. Promote that default to an error in `psalm.xml`:

```xml
<pluginClass class="Psalm\LaravelPlugin\Plugin">
    <experimental value="true" />
</pluginClass>
```

Any explicit `<PluginIssue>` entry owns this issue's complete reporting policy. The plugin leaves both its base level and scoped filters unchanged, regardless of `<experimental>`. When using scoped filters, specify the desired base level explicitly:

```xml
<issueHandlers>
    <PluginIssue name="UndefinedModelRelation" errorLevel="info">
        <errorLevel type="suppress">
            <directory name="legacy" />
        </errorLevel>
    </PluginIssue>
</issueHandlers>
```

Without the outer `errorLevel="info"`, Psalm uses its normal implicit fallback of `error` outside the scoped filter.
