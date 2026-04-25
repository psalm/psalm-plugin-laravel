---
name: psalm-taint
description: Deep inspection of Psalm taint annotations in Laravel stub files. Validates @psalm-taint-source, @psalm-taint-sink, @psalm-taint-escape, and @psalm-taint-unescape annotations against actual Laravel source code to prevent false positives and missed vulnerabilities.
argument-hint: "[file-or-method] - optional stub file path or method name to inspect"
effort: max
---

# Taint Annotation Deep Inspection

You are validating Psalm taint analysis annotations in the psalm-plugin-laravel project.
Your goal is to verify each annotation against the actual Laravel source code to ensure correctness and prevent false positives.
Ultrathink tasks.

## What to Inspect

If `$ARGUMENTS` is provided, inspect the specified file or method.
Otherwise, inspect ALL uncommitted taint annotations by running `git diff` and checking untracked files under `stubs/`.

## Psalm Taint Analysis Reference

### How Taint Analysis Works

Psalm's `--taint-analysis` flag tracks user-controlled input from **taint sources** to **taint sinks**. It detects when unescaped user-controlled data reaches sensitive operations. Default sources are `$_GET`, `$_POST`, `$_COOKIE`. Default sinks include `echo`, `include`, `header`, and database query functions.

### Taint Type Groups

The special type `input` is a **group alias** that expands to ALL input-related taint types (`sql`, `html`, `has_quotes`, `shell`, `callable`, `unserialize`, `include`, `eval`, `ssrf`, `file`, `cookie`, `header`, `ldap`).
When you write `@psalm-taint-source input`, the return value carries ALL of these taint types simultaneously.
The types `user_secret` and `system_secret` are NOT part of the `input` group — they must be specified independently.

### All Recognized Taint Types

| Type            | Purpose                                    |
|-----------------|--------------------------------------------|
| `sql`           | SQL injection prevention                   |
| `ldap`          | LDAP filter/DN attacks                     |
| `html`          | HTML injection via angle brackets (XSS)    |
| `has_quotes`    | Unquoted string injection                  |
| `shell`         | Shell command injection                    |
| `callable`      | User-controlled callable strings           |
| `unserialize`   | Unsafe deserialization                     |
| `include`       | File inclusion attacks                     |
| `eval`          | Code evaluation injection                  |
| `ssrf`          | Server-side request forgery                |
| `file`          | File path traversal                        |
| `cookie`        | Cookie manipulation                        |
| `header`        | HTTP header injection                      |
| `user_secret`   | User-supplied secrets (passwords, tokens)  |
| `system_secret` | System secrets (API keys, encryption keys) |

Custom taint types can also be defined.

### Annotation Reference

**`@psalm-taint-source <taint-type>`** — Marks a function/method return value as introducing tainted data. The return value carries the specified taint type. Use `input` to mark as all input taint types at once.

**`@psalm-taint-sink <taint-type> <param-name>`** — Marks a parameter as a dangerous destination. If tainted data matching `<taint-type>` reaches `<param-name>`, Psalm reports a vulnerability.

**`@psalm-taint-escape <taint-type>`** — Marks a function as sanitizing data, removing the specified taint type from the return value. Can be conditional:
```php
/**
 * @psalm-taint-escape ($escape is true ? 'html' : null)
 */
function processVar(string $str, bool $escape = true): string {}
// processVar($_GET['x'], false) → still tainted
// processVar($_GET['x'], true)  → html taint removed
```

**`@psalm-taint-unescape <taint-type>`** — Marks a function as reversing sanitization, re-introducing the specified taint type. Used for decode/unescape operations that undo prior escaping (e.g., HTML entity decoding re-introduces `html` taint).

**`@psalm-taint-specialize`** — Treats each function invocation independently for taint tracking. Prevents taint from one call site leaking to another. Functions marked `@psalm-pure` are automatically specialized. Can also be applied to classes with `@psalm-immutable`. Without this, if ANY call site passes tainted data, ALL call sites are treated as tainted.

**`@psalm-flow`** — Defines explicit data flow paths for taint propagation:
- **Proxy hint:** `@psalm-flow proxy exec($value)` — states that calling this function is equivalent to calling `exec($value)` from a taint perspective. Psalm checks the proxy function's sinks.
- **Return hint:** `@psalm-flow ($value, $items) -> return` — specified params flow into return value. Essential when used with `@psalm-taint-escape` to preserve non-escaped taint types.
- **Combined:** multiple `@psalm-flow` annotations can coexist on one function.

### Plugin API for Custom Taints

Since this project IS a Psalm plugin, we can also use the programmatic API for taint analysis:
- **`AddTaintsInterface`** — Plugin event handler that adds taint types to expressions dynamically. Implement `addTaints(AddRemoveTaintsEvent $event): int` returning a taint bitmask.
- **`RemoveTaintsInterface`** — Plugin event handler that removes taint types from expressions.
- **`$codebase->getOrRegisterTaint("custom_type")`** — Register a custom taint type that can also be used in `@psalm-taint-*` annotations.
- **`$codebase->registerTaintAlias("alias", $bitmask)`** — Register a group alias combining multiple taint types.

