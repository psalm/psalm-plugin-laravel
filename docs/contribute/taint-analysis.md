---
title: Taint Analysis Stubs
parent: Contributing
nav_order: 5
---

# Taint Analysis Stubs

This guide covers how to write and review taint analysis stubs for psalm-plugin-laravel.

## Stub location

Taint stubs live in `stubs/taintAnalysis/`, organized by Laravel namespace:

```
stubs/taintAnalysis/
  Console/
  Database/
  Encryption/
  Filesystem/
  Hashing/
  Http/
  Process/
  Routing/
  Support/
```

They are loaded separately from `stubs/common/` to avoid redeclaration conflicts.

## Annotation types

| Annotation | Purpose | Example |
|---|---|---|
| `@psalm-taint-source` | Marks a method as producing tainted data | `Request::input()` |
| `@psalm-taint-sink` | Marks a parameter as dangerous if tainted | `Connection::unprepared($query)` |
| `@psalm-taint-escape` | Marks a function as removing a specific taint kind | `e()` escapes `html` |
| `@psalm-flow` | Declares how taint propagates from params to return | `($value) -> return` |

## Critical rule: always pair `@psalm-taint-escape` with `@psalm-flow`

`@psalm-taint-escape` alone makes the return value **fully untainted** -- it drops ALL taint kinds, not just the one specified. This creates dangerous false negatives.

To remove only specific taint kinds while preserving others, you **must** add `@psalm-flow`:

```php
// WRONG: drops ALL taints (html, sql, shell, etc.)
// e($userInput) used in a SQL query would NOT trigger TaintedSql
/**
 * @psalm-taint-escape html
 * @psalm-taint-escape has_quotes
 */
function e($value, $doubleEncode = true) {}

// CORRECT: drops only html + has_quotes, other taints flow through
// e($userInput) used in a SQL query WILL trigger TaintedSql
/**
 * @psalm-taint-escape html
 * @psalm-taint-escape has_quotes
 * @psalm-flow ($value) -> return
 */
function e($value, $doubleEncode = true) {}
```

Psalm's own stubs follow this pattern -- see `urlencode()` and `strip_tags()` in `vendor/vimeo/psalm/stubs/CoreGenericFunctions.phpstub`.

### When `@psalm-flow` is NOT needed

`@psalm-taint-sink` does not need `@psalm-flow` because sinks are endpoints -- they consume taint, they don't produce output:

```php
// Correct: sink only, no flow needed
/**
 * @psalm-taint-sink sql $query
 */
public function unprepared($query) {}
```

`@psalm-taint-source` does not need `@psalm-flow` because sources create new taint on the return value:

```php
// Correct: source only, no flow needed
/**
 * @psalm-taint-source input
 */
public function input($key = null, $default = null) {}
```

## Taint kinds

Common taint kinds used in the plugin (defined in `Psalm\Type\TaintKind`):

| Kind | Attack vector | Escape function |
|---|---|---|
| `html` | XSS via HTML injection | `e()`, `htmlspecialchars()` |
| `has_quotes` | Attribute injection via unescaped quotes | `e()`, `urlencode()` |
| `sql` | SQL injection | `Connection::escape()`, parameterized queries |
| `shell` | Command injection | `escapeshellarg()` |
| `input` | General user input | (base kind for all user data) |
| `user_secret` | Password/token exposure | `Hash::make()`, `encrypt()` |
| `system_secret` | Internal secret exposure | `encrypt()` |

## Stub authoring checklist

1. **Verify the function's actual behavior** against Laravel source in `vendor/laravel/framework/`
2. **Choose the correct annotation type**: source, sink, escape, or flow
3. **If using `@psalm-taint-escape`**: always add `@psalm-flow` to preserve other taint kinds
4. **Match parameter types exactly** to Laravel's signatures -- do not narrow types
5. **Place in `stubs/taintAnalysis/`** if the class already has a stub in `stubs/common/`
6. **Add a cross-reference comment** in the common stub pointing to the taint stub

## Testing taint stubs

The project's own `psalm.xml` cannot test taint stubs (the plugin can't analyze itself). Create a separate test project:

```bash
mkdir -p /tmp/taint-test/app
cat > /tmp/taint-test/psalm.xml << 'XMLEOF'
<?xml version="1.0"?>
<psalm errorLevel="1" findUnusedCode="false"
    xmlns="https://getpsalm.org/schema/config">
    <projectFiles><directory name="app" /></projectFiles>
    <plugins><pluginClass class="Psalm\LaravelPlugin\Plugin"/></plugins>
</psalm>
XMLEOF

# Write test PHP in /tmp/taint-test/app/Test.php, then:
cd /tmp/taint-test && /path/to/vendor/bin/psalm --taint-analysis --no-cache
```

### Known limitation: Facade static calls

Facade static calls (`DB::unprepared(...)`) may not propagate taint because `__callStatic` loses taint context. The generated alias stubs (`class X extends Y {}`) don't carry taint annotations. Calling the underlying class directly (`DB::connection()->unprepared(...)`) works correctly.
