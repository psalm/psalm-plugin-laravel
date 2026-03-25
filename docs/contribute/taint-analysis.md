---
title: Taint Analysis Stubs
parent: Contributing
nav_order: 5
---

# Taint Analysis Stubs

This guide covers how to write and review taint analysis stubs for psalm-plugin-laravel.

For Psalm's upstream taint analysis documentation, see:
- [Security Analysis overview](https://psalm.dev/docs/security_analysis/) -- how taint sources, sinks, and types work
- [Taint annotations reference](https://psalm.dev/docs/security_analysis/annotations/) -- `@psalm-taint-source`, `@psalm-taint-sink`, `@psalm-taint-escape`, `@psalm-taint-unescape`, `@psalm-taint-specialize`, `@psalm-flow`
- [Avoiding false positives](https://psalm.dev/docs/security_analysis/avoiding_false_positives/) -- `@psalm-taint-escape`, `@psalm-taint-specialize`, ignoring files
- [Avoiding false negatives](https://psalm.dev/docs/security_analysis/avoiding_false_negatives/) -- `@psalm-taint-unescape`
- [Custom taint sources](https://psalm.dev/docs/security_analysis/custom_taint_sources/) -- `@psalm-taint-source` annotation and plugin API
- [Custom taint sinks](https://psalm.dev/docs/security_analysis/custom_taint_sinks/) -- `@psalm-taint-sink` annotation
- [Taint flow](https://psalm.dev/docs/security_analysis/taint_flow/) -- `@psalm-flow` proxy and return hints

## Stub location

Taint annotations live in `stubs/common/` alongside type stubs, organized by Laravel namespace. Psalm 7 runs taint analysis by default (`$run_taint_analysis = true`), so there is no need for a separate directory.

## Annotations quick reference

There are six taint-related annotations. The first four are the ones you'll use most in stubs:

| Annotation                          | Purpose                                              | Needs `@psalm-flow`?                                                                                |
|-------------------------------------|------------------------------------------------------|-----------------------------------------------------------------------------------------------------|
| `@psalm-taint-source <kind>`        | Marks return value as producing tainted data         | No -- sources create new taint                                                                      |
| `@psalm-taint-sink <kind> <$param>` | Marks a parameter as dangerous if tainted            | No -- sinks are endpoints                                                                           |
| `@psalm-taint-escape <kind>`        | Removes a specific taint kind from the return value  | **Yes** -- see [critical rule](#critical-rule-always-pair-psalm-taint-escape-with-psalm-flow) below |
| `@psalm-flow (<$params>) -> return` | Declares that taint propagates from params to return | N/A -- this IS the flow declaration                                                                 |
| `@psalm-taint-unescape <kind>`      | Re-adds a taint kind (reverses an earlier escape)    | Yes -- same pattern as escape                                                                       |
| `@psalm-taint-specialize`           | Tracks taints per call-site instead of globally      | No                                                                                                  |

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

The same rule applies to `@psalm-taint-unescape` -- always pair it with `@psalm-flow`.

Psalm's own stubs follow this pattern -- see `htmlspecialchars()` in `vendor/vimeo/psalm/stubs/Php74.phpstub` and `urlencode()`/`strip_tags()` in `vendor/vimeo/psalm/stubs/CoreGenericFunctions.phpstub`.

### When `@psalm-flow` is NOT needed

**Sinks** don't need `@psalm-flow` because they are endpoints -- they consume tainted data, they don't produce output:

```php
/**
 * @psalm-taint-sink sql $query
 */
public function unprepared($query) {}
```

**Sources** don't need `@psalm-flow` because they create new taint on the return value, not flow from input:

```php
/**
 * @psalm-taint-source input
 */
public function input($key = null, $default = null) {}
```

**Exception -- sink-only escapes**: If a function's return value is never used for taint-sensitive operations (e.g., `Hash::make()` returns a hash that's safe by nature), `@psalm-taint-escape` without `@psalm-flow` is acceptable because there's no meaningful taint to preserve on the return value.

## Taint kinds

All taint kind names are defined in [`Psalm\Type\TaintKind::TAINT_NAMES`](https://github.com/vimeo/psalm/blob/master/src/Psalm/Type/TaintKind.php). These are the strings you use in annotations.

### Common kinds used in stubs

| Kind            | Attack vector                             | Example sink                                  | Example escape                                |
|-----------------|-------------------------------------------|-----------------------------------------------|-----------------------------------------------|
| `html`          | XSS via HTML injection                    | `echo`, `Response::make()`                    | `e()`, `htmlspecialchars()`                   |
| `has_quotes`    | Attribute injection via unquoted strings  | `echo` inside HTML attributes                 | `e()`, `urlencode()`                          |
| `sql`           | SQL injection                             | `Connection::unprepared()`                    | `Connection::escape()`, parameterized queries |
| `shell`         | Command injection                         | `Process::run()`                              | `escapeshellarg()`                            |
| `ssrf`          | Server-side request forgery               | `Http::get($url)`                             | --                                            |
| `file`          | Path traversal                            | `Filesystem::get()`, `response()->download()` | --                                            |
| `user_secret`   | Password/token exposure in logs or output | `echo`, log sinks                             | `Hash::make()`, `Encrypter::encrypt()`        |
| `system_secret` | Internal secret exposure                  | `echo`, log sinks                             | `Encrypter::encrypt()`                        |

### All available kinds

| Kind            | Constant            | Description                                                |
|-----------------|---------------------|------------------------------------------------------------|
| `callable`      | `INPUT_CALLABLE`    | User-controlled callable strings                           |
| `unserialize`   | `INPUT_UNSERIALIZE` | Strings passed to `unserialize()`                          |
| `include`       | `INPUT_INCLUDE`     | Paths passed to `include`/`require`                        |
| `eval`          | `INPUT_EVAL`        | Strings passed to `eval()`                                 |
| `ldap`          | `INPUT_LDAP`        | LDAP DN or filter strings                                  |
| `sql`           | `INPUT_SQL`         | SQL query strings                                          |
| `html`          | `INPUT_HTML`        | Strings that could contain HTML/JS                         |
| `has_quotes`    | `INPUT_HAS_QUOTES`  | Strings with unescaped quotes                              |
| `shell`         | `INPUT_SHELL`       | Shell command strings                                      |
| `ssrf`          | `INPUT_SSRF`        | URLs passed to HTTP clients                                |
| `file`          | `INPUT_FILE`        | Filesystem paths                                           |
| `cookie`        | `INPUT_COOKIE`      | HTTP cookie values                                         |
| `header`        | `INPUT_HEADER`      | HTTP header values                                         |
| `xpath`         | `INPUT_XPATH`       | XPath query strings                                        |
| `sleep`         | `INPUT_SLEEP`       | Values passed to `sleep()` (DoS)                           |
| `extract`       | `INPUT_EXTRACT`     | Values passed to `extract()`                               |
| `user_secret`   | `USER_SECRET`       | User-supplied secrets (passwords, tokens)                  |
| `system_secret` | `SYSTEM_SECRET`     | System secrets (API keys, encryption keys)                 |
| `input`         | `ALL_INPUT`         | Alias: all input-related kinds combined (excludes secrets) |
| `tainted`       | `ALL_INPUT`         | Alias: same as `input`                                     |

## Stub patterns by annotation type

### Source stubs

Mark methods that return user-controlled data. In Laravel, the primary sources are on `Request`:

```php
/**
 * @psalm-taint-source input
 */
public function input($key = null, $default = null) {}
```

### Sink stubs

Mark parameters where tainted data is dangerous. Always specify **which parameter** receives tainted data:

```php
/**
 * @psalm-taint-sink sql $query
 */
public function select($query, $bindings = [], $useReadPdo = true) {}
```

Multiple parameters can be sinks:

```php
/**
 * @psalm-taint-sink html $callback
 * @psalm-taint-sink html $data
 */
public function jsonp($callback, $data = []) {}
```

### Escape stubs (with flow)

Mark functions that sanitize specific taint kinds. **Always pair with `@psalm-flow`**:

```php
/**
 * @psalm-taint-escape html
 * @psalm-taint-escape has_quotes
 * @psalm-flow ($value) -> return
 */
function e($value, $doubleEncode = true) {}
```

### Unescape stubs (with flow)

Mark functions that reverse sanitization, re-introducing taint. Used for decrypt, decode, etc.:

```php
/**
 * @psalm-taint-unescape user_secret
 * @psalm-taint-unescape system_secret
 * @psalm-flow ($payload) -> return
 */
public function decrypt($payload, $unserialize = true) {}
```

### Flow-only stubs

When a function passes taint through without escaping or sinking, use `@psalm-flow` alone. This is useful for wrapper functions where Psalm can't automatically trace the data flow:

```php
/**
 * @psalm-flow ($value, $items) -> return
 */
function inputOutputHandler(string $value, string ...$items): string {}
```

## Stub authoring checklist

1. **Verify the function's actual behavior** against Laravel source in `vendor/laravel/framework/`
2. **Choose the correct annotation type**: source, sink, escape, or flow
3. **If using `@psalm-taint-escape` or `@psalm-taint-unescape`**: always add `@psalm-flow` to preserve other taint kinds (unless the return value's other taints are truly irrelevant)
4. **Match parameter types exactly** to Laravel's signatures -- do not narrow types
5. **Place in `stubs/common/`** under a path matching the Laravel namespace

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

**Tip**: Use `--dump-taint-graph=taints.dot` to visualize taint flow and debug unexpected results. See [Debugging the taint graph](https://psalm.dev/docs/security_analysis/#debugging-the-taint-graph).

### Known limitation: Facade static calls

Facade static calls (`DB::unprepared(...)`) may not propagate taint because `__callStatic` loses taint context. The generated alias stubs (`class X extends Y {}`) don't carry taint annotations. Calling the underlying class directly (`DB::connection()->unprepared(...)`) works correctly.