This is useful when stub annotations alone are insufficient (e.g., conditional tainting based on argument values that can't be expressed with annotations).

### Debugging Taint Analysis

When validating annotations, these tools help diagnose issues:
- **`psalm --taint-analysis --dump-taint-graph=taints.dot`** — Outputs the taint flow graph in DOT format. Convert to SVG with `dot -Tsvg -o taints.svg taints.dot` to visualize taint paths.
- **`psalm --taint-analysis --use-baseline=taint-baseline.xml`** — Use a separate baseline for taint analysis (distinct from the main Psalm baseline).
- **`psalm --taint-analysis --report=results.sarif`** — Generate SARIF report for viewing taint flows in GitHub Code Scanning or other SARIF-compatible tools.

### Avoiding False Positives

1. **Use `@psalm-taint-escape`** to mark sanitization functions (e.g., `htmlentities` wrapper escapes `html`)
2. **Use `@psalm-taint-specialize`** to isolate taint per call site — prevents one tainted call from contaminating unrelated calls to the same function
3. **Use `@psalm-pure` or `@psalm-immutable`** for automatic specialization
4. **Bindings/parameterized queries are safe** — PDO prepared statement parameters should NOT be marked as taint sinks
5. **Configure `<ignoreFiles>`** in psalm.xml to exclude test directories from taint paths

### Avoiding False Negatives

1. **Use `@psalm-taint-unescape`** on functions that reverse escaping (e.g., HTML entity decoding)
2. **Use `@psalm-flow`** to make data flow explicit through proxy functions and wrappers
3. **Mark all input entry points** as taint sources, not just `$_GET`/`$_POST`/`$_COOKIE`

## Inspection Methodology

For EACH method with a taint annotation, perform this deep inspection:

### 1. Locate the Laravel Source

Find the actual implementation in `vendor/laravel/framework/src/Illuminate/`. Read the full method body.

### 2. Trace Data Flow

For **taint sinks** (`@psalm-taint-sink <type> $param`):
- Trace how `$param` flows through the method implementation
- Determine if the parameter is directly used in a dangerous operation (raw SQL, shell exec, file path, HTML output, HTTP redirect) or if it goes through sanitization/parameterization
- Check if the sink type is correct for the actual danger (refer to taint types table above)
- Check if OTHER parameters should also be marked as sinks but aren't

For **taint sources** (`@psalm-taint-source input`):
- Verify the method actually returns user-controllable data
- Check if the return value is derived from HTTP request data, headers, cookies, URL segments, etc.
- Verify no server-side-only data is being incorrectly marked as user input

For **taint escapes** (`@psalm-taint-escape <type>`):
- Verify the method actually sanitizes/transforms data in a way that neutralizes the taint type
- Check if `@psalm-flow ($param) -> return` is needed to maintain taint propagation for other taint types
- Verify ALL relevant taint types are escaped (e.g., `html` AND `has_quotes` for HTML escaping)

For **taint unescapes** (`@psalm-taint-unescape <type>`):
- Verify the method reverses a sanitization, re-introducing the taint type
- Check if `@psalm-flow` is correctly set

### 3. Assess False Positive Risk

For each annotation, evaluate:
- **Could legitimate safe usage be flagged?** (e.g., bindings parameters in SQL methods are safe channels for user input)
- **Is the parameter sometimes safe and sometimes dangerous?** (e.g., a method that accepts both raw strings and parameterized queries)
- **Does the annotation match Laravel's intended API contract?** (e.g., `*Raw()` methods are explicitly designed for raw SQL)

### 4. Check Method Signature Match

Verify that the stub's method signature (parameter names, types, defaults) matches the actual Laravel source.

## Output Format

For each inspected method, report:

```
### ClassName::methodName()

**Annotation:** `@psalm-taint-sink sql $param` (or source/escape/unescape)
**Source location:** vendor/laravel/framework/src/Illuminate/.../File.php:LINE

**Data flow:**
Brief description of how the parameter flows through the implementation.

**Verdict:** CORRECT | INCORRECT | NEEDS MODIFICATION

**Details:**
- Explanation of why the annotation is correct or what needs to change
- Any false positive risks
- Any missing annotations on other parameters

**Suggested fix** (if needed):
```diff
- @psalm-taint-sink sql $param
+ @psalm-taint-sink html $param
```
```

## Key Principles

1. **Bindings are safe** — PDO parameterized query bindings should NEVER be marked as taint sinks. Marking them would cause false positives since passing user input through bindings is the correct safe pattern.
2. **Raw methods are dangerous** — Methods with "Raw" in the name (selectRaw, whereRaw, etc.) accept raw SQL by design. Their expression/SQL parameters are correct taint sinks.
3. **Expression objects bypass escaping** — `new Expression($value)` stores values as-is with no escaping. Any parameter that becomes an Expression is a legitimate SQL taint sink.
4. **Return type matters for sources** — A taint source annotation means the return value carries taint. Methods returning `void` or `$this` should not be sources.
5. **Escapes must actually transform** — A taint escape must perform real sanitization (hashing, encoding, encryption). Simply passing through data is not an escape.
6. **Flow annotations preserve non-escaped taints** — When a function escapes one taint type but should propagate others, use `@psalm-flow ($param) -> return` alongside `@psalm-taint-escape`. Without flow, ALL taints are removed.
7. **Specialize when needed** — If a method is called with both tainted and untainted data across different call sites, consider `@psalm-taint-specialize` to prevent cross-contamination.
8. **Unescape reverses escape** — If `encrypt()` escapes `user_secret`, then `decrypt()` must unescape it, since the original tainted data is recoverable.

## Lessons Learned (Validated Patterns)

These patterns were validated through deep source code inspection and should be applied consistently:

### Taint Sources — When NOT to Mark

9. **Values constrained to finite sets should NOT be taint sources.** Methods that validate input into a strict set of values cannot carry injectable content:
   - `BackedEnum::tryFrom()` returns only developer-defined enum cases → `enum()` is NOT a source
   - `filter_var(..., FILTER_VALIDATE_BOOLEAN)` returns strict `true`/`false` → `boolean()` is NOT a source
   - `Date::parse()`/`createFromFormat()` validates into a structured Carbon object → `date()` is NOT a source
   - **Rationale:** These methods act as implicit whitelists/validators. Their outputs cannot carry SQL, HTML, or shell injection payloads.

10. **`@psalm-taint-source` applies to ALL return paths unconditionally.** For methods with conditional returns (e.g., `route($param)` returns `Route` object when `$param` is null, or a string value when non-null), the taint applies to every branch. Accept minor over-tainting on non-dangerous branches if the common usage path is correctly tainted.

### Taint Sinks — Scope and Boundaries

11. **The `file` taint type is about path control, not content.** File content parameters (e.g., `$contents` in `put()`, `$replace` in `replaceInFile()`) should NOT be marked as `file` sinks. There is no standard Psalm taint type for "file content injection." Writing user content to a known-safe path is normal and expected.

12. **Read-only boolean checks are poor sink candidates.** Methods like `file_exists($path)` that return only `bool` and perform no read/write/execute have minimal security risk. Marking them as sinks causes high false positive rates in common patterns (e.g., checking if user uploads exist). Prefer omitting the sink annotation.

13. **SQL identifier quoting (`wrap()`) is NOT a security boundary.** Laravel's `Grammar::wrap()` does identifier quoting (backticks/double quotes) but this is not reliable protection against SQL injection. Column name parameters (e.g., `$column` in `where()`) that pass through `wrap()` should still be marked as `sql` sinks.

14. **`@psalm-taint-unescape` adds taints unconditionally.** `decrypt()` with `@psalm-taint-unescape user_secret` adds the taint even if the original data was never a secret. This is a known Psalm limitation — there's no way to express "add taint only if previously removed." Accept this as a conservative trade-off.

### Missing Taint Types — Pragmatic Substitutions

15. **No built-in taint type for "open redirect."** Use `ssrf` pragmatically for redirect targets (e.g., `Redirector::to($path)`) combined with `header` for CRLF injection coverage. The dual annotation (`ssrf` + `header`) catches both redirect-to-malicious-site and header-injection vectors.

16. **No built-in taint type for "file content injection."** Do not invent custom taint types for content written to files — the false positive cost is too high and users would need custom source definitions.

### Stub Consistency

17. **Facade stubs need parity with underlying class stubs.** If `Connection` has `insert()`, `update()`, `delete()` with taint sinks, the `DB` facade stub should have them too. Psalm may resolve calls through either the facade or the underlying class.

18. **Methods delegating to annotated methods still benefit from their own annotations.** Even if `json()` delegates to `get()` internally, add the annotation to `json()` as well — Psalm may not trace through internal delegation in all analysis modes.

19. **Stub classes should not add modifiers absent from the real class.** Do not declare a stub class as `final` if the real Laravel class is not final — it would cause Psalm to reject valid subclasses.

## Stub File Locations

- Modified stubs: `stubs/common/Database/Query/Builder.stubphp`, `stubs/common/Foundation/helpers.stubphp`, `stubs/common/Http/InteractsWithInput.stubphp`, `stubs/common/Http/Request.stubphp`, `stubs/common/Support/Facades/DB.stubphp`, `stubs/common/Support/helpers.stubphp`
- New taint stubs: `stubs/common/Database/`, `stubs/common/Encryption/`, `stubs/common/Filesystem/`, `stubs/common/Hashing/`, `stubs/common/Http/`, `stubs/common/Process/`, `stubs/common/Routing/`

## Laravel Source Location

`vendor/laravel/framework/src/Illuminate/`