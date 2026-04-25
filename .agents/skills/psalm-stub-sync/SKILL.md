---
name: psalm-stub-sync
description: Sync stub type annotations with Larastan and Laravel source. Fetches latest Larastan stubs and Laravel framework source, compares them against psalm-plugin-laravel stubs method by method, and borrows more precise types where Larastan has better narrowing. All changes are validated against Laravel's actual source code. Produces a change report table with confidence levels. Use this skill when the user says "sync stubs", "borrow from larastan", "update stubs from larastan", "compare stubs", "stub sync", or wants to improve stub type precision by cross-referencing Larastan and Laravel source.
argument-hint: "[scope] - optional file, directory, or class name to sync (defaults to all shared stubs)"
user-invocable: true
effort: max
---

# Stub Sync: Borrow Best Types from Larastan

Cross-reference Larastan stubs, psalm-plugin-laravel stubs, and Laravel's actual source code to find type annotation improvements. Apply safe improvements and produce a detailed change report.

## Source Locations

- **psalm-plugin-laravel stubs**: `stubs/common/` (and version dirs `stubs/12/`, `stubs/13/`)
- **Larastan stubs**: `.alies/larastan/stubs/` (pre-cloned reference copy)
- **Laravel source**: `vendor/laravel/framework/src/Illuminate/`

If Larastan or Laravel source is missing or outdated, fetch fresh copies:

```bash
# Larastan — clone into .alies/ if missing
[ -d .alies/larastan ] || git clone --depth=1 https://github.com/larastan/larastan.git .alies/larastan

# Laravel — should be in vendor, but verify it exists
[ -d vendor/laravel/framework ] || composer install
```

## Scope

If `$ARGUMENTS` names a file, directory, or class — sync only that. Otherwise, sync all stub files that have a counterpart in Larastan.

## Workflow

### Phase 1: Map Shared Coverage

Build a mapping of which Laravel classes have stubs in BOTH projects. Larastan uses `.stub` extension with a flat-ish layout; psalm-plugin-laravel uses `.stubphp` mirroring the Illuminate namespace.

For each shared class, extract every method stub from both projects. A "method stub" is a method signature with its PHPDoc annotations.

### Phase 2: Compare Method-by-Method

For each method that appears in both stub sets, compare:

1. **Return types** — Is Larastan's return type more precise (narrower)?
2. **Parameter types** — Is Larastan's param type different? Stricter param types are acceptable when they catch genuine bugs (e.g., `positive-int` for chunk sizes). Verify against Laravel source.
3. **Template annotations** — Does Larastan add `@template` generics that psalm-plugin-laravel lacks?
4. **Conditional returns** — Does Larastan use conditional return types (`$x is Y ? A : B`) that psalm-plugin-laravel lacks?
5. **Missing methods** — Does Larastan stub methods that psalm-plugin-laravel doesn't?
6. **Redundant stubs** — Does a psalm-plugin-laravel stub method duplicate what Laravel's own PHPDoc already provides? If Laravel source has accurate `@template`, `@param`, and `@return` annotations that Psalm can parse natively, the stub method is dead weight and should be removed.

### Phase 3: Validate Against Laravel Source

For every potential improvement found in Phase 2, validate it against Laravel's actual implementation:

1. Read the corresponding method in `vendor/laravel/framework/src/Illuminate/`
2. Check Laravel's own PHPDoc and PHP type declarations
3. Verify the Larastan type is actually correct (Larastan has bugs too)
4. For conditional return types, verify the branching logic in the source code

### Phase 4: Classify and Filter

Assign a confidence level to each potential change:

| Confidence | Meaning | Action |
|-----------|---------|--------|
| **high** (90-100%) | Larastan type matches Laravel source exactly, clearly better than current stub | Apply automatically |
| **medium** (70-89%) | Larastan type is likely correct but needs judgment (e.g., complex conditionals) | Apply with note |
| **low** (< 70%) | Larastan type may be wrong, uses PHPStan-only features, or conflicts with Psalm semantics | Skip, report only |

**Always skip (do not apply):**
- `model-property<T>` — PHPStan custom type, no Psalm equivalent
- `view-string` — PHPStan custom type, no Psalm equivalent (use non-mepty-string)
- `__benevolent<T>` — PHPStan "lenient union" that suppresses type mismatch errors. No Psalm equivalent. Translate by using the plain union type inside (e.g., `__benevolent<string|array|null>` → `string|array|null`). Psalm will be stricter, which is acceptable.
- Any change where a stricter `@param` type would cause false positives for common, valid usage patterns without catching real bugs

