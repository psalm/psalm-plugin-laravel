---
title: Taint Analysis Stubs
parent: Contributing
nav_order: 5
---

# Taint Analysis Stubs

This guide covers how to write and review taint analysis stubs for psalm-plugin-laravel.

For Psalm's upstream taint analysis documentation, see:
- [Security Analysis overview](https://psalm.dev/docs/security_analysis/): how taint sources, sinks, and types work
- [Taint annotations reference](https://psalm.dev/docs/security_analysis/annotations/): `@psalm-taint-source`, `@psalm-taint-sink`, `@psalm-taint-escape`, `@psalm-taint-unescape`, `@psalm-taint-specialize`, `@psalm-flow`
- [Avoiding false positives](https://psalm.dev/docs/security_analysis/avoiding_false_positives/): `@psalm-taint-escape`, `@psalm-taint-specialize`, ignoring files
- [Avoiding false negatives](https://psalm.dev/docs/security_analysis/avoiding_false_negatives/): `@psalm-taint-unescape`
- [Custom taint sources](https://psalm.dev/docs/security_analysis/custom_taint_sources/): `@psalm-taint-source` annotation and plugin API
- [Custom taint sinks](https://psalm.dev/docs/security_analysis/custom_taint_sinks/): `@psalm-taint-sink` annotation
- [Taint flow](https://psalm.dev/docs/security_analysis/taint_flow/): `@psalm-flow` proxy and return hints

## Stub location

Taint annotations live in `stubs/common/` alongside type stubs, organized by Laravel namespace.
Taint analysis is opt-in (`runTaintAnalysis="true"` in `psalm.xml`, or `--taint-analysis` CLI flag), so there is no need for a separate directory. The stubs apply whenever taint analysis is enabled.

## Annotations quick reference

There are six taint-related annotations. The first four are the ones you'll use most in stubs:

| Annotation                          | Purpose                                              | Needs `@psalm-flow`?                                                                                |
|-------------------------------------|------------------------------------------------------|-----------------------------------------------------------------------------------------------------|
| `@psalm-taint-source <kind>`        | Marks return value as producing tainted data         | No. Sources create new taint.                                                                       |
| `@psalm-taint-sink <kind> <$param>` | Marks a parameter as dangerous if tainted            | No. Sinks are endpoints.                                                                            |
| `@psalm-taint-escape <kind>`        | Removes a specific taint kind from the return value  | **Yes**. See [critical rule](#critical-rule-always-pair-psalm-taint-escape-with-psalm-flow) below.  |
| `@psalm-flow (<$params>) -> return` | Declares that taint propagates from params to return | N/A (this IS the flow declaration)                                                                  |
| `@psalm-taint-unescape <kind>`      | Re-adds a taint kind (reverses an earlier escape)    | Yes (same pattern as escape)                                                                        |
| `@psalm-taint-specialize`           | Tracks taints per call-site instead of globally      | No                                                                                                  |

## Critical rule: always pair `@psalm-taint-escape` with `@psalm-flow`

`@psalm-taint-escape` alone makes the return value **fully untainted**. It drops ALL taint kinds, not just the one specified. This creates dangerous false negatives.

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

The same rule applies to `@psalm-taint-unescape`: always pair it with `@psalm-flow`.

Psalm's own stubs follow this pattern (see `urlencode()`/`strip_tags()` in `vendor/vimeo/psalm/stubs/CoreGenericFunctions.phpstub`).

### When `@psalm-flow` is NOT needed

**Sinks** don't need `@psalm-flow` because they are endpoints: they consume tainted data, they don't produce output.

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

**Exception (sink-only escapes)**: If a function's return value is never used for taint-sensitive operations (e.g., `Hash::make()` returns a hash that's safe by nature), `@psalm-taint-escape` without `@psalm-flow` is acceptable because there's no meaningful taint to preserve on the return value.

## Taint kinds

All taint kind names are defined in [`Psalm\Type\TaintKind::TAINT_NAMES`](https://github.com/vimeo/psalm/blob/master/src/Psalm/Type/TaintKind.php). These are the strings you use in annotations.

### Common kinds used in stubs

| Kind            | Attack vector                             | Example sink                                  | Example escape                                |
|-----------------|-------------------------------------------|-----------------------------------------------|-----------------------------------------------|
| `html`          | XSS via HTML injection                    | `echo`, `Response::make()`                    | `e()`, `htmlspecialchars()`                   |
| `has_quotes`    | Attribute injection via unquoted strings  | `echo` inside HTML attributes                 | `e()`, `urlencode()`                          |
| `sql`           | SQL injection                             | `Connection::unprepared()`                    | `Connection::escape()`, parameterized queries |
| `shell`         | Command injection                         | `Process::run()`                              | `escapeshellarg()`                            |
| `ssrf`          | Server-side request forgery               | `Http::get($url)`                             | N/A                                           |
| `file`          | Path traversal                            | `Filesystem::get()`, `response()->download()` | N/A                                           |
| `user_secret`   | Password/token exposure in logs or output | `echo`, log sinks                             | `Hash::make()`, `Encrypter::encrypt()`        |
| `system_secret` | Internal secret exposure                  | `echo`, log sinks                             | `Hash::make()`, `Encrypter::encrypt()`        |

### All available kinds

| Kind                 | Constant                   | Description                                                |
|----------------------|----------------------------|------------------------------------------------------------|
| `callable`           | `INPUT_CALLABLE`           | User-controlled callable strings                           |
| `unserialize`        | `INPUT_UNSERIALIZE`        | Strings passed to `unserialize()`                          |
| `include`            | `INPUT_INCLUDE`            | Paths passed to `include`/`require`                        |
| `eval`               | `INPUT_EVAL`               | Strings passed to `eval()`                                 |
| `ldap`               | `INPUT_LDAP`               | LDAP DN or filter strings                                  |
| `sql`                | `INPUT_SQL`                | SQL query strings                                          |
| `html`               | `INPUT_HTML`               | Strings that could contain HTML/JS                         |
| `has_quotes`         | `INPUT_HAS_QUOTES`         | Strings with unescaped quotes                              |
| `shell`              | `INPUT_SHELL`              | Shell command strings                                      |
| `ssrf`               | `INPUT_SSRF`               | URLs passed to HTTP clients                                |
| `file`               | `INPUT_FILE`               | Filesystem paths                                           |
| `cookie`             | `INPUT_COOKIE`             | HTTP cookie values                                         |
| `header`             | `INPUT_HEADER`             | HTTP header values                                         |
| `xpath`              | `INPUT_XPATH`              | XPath query strings                                        |
| `sleep`              | `INPUT_SLEEP`              | Values passed to `sleep()` (DoS)                           |
| `extract`            | `INPUT_EXTRACT`            | Values passed to `extract()`                               |
| `user_secret`        | `USER_SECRET`              | User-supplied secrets (passwords, tokens)                  |
| `system_secret`      | `SYSTEM_SECRET`            | System secrets (API keys, encryption keys)                 |
| `input`              | `ALL_INPUT`                | Alias: all input-related kinds combined (excludes secrets) |
| `tainted`            | `ALL_INPUT`                | Alias: same as `input`                                     |
| `input_except_sleep` | `ALL_INPUT & ~INPUT_SLEEP` | All input kinds except `sleep` (used by `filter_var()`)    |

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

## PDO parameterized queries

Eloquent and the Query Builder use PDO prepared statements for WHERE conditions, HAVING clauses, and primary-key lookups. When a value is passed to `where('col', $value)`, Laravel stores it in `$this->bindings['where'][]` via `addBinding()` and the grammar compiles it as a `?` placeholder. The value never enters the SQL string. PDO binds it at execution time, making SQL injection impossible regardless of content.

This creates two distinct annotation responsibilities:

- **Column names** (`$column`): interpolated literally into the SQL identifier (e.g., `WHERE {$column} = ?`), so user-controlled column names are a real injection risk. Mark with `@psalm-taint-sink sql $column`.
- **Values** (`$value`, `$operator` in 2-arg form, `$id`): PDO-bound, never interpolated. Use `@psalm-taint-escape sql` to suppress false-positive `TaintedSql` warnings, paired with `@psalm-flow` to preserve other taint kinds.

### Pattern for where-family methods

```php
/**
 * @psalm-taint-sink sql $column           -- column names go into SQL identifiers; warn if tainted
 * @psalm-taint-escape sql                 -- values are PDO-bound; strip sql taint from return value
 * @psalm-flow ($operator, $value) -> return  -- preserve other taint kinds (html, shell, etc.)
 */
public function where($column, $operator = null, $value = null, $boolean = 'and') {}
```

Both `$operator` and `$value` appear in `@psalm-flow` because in the **2-argument form** (`where('col', $userValue)`), Laravel's `prepareValueAndOperator()` moves the second argument into the `$value` position (the original `$value = null` is discarded), so user input may arrive via `$operator` at the call site, even though it is always PDO-bound.

The same pattern applies to `orWhere()`, `whereNot()`, `orWhereNot()`, `having()`, and `orHaving()`.

### Pattern for find-family methods

```php
/**
 * @psalm-taint-escape sql       -- id is PDO-bound; strip sql taint from return value
 * @psalm-flow ($id) -> return   -- preserve other taint kinds
 * @psalm-taint-specialize       -- track taint per call-site (see note below)
 */
public function find($id, $columns = ['*']) {}
```

`@psalm-taint-specialize` is required here. Without it, a single `find($taintedId)` call anywhere in the codebase would mark ALL `find()` return values as tainted globally (including `find(1)` with a safe literal). See [Flow-through factories need `@psalm-taint-specialize`](#flow-through-factories-need-psalm-taint-specialize) for the general rule.

This specialize + escape pattern applies to `find()`, `findMany()`, `findOrFail()`, `findOrNew()`, and `findSole()`.

`firstWhere()` is a hybrid: it also accepts a `$column` argument that is interpolated into SQL, so it additionally needs `@psalm-taint-sink sql $column` and `@psalm-flow ($operator, $value)`. Do not treat it as a pure find-family method.

Note that `where()` does NOT need `@psalm-taint-specialize` because it returns `$this` (the fluent builder), a value that is chained further rather than consumed at the call site. Per-call-site isolation matters for concrete return values (models, scalars), not for method-chaining builders.

### Raw methods must not get the escape

Raw SQL methods accept a string that is interpolated verbatim into the query with no parameterization:

```php
/**
 * @psalm-taint-sink sql $sql   -- raw SQL goes directly into the query string
 */
public function whereRaw($sql, $bindings = [], $boolean = 'and') {}
```

Never add `@psalm-taint-escape sql` to `whereRaw()`, `orWhereRaw()`, `selectRaw()`, `havingRaw()`, `orderByRaw()`, `groupByRaw()`, `fromRaw()`, `DB::statement()`, or `DB::unprepared()`.

## Known limitations of `@psalm-flow`

### `$this` is not supported as a flow source

`@psalm-flow ($this) -> return` **does not work**. Psalm's flow parser only matches named method parameters, and `$this` is never in that list. The annotation is silently ignored with no error.

This means you cannot declare taint flow from an object instance to a method's return value via stubs. For fluent/builder classes like `Stringable`, taint entering via `Str::of($tainted)` will not automatically propagate through chained methods like `->trim()->lower()->toString()`.

**Workarounds:**
- Annotate the **entry point** (`Str::of()`, `str()`) with `@psalm-flow ($param) -> return` so the returned object carries taint
- Annotate methods that accept **additional tainted parameters** (like `append($values)`) with `@psalm-flow ($values) -> return`
- For full `$this` → return propagation, a handler using `AfterMethodCallAnalysisInterface` is needed (not yet implemented)

### Flow-through factories need `@psalm-taint-specialize`

When a function has `@psalm-flow ($param) -> return` without `@psalm-taint-specialize`, Psalm merges taint from **all call sites globally**. This means one tainted call site poisons all others:

```php
// WITHOUT @psalm-taint-specialize:
// Str::of($request->input('name')) at line 10 taints ALL Str::of() calls,
// so Str::of('safe literal') at line 20 is falsely reported as tainted.

// CORRECT: pair both annotations on pure flow-through factories
/**
 * @psalm-taint-specialize
 * @psalm-flow ($string) -> return
 */
public static function of($string) {}
```

**Escape functions still need `@psalm-taint-specialize`** when the stub returns a `mixed`-or-wider value that can pool. `@psalm-taint-escape` only strips the listed kind(s) (e.g. `html`, `has_quotes`); every other taint that flows through `@psalm-flow` (`sql`, `shell`, `user_secret`, `system_secret`, etc.) continues to pool into the single global return node and re-emerges at every other callsite (issue #1007). For `Js::from()` / `Js::encode()` adding `@psalm-taint-specialize` cleanly isolates per-callsite flow and is verified by `SafeJsEncodeSpecializePerCallsite.phpt`.

**Empirical verification is mandatory.** Adding `@psalm-taint-specialize` to a `@psalm-flow` + `@psalm-taint-escape` (or `@psalm-taint-unescape`) stub is NOT mechanically safe in Psalm 7. Spot-checking issue #1007's follow-up list showed that the same triple breaks within-callsite taint propagation on `Connection::escape()`, `SessionGuard::hashPasswordForCookie()`, and `Encrypter::*String` — the `TaintedHtml*` tests for those methods stopped firing after `@psalm-taint-specialize` was added, even though `Js::encode()` with the same triple keeps propagating SQL taint correctly in `TaintedSqlJsEncodePreservesTaint.phpt`. The asymmetry is not localized yet (likely a Psalm-7 interaction between `@psalm-taint-specialize` and the `input` group alias on narrow parameter types). Before adding `@psalm-taint-specialize` to any other escape/unescape stub:

1. Identify the existing test that asserts within-callsite non-escaped-kind flow through the stub. If no such test exists, write one.
2. Add `@psalm-taint-specialize` and re-run the test. If it now reports zero errors, the stub falls into the broken-asymmetry class — revert the annotation and open a Psalm 7 bug report with a minimal repro.
3. Add a per-callsite regression test under `tests/Type/tests/TaintAnalysis/Safe<Stub><Method>SpecializePerCallsite.phpt` modeled on `SafeJsEncodeSpecializePerCallsite.phpt`.

The known-broken candidates (`e()`, `encrypt()` / `decrypt()` and `*String` variants, `SessionGuard::hashPasswordForCookie()`, `Connection::escape()`, `DB::escape()`) are tracked as follow-ups to #1007. Do not blanket-apply the annotation; treat every site as its own bisect.

## Per-rule escape on Rule objects

The plugin already escapes taint for built-in rules used as strings (e.g. `'email'` escapes `header` and `cookie`). The escape also applies when the same rule is expressed as a first-party Laravel rule object, and can be extended to application-defined Rule classes.

### Built-in Laravel rule classes

`Illuminate\Validation\Rules\*` objects and the matching `Illuminate\Validation\Rule::*()` fluent builders are recognised automatically, with escape bits that mirror the string-rule equivalents:

| Usage | Escape |
|---|---|
| `new Rules\Email()`, `Rule::email()` | `header`, `cookie` |
| `new Rules\Numeric()`, `Rule::numeric()` | all input |
| `new Rules\In([...])`, `Rule::in([...])` | all input |
| `new Rules\Date()`, `Rule::date()` | all input |

Chained fluent calls (including the nullsafe form `?->`) resolve to the root class, so `Rule::email()->preventSpoofing()->rfcCompliant(strict: true)` still escapes `header` and `cookie`.

Other `Rule::*()` methods (`unique`, `exists`, `dimensions`, `when`, `notIn`, `file`, `imageFile`, `enum`, …) contribute no taint escape, because the value either depends on runtime arguments (e.g. the column passed to `Rule::unique`) or is not bounded to a safe character set. The field still surfaces in the validator's inferred shape, so type narrowing on `validated()` continues to apply.

### Custom Rule classes

Application code can extend the escape to **custom Rule classes** by placing `@psalm-taint-escape <kind>` on the class docblock.

When `ValidationRuleAnalyzer` encounters a Rule object in a `rules()` array, it resolves the class FQN, reads the class's own `@psalm-taint-escape` tags, and ORs those kinds into the field's removed-taints bitmask alongside any string rule escapes.

```php
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * @psalm-taint-escape header
 * @psalm-taint-escape cookie
 */
final class EmailWithDnsRule implements ValidationRule
{
    public static function make(): self
    {
        return new self();
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        (Rule::email()->preventSpoofing()->rfcCompliant(strict: true))
            ->validate($attribute, $value, $fail);
    }
}

// ['required', new EmailWithDnsRule()]      : escape unioned in.
// ['required', EmailWithDnsRule::make()]    : same (static factory accepted, see caveat below).
// ['required', 'email', new EmailWithDnsRule()] : email's escape unioned with class's escape.
```

**What is honoured:**

- Only `@psalm-taint-escape` at class level. `@psalm-taint-source`, `@psalm-taint-sink`, and `@psalm-flow` are ignored on a class (they have no meaning outside a function-like scope).
- The bare form (`@psalm-taint-escape header`). The conditional form (`@psalm-taint-escape (...)`) is parameter-scoped and ignored on a class.
- Any `TaintKind` name from the [All available kinds](#all-available-kinds) table (including `input` as a shortcut for all input taints).
- Rule objects constructed via `new X()` or a static factory `X::method(...)`. Dynamic class names (`new $class()`) and runtime-built rule arrays are out of scope, matching the parser limits elsewhere in `ValidationRuleAnalyzer`.
- **The annotation is read from the class that appears literally in `rules()`.** Subclassing an annotated rule does NOT inherit its escape. Re-declare the annotation on the subclass if you need it. This keeps the taint contract explicit and reviewable from the Rule class alone.

**Static factory caveat.** For an application `X::make(...)` the plugin reads the docblock of `X`, not of whatever object the method returns. This is sound for the common user-authored pattern (`public static function make(): static { return new static(); }`) where `X` and the returned class coincide. Laravel's own `Rule::*()` fluent builders do not match this heuristic (they return a different class), so they are handled via the dedicated method map described above rather than by reading `Rule`'s docblock.

**Base class agnostic.** The handler reads the docblock on whatever class you instantiate. Any of `Illuminate\Contracts\Validation\ValidationRule`, `Illuminate\Contracts\Validation\InvokableRule`, or the deprecated `Illuminate\Contracts\Validation\Rule` works. Custom base classes or community packages (e.g. Spatie's `CompositeRule`) work as well, since no `instanceof` check is performed.

**No `@psalm-flow` needed.** Unlike function-level escapes, the class-level annotation does not live on a return value: it applies to the Rule's contribution to a single validated field. The "always pair with `@psalm-flow`" rule does not apply here.

**Trust model.** The plugin trusts the developer's assertion, just like any `@psalm-taint-escape`. A mis-annotated rule becomes a **false negative**: the escape removes taint kinds the value still actually carries. Only annotate kinds the rule genuinely prevents, and prefer narrow escapes (such as `header`, `cookie`) over the broad `input` alias unless the rule truly constrains the value to a digit-like or date-like form.

## Plugin-emitted taint sinks (handler-driven)

Some taint sinks are not expressible as `@psalm-taint-sink` docblocks because they target language constructs (comparison operators) or call shapes that the stub parser cannot annotate. These sinks are registered programmatically by handlers in `src/Handlers/Rules/`.

### `TimingUnsafeComparisonHandler` — CWE-208

Detects timing-unsafe comparisons of secret-tainted values. The handler registers a taint sink (matching `USER_SECRET | SYSTEM_SECRET`) at every:

- Strict and loose equality / inequality operator: `===`, `==`, `!==`, `!=`
- Variable-time string-compare function: `strcmp()`, `strcasecmp()`, `strncmp()`, `strncasecmp()`, `substr_compare()`

Comparisons against a literal scalar (`null`, `''`, `'sentinel'`, `42`, `false`) are skipped: the literal IS the known half of the comparison, so no character-by-character information about the secret leaks. Idiomatic defensive checks (`if ($token === null)`, `if ($apiKey === '')`) do not trigger the handler.

The literal carve-out matches by **AST shape**, not by Psalm's inferred type. Integer/float/string scalars, magic constants (`__FILE__`, `__LINE__`, ...), `null`/`true`/`false`, unary `+`/`-` over a literal, and concatenation of two literals all count. Class constants (`Foo::BAR`) and enum cases (`Status::Active`) are **not** exempt — an attacker-controlled indirection could resolve to one at runtime, so the handler errs on flagging.

When a value carrying `user_secret` or `system_secret` taint flows into one of these sinks, Psalm emits `TaintedUserSecret` or `TaintedSystemSecret`. The fix is to use `hash_equals()` for constant-time comparison.

```php
// Triggers TaintedUserSecret
function check(\Illuminate\Foundation\Auth\User $user, string $given): bool {
    return $user->getAuthPassword() === $given;
}

// Safe — hash_equals() is not watched as a sink
function checkSafe(\Illuminate\Foundation\Auth\User $user, string $given): bool {
    return hash_equals($user->getAuthPassword(), $given);
}
```

**Runtime cost.** The handler hooks `AfterExpressionAnalysisInterface`, which fires per expression. It exits immediately when `taint_flow_graph` is null (i.e. when `--taint-analysis` is not enabled), so the only cost in regular analysis is an `instanceof` check against the event's expression. Sink registration only happens during taint analysis runs.

**Issue-message limitation.** Psalm 7 hardcodes the issue message per taint kind in `TaintFlowGraph::connectSinksAndSources()`, so the emitted text is the generic `"Detected tainted user secret leaking"` rather than something CWE-208-specific. Tracked upstream as [vimeo/psalm#11762](https://github.com/vimeo/psalm/issues/11762); the handler will switch to a CWE-tagged message once a custom-message API lands. The data-flow trace itself still pinpoints the timing-unsafe comparison site, so the report is actionable today.

**Scope.** Only secret-tainted operands are flagged. Plain `===` on user input (e.g. `$request->input('name') === 'admin'`) is not reported, because the sink does not match `INPUT_*` taint kinds.

**Known gaps.** These shapes are NOT currently watched, even when one operand carries secret taint:

- `switch ($secret) { case $candidate: }` — `switch`/`case` uses `==` semantics but lives in `Stmt\Switch_`/`Stmt\Case_`, not a `BinaryOp` node, so it bypasses the operator branch.
- `match ($secret) { 'literal' => ... }` — same reason. Note: `match` against a literal arm would be exempt by the literal carve-out anyway, but `match` against a variable arm would slip through.
- Partial-leak operations: `str_starts_with`, `str_ends_with`, `str_contains` on a secret; `preg_match` with an attacker-controlled pattern; `in_array($secret, $list, false)` / `array_search($secret, $list, false)`; fluent chains like `Str::of($secret)->is($candidate)`.

These are tracked as follow-ups. Until they are covered, treat the handler as a high-signal first-line check rather than a complete CWE-208 audit.

## Stub authoring checklist

1. **Verify the function's actual behavior** against Laravel source in `vendor/laravel/framework/`
2. **For database methods, check whether values are PDO-bound or raw SQL**. See [PDO parameterized queries](#pdo-parameterized-queries). Column names go into SQL identifiers (sink); values go into bindings (escape).
3. **Choose the correct annotation type**: source, sink, escape, or flow
4. **If using `@psalm-taint-escape` or `@psalm-taint-unescape`**: always add `@psalm-flow` to preserve other taint kinds (unless the return value's other taints are truly irrelevant)
5. **If using `@psalm-flow` on a method returning a concrete value (model, scalar, or collection)**: add `@psalm-taint-specialize` to prevent cross-call-site taint pollution, then run the existing `Tainted<NonEscapedKind>*` test for the stub to confirm within-callsite flow still propagates. The combination is not mechanically safe on every stub shape in Psalm 7 — see [Flow-through factories need `@psalm-taint-specialize`](#flow-through-factories-need-psalm-taint-specialize) for the empirical-verification protocol
6. **Match parameter types exactly** to Laravel's signatures. Do not narrow types.
7. **Place in `stubs/common/`** under a path matching the Laravel namespace
8. **Keep taint and type annotations together**. If a method already has type stubs, add taint annotations to the same file (see [Stub merging](README.md#stub-merging-how-psalm-combines-annotations))

## Testing taint stubs

The project's own `psalm.xml` cannot test taint stubs (the plugin can't analyze itself). Create a separate test project:

```bash
mkdir -p /tmp/taint-test/app
cat > /tmp/taint-test/psalm.xml << 'XMLEOF'
<?xml version="1.0"?>
<psalm errorLevel="1"
    findUnusedCode="false"
    runTaintAnalysis="true"
    xmlns="https://getpsalm.org/schema/config">
    <projectFiles>
        <directory name="app" />
    </projectFiles>
    <plugins>
        <pluginClass class="Psalm\LaravelPlugin\Plugin"/>
    </plugins>
</psalm>
XMLEOF

# Write test PHP in /tmp/taint-test/app/Test.php, then:
cd /tmp/taint-test && /path/to/vendor/bin/psalm --no-cache
```

**Tip**: Use `--dump-taint-graph=taints.dot` to visualize taint flow and debug unexpected results. See [Debugging the taint graph](https://psalm.dev/docs/security_analysis/#debugging-the-taint-graph).

### Known limitation: Facade static calls

Facade static calls (`DB::unprepared(...)`) may not propagate taint because `__callStatic` loses taint context. The generated alias stubs (`class X extends Y {}`) don't carry taint annotations. Calling the underlying class directly (`DB::connection()->unprepared(...)`) works correctly.
