# Performance: Model Analysis Memory and Time Explosion

## Problem

When analyzing large Laravel projects, Psalm with psalm-plugin-laravel consumes **13-15 GB per worker thread** and individual model files take **30+ seconds** to analyze. With `--threads=16`, total memory can exceed 200 GB (with `memory_limit=-1`).

Affected file types: Eloquent models, query builder classes, scope classes.

Observed on: plugin 3.x with Laravel 11, large project (~14,000 files, 5,900 analyzed).

Screenshot context: 16 PHP workers each at 13-15 GB, files like `Member.php`, `Article.php`, `Tag.php`, `Team.php`, `PaymentSlipScope.php` all stuck at 30+ seconds.

## Root Cause Analysis

### 1. ProxyMethodReturnTypeProvider: Fake Call Execution (Critical)

**File:** `src/Util/ProxyMethodReturnTypeProvider.php`

`executeFakeCall()` is the single biggest performance bottleneck. For every method call that gets proxied from a Model or Relation to Builder, it:

1. **Clones `node_data`** — Psalm's full statement analysis context (can be megabytes)
2. **Clones `Context`** — the type context for the current scope
3. **Runs `MethodCallAnalyzer::analyze()`** — a full re-analysis of the faked method call
4. **Reads back the inferred type** from the cloned node data

This is triggered by:
- `ModelMethodHandler` — every `Model::__callStatic()` forwarded to Builder (e.g., `User::where(...)`)
- `RelationsMethodHandler` — every method call on a Relation forwarded to Builder (e.g., `$this->posts()->where(...)`)

**Scale:** A model with 20 relationships, each with 3-5 chained builder calls in the codebase, triggers 60-100 fake call executions. Each clones large objects and runs full method analysis. This is O(n × m) where n = proxy calls and m = cost per analysis.

### 2. Uncached Property Handler Lookups (High)

**Files:** `ModelRelationshipPropertyHandler.php`, `ModelPropertyAccessorHandler.php`

Every property access on any model triggers these handlers, which call:
- `codebase->methodExists()` — checks if a relationship method exists
- `codebase->getMethodReturnType()` — resolves the full return type

These are expensive Psalm API calls with no caching. The same property may be accessed dozens of times across a file (in different methods), and each access repeats the full lookup.

### 3. Overlapping Handler Registrations (Medium)

**File:** `src/Plugin.php` lines 176-191

Four separate handlers all register for the same model classes and fire on every property access:
1. `ModelRelationshipPropertyHandler` — PropertyExistence + Visibility + Type (3 events)
2. `ModelPropertyAccessorHandler` — PropertyExistence + Visibility + Type (3 events)
3. `ModelFactoryTypeProvider` — PropertyType
4. `ModelPropertyHandler` — PropertyExistence + Visibility + Type (3 events)

For a single `$model->name` access, up to 4 handlers fire sequentially, each doing expensive lookups, even though typically only one is relevant.

### 4. Large Builder Stub with Complex Generics (Medium)

**File:** `stubs/common/Database/Eloquent/Builder.stubphp` (626 lines)

The Builder stub uses `@template TModel` extensively. Every proxied method call through `ProxyMethodReturnTypeProvider` must resolve these generic templates, which is expensive. The stub declares ~100 methods with generic return types.

## Compound Effect

These issues multiply:

```
Per model file analysis:
  Properties accessed: ~50
  × Handlers per property: 4
  × Lookups per handler: 2-3 (methodExists + getMethodReturnType)
  = ~400-600 uncached codebase lookups

  Builder method calls (proxied): ~30-100
  × Cost per proxy: clone(node_data) + clone(Context) + MethodCallAnalyzer::analyze()
  = 30-100 full method re-analyses with large object cloning
```

For a complex model with many relationships, scopes, and builder usage across the codebase, this easily explains 15 GB memory and 30+ second analysis time.

## Optimization Plan

### Priority 1: Cache property handler results

**Effort: Low. Impact: High.**

Add a simple static cache (`array<string, Type\Union|null>`) keyed by `"{$fqcn}::{$property}"` to `ModelRelationshipPropertyHandler` and `ModelPropertyAccessorHandler`. Same property on same class always returns the same type — no reason to recompute.

```php
private static array $cache = [];

public static function getPropertyType(...): ?Type\Union
{
    $key = "{$fq_classlike_name}::{$property_name}";
    if (array_key_exists($key, self::$cache)) {
        return self::$cache[$key];
    }
    // ... existing logic ...
    self::$cache[$key] = $result;
    return $result;
}
```

### Priority 2: Short-circuit handler chain

**Effort: Low. Impact: Medium.**

In Plugin's handler registration, document that handler order matters and the first non-null result wins (Psalm's behavior). Ensure the most common cases (relationship properties, schema properties) are checked first, and less common cases (accessors, factory) are checked later.

Currently the registration order is:
1. ModelRelationshipPropertyHandler
2. ModelFactoryTypeProvider
3. ModelPropertyAccessorHandler
4. ModelPropertyHandler

This is already reasonable — relationships are the most common dynamic properties.

### Priority 3: Reduce fake call overhead in ProxyMethodReturnTypeProvider

**Effort: High. Impact: Critical.**

Options:
1. **Cache proxy results** — same class + method + argument types = same return type. Cache it.
2. **Avoid cloning when possible** — investigate if a lighter-weight type resolution path exists (e.g., just calling `codebase->getMethodReturnType()` on the target class directly instead of running full analysis)
3. **Use Psalm's method return type directly** — for many Builder methods, the return type is known statically from the stub. Only use fake call analysis for methods with complex generic resolution.

### Priority 4: Reduce Builder stub complexity

**Effort: Medium. Impact: Medium.**

Review the Builder stub for methods that don't need full generic type parameters. Many Builder methods return `$this` or `static` — these can use simpler return types that don't require template resolution.

## Measurement

To measure the impact of each optimization, use Psalm's `--debug` flag which logs per-file timing:

```bash
psalm --no-cache --debug 2>&1 | grep "Processing.*taking"
```

Before/after comparison on a project with large models will show the improvement.

## Related

- Psalm issue: https://github.com/vimeo/psalm/issues/11683 (CLI `--show-snippet` bug, separate issue)
- Plugin version affected: 3.x (confirmed), likely 4.x as well (same handler architecture)
- The facade replacement (PR #427) does NOT affect this — it's about plugin boot, not analysis-time handlers