**Translate when possible:**
- `@phpstan-return` → `@psalm-return`
- `@phpstan-param` → `@psalm-param`
- `@phpstan-this-out` / `@phpstan-self-out` → `@psalm-this-out` / `@psalm-self-out` — Psalm fully supports these annotations (they are semantically equivalent). Adopt `@phpstan-this-out` from Larastan by renaming to `@psalm-this-out`.
- `collection-of<T>` — Larastan custom type that resolves to the model's custom collection class (e.g., `UserCollection<int, User>`) or falls back to `Eloquent\Collection<int, T>`. Our plugin already handles custom collection resolution via return type provider handlers, so translate `collection-of<T>` to `\Illuminate\Database\Eloquent\Collection<int, T>` in stubs — the handler will override with the correct custom collection at analysis time.
- PHPStan `@return` conditionals work in Psalm too — adopt them directly

### Phase 5: Apply Changes

For each high/medium confidence change, edit the stub file. Preserve:
- Existing `@psalm-taint-*` annotations (Larastan has no taint analysis)
- Existing `@psalm-mutation-free` annotations
- Existing `@psalm-assert-*` annotations
- Any Psalm-specific annotations not present in Larastan

**Removing redundant stubs:** If a stub method only restates what Laravel's own PHPDoc already declares (same `@template`, `@param`, `@return` types), remove the method from the stub. This reduces maintenance burden — Laravel updates its PHPDoc, Psalm reads it directly, no stub drift. Only remove when ALL of:
- Laravel's PHPDoc has complete type annotations (not just `@param mixed` or `@return mixed`)
- The stub adds no Psalm-specific annotations (`@psalm-taint-*`, `@psalm-assert-*`, `@psalm-mutation-free`, `@psalm-return` conditionals)
- The stub adds no type narrowing beyond what Laravel declares
- Removing it doesn't change Psalm's inferred types (verify by running tests)

### Phase 6: Test

Run the test suite to verify no regressions:

```bash
composer test:unit && composer test:type
```

If tests fail, revert the failing change and downgrade its confidence to "low".

### Phase 7: Report

Output a change report table. This is the primary deliverable.

## Report Format

```markdown
## Stub Sync Report

**Scope:** [what was synced]
**Larastan version:** [commit hash or tag]
**Laravel version:** [version from composer.lock]
**Changes applied:** X high, Y medium
**Changes skipped:** Z low

### Changes Applied

| Class::method / function | Confidence | Before (psalm-plugin) | After | Larastan type | Laravel PHPDoc | Laravel source type |
|--------------------------|------------|----------------------|-------|---------------|----------------|---------------------|
| `Builder::findOrNew()` | high (95%) | `TModel` | `(T is array ? Collection<int,TModel> : TModel)` | `(T is array ? collection-of<TModel> : TModel)` | `@return TModel\|Collection` | Conditional: array→Collection, scalar→TModel |
| `Query\Builder::sum()` | high (90%) | `int\|float` | `numeric-string\|int\|float` | `numeric-string\|float\|int` | `@return mixed` | DB returns numeric-string for aggregates |

### Stubs Removed (redundant with Laravel PHPDoc)

| Class::method / function | Confidence | Stub had | Laravel PHPDoc has | Reason |
|--------------------------|------------|----------|-------------------|--------|
| `Collection::merge()` | high (95%) | `@return static` | `@return static<TKey, TValue\|TMergeValue>` | Laravel PHPDoc is more precise, stub was less specific |

### Changes Skipped

| Class::method / function | Reason | Larastan type | Note |
|--------------------------|--------|---------------|------|
| `Builder::create()` | PHPStan-only `model-property<T>` in param | `array<model-property<TModel>, mixed>` | No Psalm equivalent |

### Tests
- Unit: [pass/fail count]
- Type: [pass/fail count]
```

## Key Principles

1. **Return types can be narrowed freely** — more precise return types improve downstream inference.
2. **Parameter types can be stricter when it catches genuine bugs** — e.g., `positive-int` for chunk sizes is fine. Avoid stricter params only when they'd cause false positives for common, valid usage patterns without catching real bugs.
3. **Preserve Psalm-specific annotations** — taint sources/sinks, mutation-free, assertions. Larastan doesn't have these.
4. **PHPStan-only types are not adoptable** — `model-property<T>`, `view-string`, `__benevolent<T>` have no direct Psalm equivalent. However, `collection-of<T>` can be translated to `Collection<int, T>` (handlers refine further), and `@phpstan-this-out` can be translated to `@psalm-this-out`.
5. **Validate everything against Laravel source** — Larastan stubs can have bugs. The Laravel source is the ground truth.
6. **Test after applying** — run the test suite. If a change breaks tests, revert it.
7. **Confidence reflects risk** — high means "obviously correct", medium means "probably correct but uses complex type logic", low means "uncertain or incompatible".