---
name: psalm-stub-audit
description: Audit stub file parameter types against Laravel's actual source signatures. Detects overly strict @param types that cause false-positive ArgumentTypeCoercion errors. Can audit a single file, a directory, or all stubs.
argument-hint: "[file-or-directory] - optional stub file path or directory to audit (defaults to all stubs)"
effort: max
---

# Psalm Stub Audit

You are auditing Psalm stub files in the psalm-plugin-laravel project to detect **parameter types that differ from Laravel's actual signatures** and evaluate whether the difference is beneficial or harmful.
Stricter `@param` types can be beneficial when they catch genuine bugs (e.g., `positive-int` for chunk sizes), but harmful when they cause false positives for common, valid usage patterns without catching real bugs.

Ultrathink tasks.

## Scope

If `$ARGUMENTS` is provided, audit only the specified file or directory. Otherwise, audit all stub files under `stubs/common/` and `stubs/11/` and `stubs/12/`.

## What to Check

For each function/method in a stub file that has `@param` annotations:

### 1. Locate the Laravel Source

Find the corresponding function/method in `vendor/laravel/framework/src/Illuminate/`. For helper functions, check `vendor/laravel/framework/src/Illuminate/Foundation/helpers.php` and `vendor/laravel/framework/src/Illuminate/Support/helpers.php`.

### 2. Compare Parameter Types

For each `@param` annotation in the stub, compare it against Laravel's actual parameter type (from the PHP type declaration and/or the PHPDoc `@param`).

**Flag as a problem** when the stub type is a strict subtype of Laravel's declared type:

| Stub type | Laravel type | Problem? | Why |
|-----------|-------------|----------|-----|
| `positive-int` | `int` | YES | `positive-int` is a subtype of `int` |
| `int<0, max>` | `int` | YES | `int<0, max>` is a subtype of `int` |
| `int<1, max>` | `int` | YES | Same as `positive-int` |
| `non-empty-string` | `string` | MAYBE | Check if empty string is genuinely invalid or just unusual |
| `non-empty-list` | `array` | MAYBE | Check if empty array causes errors |
| `non-empty-array` | `array` | MAYBE | Check if empty array causes errors |
| `int<300, 308>` | `int` | OK | HTTP status codes — narrowing is valid here as invalid codes cause errors |
| `int<400, 511>` | `int` | OK | HTTP error codes — same rationale |
| `class-string` | `string` | OK | Genuinely requires a class name |
| `callable-array\|class-string` | `string` | OK | More precise but compatible |

### 3. Evaluate "MAYBE" Cases

For types flagged as MAYBE, check the Laravel source to determine if:

- **Laravel validates/rejects the broader type** (e.g., throws on empty string) → narrower stub type is acceptable
- **Laravel handles the broader type gracefully** (e.g., treats empty string as null, negative int as 0) → stub must use the broader type
- **The narrower type prevents legitimate usage patterns** → stub must use the broader type

The key question: **Will a user passing the broader type get a false positive from Psalm that would NOT be an actual bug in their code?**

### 4. Check Return Types (Informational Only)

Return types CAN be narrower than Laravel's declared types — this is beneficial for downstream type inference and is NOT a problem. Note these as "OK (return type narrowing)" but do not flag them.

### 5. Check for Missing `@param` Annotations

If a stub method has some but not all parameters annotated, check whether the missing parameters could benefit from annotations. This is informational, not a problem.

## Strictness Levels

Report findings at three levels:

- **ERROR**: Stub `@param` type is stricter than Laravel's AND causes false positives for common, valid usage patterns without catching real bugs
- **WARNING**: Stub `@param` type is stricter but the impact depends on context — evaluate whether the narrowing catches genuine bugs (beneficial) or just creates noise (harmful)
- **INFO**: Observation that doesn't require action (e.g., return type narrowing, missing annotations)

## Output Format

```
## Audit Results for [file/directory]

### [filename]

#### ClassName::methodName() (or function_name())

**Parameter:** `$paramName`
**Stub type:** `positive-int`
**Laravel type:** `int` (from type declaration / PHPDoc)
**Source:** vendor/laravel/framework/src/Illuminate/.../File.php:LINE
**Severity:** ERROR

**Analysis:**
Laravel declares `$paramName` as `int` with no validation that rejects non-positive values.
[Description of what happens with edge case values]

**Suggested fix:**
```diff
- * @param positive-int $paramName
+ * @param int $paramName
```

---

[next finding...]
```

## Summary Section

At the end, provide a summary table:

```
## Summary

| Severity | Count | Details |
|----------|-------|---------|
| ERROR    | N     | Definite false positive sources |
| WARNING  | N     | Potential issues to investigate |
| INFO     | N     | Informational observations |
```

## Key Principles

1. **Stricter parameter types are acceptable when they catch genuine bugs** (e.g., `positive-int` for chunk sizes, `non-empty-string` for column names). Only flag stricter params as errors when they cause false positives for common, valid usage patterns without catching real bugs.
2. **Return types CAN be narrower.** Narrowing return types improves downstream inference and is desirable.
3. **Template types and conditional types are exempt.** Complex generic annotations (`@template`, conditional return types) serve a different purpose and should not be flagged for type width.
4. **Taint annotations are out of scope.** Use the `taint` skill for auditing `@psalm-taint-*` annotations.
5. **Generated stubs are out of scope.** Only audit hand-written stubs under `stubs/`. Generated files (facades.stubphp, models.stubphp) are created by ide-helper.
6. **Context matters.** A type like `class-string` for a parameter declared as `string` in Laravel is usually correct — Laravel genuinely requires a class name even if PHP's type system can't express it. Use judgment.
7. **Check both type declaration AND PHPDoc.** Laravel may have `int` as the PHP type but `positive-int` in its own PHPDoc. If Laravel's own PHPDoc uses the narrower type, matching it in the stub is acceptable.