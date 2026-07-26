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

The upstream reference does not cover the conditional form of `@psalm-taint-escape`, nor `@psalm-assert-untainted`. Both are documented below from source, along with which analyzers actually consume them.

Claims here are verified against both majors this repo targets. Source citations are Psalm 7.0.0-beta19 and will drift; the Psalm 6 line (6.16.1, backing `3.x`) implements this surface in different classes at different line numbers. Only behavioral differences between the majors are called out, at the point of use.

## Stub location

Taint annotations live in `stubs/common/` alongside type stubs, organized by Laravel namespace.
Taint analysis is opt-in (`runTaintAnalysis="true"` in `psalm.xml`, or `--taint-analysis` CLI flag), so there is no need for a separate directory. The stubs apply whenever taint analysis is enabled.

Version difference that bites when testing: Psalm 6 runs in exactly one mode per invocation, so a run reports either type issues or taint issues and never both, and full coverage takes two runs. Psalm 7 emits both in a single run. A Psalm 6 run that looks clean of type errors may simply be in taint-only mode.

**Exception — classes reached only through narrowing.** A taint stub redeclares the class to host the annotated method, which makes the stub claim the class's file slot. Psalm *merges* that stub with the real class (stub members win on overlapping names) — but only when the real source is **also** scanned, which a direct mention of the class in analysed code triggers. A class reached only through a return-type provider (for example `Illuminate\Auth\SessionGuard` produced by `auth('web')`, or `Illuminate\Encryption\Encrypter` produced by `app('encrypter')`) is never named in analysed code, so its real source is never scanned. The stub then becomes the class's sole definition and every non-stubbed method goes missing, breaking calls like `auth('web')->user()` and `app('encrypter')->getKey()` (#1113). The strip stays invisible when the class carries a `Macroable` or `__call` (most Laravel service classes, such as `Cache\Repository`, `Session\Store`, and `Database\Connection`, mask the missing methods as magic calls). It surfaces as a hard `UndefinedMethod` only on the few classes that lack that masking, like the auth guards and the encrypter. For those, set the taint on the *real* method storage from a scan-phase handler instead. The fields `taint_source_types`, `added_taints`, `removed_taints`, and `return_source_params` are exactly what `@psalm-taint-source`, `@psalm-taint-unescape`, `@psalm-taint-escape`, and `@psalm-flow` populate, and the instance-call taint path reads them back. See `src/Handlers/Auth/GuardTaintHandler.php` and `src/Handlers/Encryption/EncrypterTaintHandler.php`.

### Optional third-party integrations: `stubs/integrations/<package>/`

Stubs for packages that ship outside `laravel/framework` (currently: `laravel/ai`) live under `stubs/integrations/<package>/` and are loaded only when the host application has the package installed. The plugin probes Composer's runtime metadata in `Plugin::optionalIntegrationStubs()`:

```php
if (self::isInstalledAndSatisfies('laravel/ai', '>=0.10.0 <1.0.0')) {
    \array_push($stubs, ...StubFileFinder::integrationStubs($stubsRoot, 'laravel-ai', $output));
}
```

Two reasons for the version range:

1. **Absent packages contribute zero cost** (no class lookups, no stub parsing).
2. **A future major bump won't silently load stubs that reference removed or renamed classes**: `satisfies()` traps the mismatch and falls back to no-op.

When adding a new integration, gate it on both `isInstalled()` (cheap presence check) and `satisfies()` (range guard), then drop the stubs into a new directory under `stubs/integrations/`.

## Annotations quick reference

Psalm parses eight taint-related tags. The first four are the ones you'll use most in stubs.

| Annotation                             | Scope                | Purpose                                                        | Needs `@psalm-flow`?                                                                               |
|----------------------------------------|----------------------|----------------------------------------------------------------|----------------------------------------------------------------------------------------------------|
| `@psalm-taint-source <kind>`           | function-like        | Marks return value as producing tainted data                   | No. Sources create new taint.                                                                       |
| `@psalm-taint-sink <kind> <$param>`    | function-like        | Marks a parameter as dangerous if tainted                      | No. Sinks are endpoints.                                                                            |
| `@psalm-taint-escape <kind>`           | function-like        | Removes a taint kind from the return value, unconditionally    | **Yes**. See [critical rule](#critical-rule-always-pair-psalm-taint-escape-with-psalm-flow) below.  |
| `@psalm-flow (<$params>) -> return`    | function-like        | Declares that taint propagates from params to return           | N/A (this IS the flow declaration)                                                                  |
| `@psalm-taint-escape (<conditional>)`  | function-like        | Removes a taint kind only when an argument matches a type      | **Yes**. See [Conditional escapes](#conditional-escapes-psalm-taint-escape-conditional).            |
| `@psalm-taint-unescape <kind>`         | function-like        | Re-adds a taint kind (reverses an earlier escape)              | Yes (same pattern as escape). No conditional form exists.                                           |
| `@psalm-taint-specialize`              | function-like, class | Tracks taints per call-site instead of globally                | No                                                                                                  |
| `@psalm-assert-untainted <$param>`     | function-like        | Severs ALL taint on the caller's variable after the call       | No. See [caveat](#psalm-assert-untainted) below.                                                    |

Scope matters. A tag on the wrong scope is silently ignored rather than reported:

- `@psalm-taint-source`, `@psalm-taint-sink`, `@psalm-flow`, and `@psalm-assert-untainted` are function-like only. Psalm's class docblock parser does not read them.
- `@psalm-taint-specialize` is read on both. On a class it sets `ClassLikeStorage::$specialize_instance` (`ClassLikeNodeScanner:715`), isolating taint per instance rather than per call-site.
- `@psalm-taint-escape` is function-like in Psalm core. This plugin additionally honours the **bare** form on a class docblock, but only for validation Rule classes, and only through its own reader. See [Per-rule escape on Rule objects](#per-rule-escape-on-rule-objects).

### `@psalm-assert-untainted`

`@psalm-assert-untainted $param` does not remove a taint *kind*. `ArgumentAnalyzer:985` calls `$input_type->setParentNodes([])`, detaching the argument's entire dataflow ancestry, so the caller's variable reads as clean for **every** kind from that point on. No `@psalm-flow` counterpart exists to narrow it. Reach for it only when a function's whole contract is "throws unless the argument is provably safe"; a validator that constrains a value should escape the specific kinds it constrains instead.

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

## Conditional escapes (`@psalm-taint-escape (<conditional>)`)

Some functions sanitize only for certain argument values. `filter_var($v, FILTER_VALIDATE_INT)` constrains its output to a digit string; `filter_var($v, FILTER_DEFAULT)` does nothing. A bare `@psalm-taint-escape` cannot express that split: it would escape on every call, including the ones that sanitize nothing.

Psalm's answer is the conditional form. `FunctionLikeDocblockParser:354` selects it purely on a leading `(`:

```php
} elseif ($param[0] === '(') {
    $info->conditionally_removed_taints[] = CommentAnalyzer::sanitizeDocblockType($line_parts[0]);
}
```

The canonical use is Psalm's own `filter_var()` stub (`vendor/vimeo/psalm/stubs/CoreGenericFunctions.phpstub:831`):

```php
/**
 * @psalm-pure
 *
 * 257 is FILTER_VALIDATE_INT
 * @psalm-taint-escape ($filter is 257 ? 'input_except_sleep' : null)
 *
 * 258 is FILTER_VALIDATE_BOOLEAN
 * @psalm-taint-escape ($filter is 258 ? 'input' : null)
 *
 * @psalm-flow ($value, $filter, $options) -> return
 */
function filter_var(mixed $value, int $filter = FILTER_DEFAULT, array|int $options = 0): mixed {}
```

### Semantics

- **The condition names a parameter of the annotated function**, not a class template. `getConditionalSanitizedTypeTokens()` rewrites each `$paramName` token into a synthetic `TGeneratedFromParam<offset>` template bounded by that parameter's declared type. This is the exact machinery behind conditional return types (`@return ($flag is true ? A : B)`), which calls the same helper. At each call site the template binds to the argument's *inferred* type, so the branch resolves from a literal argument and stays unresolved for a non-literal one.
- **The body must parse to a `TConditional`.** `FunctionLikeDocblockScanner:1191` throws `Escaped taint must be a conditional` otherwise, surfaced as `InvalidDocblock` on the stub.
- **The true branch is a taint kind as a literal string; the false branch is `null`.** Evaluation (`FunctionCallReturnTypeFetcher:568-592`) expands the resolved type and bails on `if (!$expanded_type->isNullable())`, then feeds every literal string through `$codebase->getOrRegisterTaint()`. So `null` means "no escape", and an unresolved branch (a non-literal argument leaves the conditional collapsed to a union containing `null`) also means no escape. **The failure direction is a false positive, not a false negative**, the same posture the rest of this guide asks for.
  Reading the Psalm 6 source instead, `getOrRegisterTaint()` is absent there; `FunctionCallReturnTypeFetcher:595-599` uses each literal's `->value` directly with no registration step.
- **Custom kind names work.** `getOrRegisterTaint()` accepts arbitrary strings, so `html_url` or `llm_prompt` slot into a conditional exactly like a built-in kind.
- **Multiple tags accumulate.** Each `@psalm-taint-escape (...)` line is evaluated independently and the resulting masks are OR-ed, which is why `filter_var()` lists one per `FILTER_*` constant instead of nesting one large conditional.
- **`@psalm-flow` is still mandatory**, for the same reason as the bare form: the escape node replaces the return node, so without a declared flow every other kind is dropped too. Note that `filter_var()`'s flow lists `$filter` and `$options` alongside `$value`.

Verified against Psalm 7.0.0-beta19 with a three-case fixture on `filter_var()`, plain Psalm, no plugin:

```php
$v = filter_var(taintSource(), FILTER_VALIDATE_INT);  // clean: literal 257 selects the escape
$v = filter_var(taintSource(), $runtimeFilter);       // TaintedHtml: condition unresolved, no escape
$v = filter_var(taintSource());                       // TaintedHtml: FILTER_DEFAULT sanitizes nothing
```

The middle case is the one worth remembering: an argument Psalm cannot resolve to a literal produces a report, never a silent pass.

### Limitation: instance method calls ignore the conditional form

Only two analyzers read `conditionally_removed_taints`: `FunctionCallReturnTypeFetcher` (plain function calls) and `StaticCallAnalyzer` (static calls). `MethodCallReturnTypeFetcher` reads `$method_storage->removed_taints` (`:536`) but contains **zero** references to `conditionally_removed_taints`, and `NewAnalyzer` does not read it either, even though it already consumes `if_true_assertions` (`:484`), so the hook point exists.

A stub backported to `3.x` inherits this limitation, so a conditional escape that silently fails on `master` fails there too.

The practical consequence for this plugin: a conditional escape on an *instance method* stub is parsed, stored, and then silently never applied. Since nearly every Laravel stub here is an instance method (`Builder::where()`, `Connection::escape()`, `Encrypter::encrypt()`), the conditional form is currently usable only in `stubs/common/**/helpers.phpstub` free functions and on static methods. Do not reach for it on an instance method and assume it works. Write the type test first and watch it fail.

When you need argument-dependent escaping on an instance method today, use a handler instead: `RemoveTaintsInterface` receives an `AddRemoveTaintsEvent` and `MethodCallReturnTypeFetcher:303` dispatches it on the return node of every method call, so the argument inspection happens in PHP where you can gate it however you like. `WhereColumnTaintHandler` is the worked example of argument-shape-dependent stripping (see [PDO parameterized queries](#pdo-parameterized-queries)). Extending Psalm core to consume `conditionally_removed_taints` in `MethodCallReturnTypeFetcher` and `NewAnalyzer` (mirroring `StaticCallAnalyzer:300-351`; Psalm 6: `StaticCallAnalyzer:312-333`) is a small upstream change and the right long-term fix; per the repo's upstream-first policy, propose it there before growing a handler that only exists to work around the gap. Since the gap exists identically in both majors, an upstream fix would need to land on both, or a `3.x` backport of a handler-based workaround stays necessary even after `master` is fixed.

### Where a conditional escape is the wrong tool

A conditional escape ties the escape to an argument of **the call being annotated**. It cannot express "this value is safe because some *other* object, constructed elsewhere, guarantees it". Runtime-guard packages have that shape: the guard is instantiated in one place, registered in a middleware array, and the protected call happens somewhere else entirely with no dataflow edge between them. No annotation closes that gap. The edge has to be synthesized by a handler first, and only then does an annotation on the guard's own method become meaningful.

## Taint kinds

Most taint kind names are defined in [`Psalm\Type\TaintKind::TAINT_NAMES`](https://github.com/vimeo/psalm/blob/master/src/Psalm/Type/TaintKind.php). Psalm's docblock parser also accepts arbitrary strings as taint kinds: anything not in that constant flows through `TaintedCustom` and reports as `Detected tainted <kind>`. The plugin uses this to model `html_url` (see [URL context vs HTML escaping](#url-context-vs-html-escaping-html_url)).

### Common kinds used in stubs

| Kind            | Attack vector                             | Example sink                                  | Example escape                                |
|-----------------|-------------------------------------------|-----------------------------------------------|-----------------------------------------------|
| `html`          | XSS via HTML injection                    | `echo`, `Response::make()`                    | `e()`, `htmlspecialchars()`                   |
| `has_quotes`    | Attribute injection via unquoted strings  | `echo` inside HTML attributes                 | `e()`, `urlencode()`                          |
| `html_url`      | XSS via URL-scheme injection in `<a href>` / `<img src>` (e.g. `javascript:`, `data:`) | `Notifications\Messages\MailMessage::action($url)` | App-defined URL allowlister (e.g. `Str::sanitizeUrl()`); NOT `e()` |
| `sql`           | SQL injection                             | `Connection::unprepared()`                    | `Connection::escape()`, parameterized queries |
| `shell`         | Command injection                         | `Process::run()`                              | `escapeshellarg()`                            |
| `ssrf`          | Server-side request forgery               | `Http::get($url)`                             | N/A                                           |
| `file`          | Path traversal                            | `Filesystem::get()`, `response()->download()` | N/A                                           |
| `user_secret`   | Password/token exposure in logs or output | `echo`, log sinks, `md5()`, `sha1()`          | `Hash::make()`, `Encrypter::encrypt()`        |
| `system_secret` | Internal secret exposure                  | `echo`, log sinks, `md5()`, `sha1()`          | `Hash::make()`, `Encrypter::encrypt()`        |

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
| `llm_prompt`         | `INPUT_LLM_PROMPT`         | Strings interpolated into LLM prompts (prompt injection)   |
| `input`              | `ALL_INPUT`                | Alias: all input-related kinds combined (excludes secrets) |
| `tainted`            | `ALL_INPUT`                | Alias: same as `input`                                     |
| `input_except_sleep` | `ALL_INPUT & ~INPUT_SLEEP` | All input kinds except `sleep` (used by `filter_var()`)    |
| `html_url`           | (custom, plugin-defined)   | URL emitted into an HTML attribute (`href`, `src`, …). Distinct from `html` because HTML-escaping (`e()`) blocks attribute breakout but NOT scheme injection (`javascript:`, `data:`). Distinct from `ssrf` because the threat is client-side XSS, not server-side request forgery. NOT a member of the `input` alias: must be sourced explicitly. |

## URL context vs HTML escaping (`html_url`)

`e()` (and `htmlspecialchars()`) escapes HTML special characters. That blocks attribute-breakout XSS like `"><script>alert(1)</script>`. It does NOT validate the URL scheme, so a value emitted into `<a href="{{ $url }}">` or `<img src="{{ $url }}">` can still execute as `javascript:alert(1)` or `data:text/html,...`. Filament shipped a stored-XSS fix for exactly this pattern (GHSA-3fc8-8hp6-6jr4), adding a separate `Str::sanitizeUrl()` helper that allowlists `http` / `https` / `mailto` / `tel` schemes and applying it across every URL-attribute renderer (`<a href>`, `<img src>`, and friends). Laravel's `MailMessage::action($url)` lands in the same `<a href="…">` shape via the notification email template, which is why the new sink targets it.

`html_url` models this cleanser-context distinction:

- `e()` escapes `html` and `has_quotes` only (see `stubs/common/Support/helpers.phpstub`). It does NOT escape `html_url`, so an `html_url`-tainted value that flows through `e()` is still flagged at an `html_url` sink.
- `Notifications\Messages\MailMessage::action($url)` is annotated with both `@psalm-taint-sink html` and `@psalm-taint-sink html_url`. The first catches body-content XSS (the URL is concatenated into HTML); the second catches scheme-injection inside the `<a href="…">` attribute.

### Detection gap: `html_url` is opt-in at the source

`html_url` is NOT a member of `TaintKindGroup::ALL_INPUT`. That means generic Laravel input sources (`$request->input(…)`, `$request->query(…)`, model attributes) do NOT auto-flow as `html_url`. The canonical Filament flow (form input → DB → Blade `{{ $url }}` → `<img src>`) will NOT be caught out of the box. You must mark the value at a boundary you trust:

```php
final class StoreAvatarRequest extends FormRequest
{
    public function rules(): array
    {
        return ['avatar_url' => ['required', 'url']];
    }

    /**
     * @psalm-taint-source html_url
     */
    public function avatarUrl(): string
    {
        return (string) $this->input('avatar_url');
    }
}
```

Anywhere this accessor is used and the value reaches an `html_url` sink without passing through an `html_url` escape, the plugin flags `TaintedCustom: Detected tainted html_url`.

### Annotating an app-level URL sanitizer

Laravel core ships `Str::isUrl($value, ['http', 'https'])` as a scheme-allowlisting *validator* (returns `bool`), but no first-party *sanitizer* that returns a cleaned string. To use `Str::isUrl()` as an `html_url` escape, wrap it in an app helper that returns the URL on `true` and a safe fallback (e.g. `'#'`) on `false`, then annotate the wrapper. If your app defines its own sanitizer (a `Str::macro('sanitizeUrl', …)`, an `HtmlUrl` value object, a dedicated helper), annotate that instead:

```php
/**
 * Allowlists http/https/mailto/tel; returns '#' for anything else.
 *
 * @psalm-taint-escape html_url
 * @psalm-flow ($url) -> return
 */
function safe_url(string $url): string
{
    return preg_match('#^(https?|mailto|tel):#i', $url) === 1 ? $url : '#';
}
```

The `@psalm-flow` line is mandatory. Without it `@psalm-taint-escape` drops every taint kind on the return value, including `html`, so a value that was tainted for both kinds would silently appear clean (see [Critical rule: always pair `@psalm-taint-escape` with `@psalm-flow`](#critical-rule-always-pair-psalm-taint-escape-with-psalm-flow)). The regression test `tests/Type/tests/TaintAnalysis/TaintedHtmlSanitizeUrlPreservesHtmlTaint.phpt` exercises this exact mutation.

A value passed only through `e()` (which escapes `html` and `has_quotes`) is still tainted for `html_url`; a value passed only through `safe_url()` (which escapes `html_url`) is still tainted for `html` and `has_quotes`. The two cleansers are not interchangeable. Test coverage for this contract lives in `tests/Type/tests/TaintAnalysis/TaintedHtmlUrl*.phpt` and `SafeHtmlUrl*.phpt`.

### Testing-time pitfall: Psalm's per-sink-node taint de-duplication

When two PHPT tests in the same suite source the same taint kind into the **same stubbed sink** (e.g. both flow `html_url` into `MailMessage::action()`), only one of the two will emit `TaintedCustom`. `TaintFlowGraph::connectSinksAndSources()` keeps a `visited_source_ids[$sink_node][$taint_mask]` set and skips repeated visits, so the first `(sink, mask)` pair reached during BFS wins the report and any subsequent source path to that same pair is silently dropped. The Tainted case using `e()` (`TaintedHtmlUrlEDoesNotEscape.phpt`) therefore routes through a per-file local sink instead of `MailMessage::action()`. The Safe test is unaffected: the sanitizer drops `html_url`, so the taint mask reaching the shared sink is `0`, which is a distinct dedupe key from any concurrent Tainted test's `html_url` mask. Use a local `@psalm-taint-sink html_url $url` helper whenever you need a second Tainted test against an already-covered sink.

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

#### Unsafe reflection (CWE-470) — container resolution

A user-controlled class name resolved through the container lets an attacker
instantiate arbitrary classes (constructor side effects, gadget chains). The
container entry points reuse the built-in `callable` kind, the same kind Psalm
applies to `new $var()` and dynamic invocation:

- `app($abstract)` / `resolve($name)` — `stubs/common/Foundation/helpers.phpstub`
- `Container::make($abstract)` / `Container::makeWith($abstract)` — `stubs/common/Container/Container.phpstub`

```php
/**
 * @psalm-taint-sink callable $abstract
 */
public function make($abstract, array $parameters = []) {}
```

The helper stubs (`app`, `resolve`) carry the sink only; their return type is
still produced by `ContainerHandler`. The bare `new $var()`, `$callback()`, and
`call_user_func()` forms in the issue are already caught by Psalm core's
`callable` sink combined with the plugin's `Request` taint sources, so no stub
is needed for those.

The `App::make(...)` facade form does **not** propagate taint — see
[Known limitation: Facade static calls](#known-limitation-facade-static-calls).
Use the `app()` / `resolve()` helpers or an instance typed as
`Illuminate\Container\Container` for analyzable code.

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

The same pattern applies to `orWhere()`, `whereNot()`, `orWhereNot()`, `having()`, and `orHaving()`. `whereLike()`, `orWhereLike()`, `whereNotLike()`, and `orWhereNotLike()` follow it too, minus the `$operator` arm.

Most of the **array** form is a false positive under the plain sink, because `Builder::addArrayOfWheres()` re-dispatches each element:

```php
if (is_numeric($key) && is_array($value)) {
    $query->{$method}(...array_values($value), boolean: $boolean);  // nested condition
} else {
    $query->{$method}($key, '=', $value, $boolean);                 // the key is the column
}
```

So only two positions still reach SQL as a raw identifier: the array KEY on the `else` branch, and — on the nested branch — `array_values()` ordinal 0 (`$column`). `Psalm\LaravelPlugin\Handlers\Eloquent\WhereColumnTaintHandler` removes the `sql` taint from ordinals 1 and 2 and from the `else`-branch value (#734/#733, #1300), CALL-SITE-SCOPED: a `BeforeExpressionAnalysis` hook records the nodes of where-family first arguments, and only those exact nodes are stripped (so an array that merely happens to have that shape elsewhere, in an assignment, a return, or an element read, keeps its taint). An array LITERAL is walked element-wise; any other argument falls back to a coarse type check that accepts only a sealed `TKeyedArray` with all-string keys.

Both the element-wise and whole-argument strips gate on the receiver being Laravel's own — a project's own class can name a `where()` method without going through `addArrayOfWheres()`. For a `MethodCall`/`NullsafeMethodCall` receiver this is a type check (`isLaravelBuilder`) run at strip time, once the receiver's type exists. A `StaticCall` receiver (`Model::where(...)`) is a class NAME, not a typed expression, and — despite an earlier assumption to the contrary — DOES route through the same stub sink via the pseudo-method (`__callStatic`) path, so it needs the same gate: resolved eagerly at record time (`isStaticReceiverLaravelBound`), accepting only a class that is or extends `Illuminate\Database\Eloquent\Model` or one of the builder classes, and recording nothing at all for an unresolved dynamic class (`$class::where(...)`) or a non-Laravel one (#1300).

Ancestry alone is not enough for a `StaticCall`, though: a Model subclass can declare its OWN concrete static `where()`, which PHP dispatches to directly, never reaching `__callStatic`/`addArrayOfWheres` — so its own sink, if any, must survive. `isStaticReceiverLaravelBound` additionally requires `Codebase::getDeclaringMethodId()` (default `with_pseudo: false`, so the plugin's own `@mixin`-injected forwarding is invisible to it) to find no REAL declaration outside `Illuminate\` for the called method name. Because the `StaticCall` gate is resolved eagerly at record time rather than at strip time from a re-inferred type, a trait method's `static::where([...])` — reanalysed once per class using the trait, reusing the SAME AST nodes each time — needs one more safeguard: a failed gate actively clears any ids an earlier pass (under a different `$context->self`) recorded for those same nodes, rather than merely skipping the record, so a later non-Model pass can never inherit a stale strip (#1300).

The strip covers `where`, `orWhere`, `whereNot`, `orWhereNot`, and `firstWhere` (exactly the methods whose array form routes through `addArrayOfWheres()`). It does **not** cover `having`/`orHaving`: despite sharing the flow pattern above, their array form never reaches `addArrayOfWheres()` (there is no `is_array($column)` branch), so an array column compiles raw and the sink must stand (issue #734 wrongly proposed including them). See the handler docblock. Do **not** "fix" it by dropping the sink (the string form is a real vector) or by adding `@psalm-taint-specialize` to these stubs, which silently breaks the non-SQL `@psalm-flow` on the value positions (see the specialize note below).

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

The known-broken candidates (`e()`, `encrypt()` / `decrypt()` and `*String` variants, `Connection::escape()`, `DB::escape()`) are tracked as follow-ups to #1007. Do not blanket-apply the annotation; treat every site as its own bisect. (`SessionGuard::hashPasswordForCookie()` was on this list but no longer applies: its escape moved to `GuardTaintHandler` and dropped the `@psalm-flow` propagation entirely — see #1113. The `Encrypter` class methods (`encrypt`/`encryptString`/`decrypt`/`decryptString`) likewise moved to `EncrypterTaintHandler` and are no longer stubs, so the specialize question does not arise for them; the handler preserves their `@psalm-flow` via `return_source_params`. The global `encrypt()` / `decrypt()` helpers remain function stubs in `helpers.phpstub` and are unaffected.)

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
- The bare form (`@psalm-taint-escape header`). The conditional form (`@psalm-taint-escape (...)`) names parameters of a function-like and has nothing to bind to on a class, so it is ignored here. See [Conditional escapes](#conditional-escapes-psalm-taint-escape-conditional).
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
- The spaceship operator `<=>` (compares byte-by-byte; its `-1`/`0`/`1` result leaks ordering like `strcmp()`)
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
3. **Choose the correct annotation type and confirm it applies at that scope**: source, sink, escape, or flow. See [Annotations quick reference](#annotations-quick-reference). A tag on the wrong scope is ignored silently, never reported.
4. **If using `@psalm-taint-escape` or `@psalm-taint-unescape`**: always add `@psalm-flow` to preserve other taint kinds (unless the return value's other taints are truly irrelevant)
5. **If the escape depends on an argument value**: use the conditional form, and check first that the stub is a free function or a static method. Instance methods parse it and silently never apply it. See [Conditional escapes](#conditional-escapes-psalm-taint-escape-conditional)
6. **If using `@psalm-flow` on a method returning a concrete value (model, scalar, or collection)**: add `@psalm-taint-specialize` to prevent cross-call-site taint pollution, then run the existing `Tainted<NonEscapedKind>*` test for the stub to confirm within-callsite flow still propagates. The combination is not mechanically safe on every stub shape in Psalm 7 — see [Flow-through factories need `@psalm-taint-specialize`](#flow-through-factories-need-psalm-taint-specialize) for the empirical-verification protocol
7. **Match parameter types exactly** to Laravel's signatures. Do not narrow types.
8. **Place in `stubs/common/`** under a path matching the Laravel namespace
9. **Keep taint and type annotations together**. If a method already has type stubs, add taint annotations to the same file (see [Stub merging](README.md#stub-merging-how-psalm-combines-annotations))

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

A facade declares its forwarded surface as class-level `@method` tags resolved through `__callStatic`. Psalm models those as *pseudo* methods, and a pseudo parameter cannot carry `@psalm-taint-sink`: a sink is a per-parameter bitmask populated only from a real docblock, and a `@method` tag has no per-parameter docblock. The annotated methods live on the class the facade forwards to, which the static-call path never consults.

Two mechanisms close this:

- `FacadeTaintForwardingHandler` copies the target class's parameter sinks onto the facade's pseudo-methods once the codebase is populated. It covers `Storage`, `File`, `Artisan`, `Redirect`, `Response`, `Http`, `Process`, and `View`, and the generated root aliases (`\Storage::get(...)`) inherit the same pseudo-method storage, so they are covered too.
- A hand-written facade stub declaring the forwarded methods as real statics, as in `stubs/common/Support/Facades/DB.phpstub`. Heavier to maintain (every signature is restated and can drift from Laravel), so prefer the handler unless the facade also needs type overrides.

The limitation still applies to any facade covered by neither: user-defined facades, and core facades outside the map (`App::make(...)` is the notable one). Calling the underlying class directly always works, because the receiver is the real annotated class: `DB::connection()->unprepared(...)`, `Storage::disk()->get(...)`.

A variadic sink covers only its first argument, on either call form. `Filesystem::delete()` and `FilesystemAdapter::delete()` accept an array or variadic string arguments (`func_get_args()` at runtime), so their stubs carry `@psalm-variadic` next to the sink. That tag only relaxes the arity check and does not spread the sink across the extra arguments, so `Storage::disk('local')->delete($safe, $tainted)` goes unreported. The static facade form misses it too, because a flat `@method` tag cannot express variadic at all, though there the call is at least loud (Psalm reports `TooManyArguments`). Prefer the array form, `delete([$safe, $tainted])`, which is fully covered on both paths.

To cover another core facade, add `Facade::class => [TargetClass::class]` to `FacadeTaintForwardingHandler::FACADE_TARGETS`, confirm against `vendor/laravel/framework` that the facade's `@method` tags mirror the target's real signatures (the copy matches on both parameter offset and parameter name, so a drifted tag is skipped rather than mis-assigned), and add a `.phpt` under `tests/Type/tests/TaintAnalysis/`.

## LLM prompt-injection sinks (`laravel/ai`)

The `llm_prompt` taint kind models OWASP LLM01:2025 (direct + indirect prompt injection). Annotations are applied in two layers, depending on what the sink shape allows. Both layers are a worked instance of the scope rule in [Annotations quick reference](#annotations-quick-reference): the tags are function-like only, so anything that is not a function parameter needs a handler instead.

### Parameter sinks (docblock annotation works)

Methods that accept the prompt as a named parameter are annotated normally:

```php
trait Promptable
{
    /**
     * @psalm-taint-sink llm_prompt $prompt
     */
    public function prompt(string $prompt, ...): AgentResponse {}
}
```

Same shape is used on `Promptable::stream()`, `queue()`, `broadcast*()`, the `\Laravel\Ai\agent()` factory, `AgentPrompt::prepend()`/`append()`/`revise()`, `Embeddings::for()`, `Tools\Document::fromString()/fromBase64()`, `Messages\UserMessage`, and `Messages\Message::__construct()`.

### Property-source pattern: `$response->text` (handler required)

Psalm honors `@psalm-taint-source` on **method return types** but not on **properties**: `PropertyStorage` carries no taint fields at all, so the annotation is dropped at scan time rather than misapplied. The model's `$text` output is downstream of every untrusted input that reached the prompt (indirect prompt injection via web pages, RAG corpora, tool output, attacker emails — see EchoLeak CVE-2025-32711), so we need to taint property reads programmatically.

`src/Handlers/Ai/LlmOutputTaintHandler.php` subscribes to `AfterExpressionAnalysisEvent`, matches the read, and calls `Codebase::addTaintSource()` to add the `ALL_INPUT` taint to the expression's type. `TAINTED_PROPERTIES` maps each property name to the classes that declare it, rather than crossing one class list with one property list, because the two properties sit on different parts of the hierarchy:

- `$text` on `Laravel\Ai\Responses\{TextResponse, AgentResponse, StreamedAgentResponse, StreamableAgentResponse, TranscriptionResponse}`.
- `$structured` on `Laravel\Ai\Responses\{StructuredAgentResponse, StructuredTextResponse}`, the decoded payload the `ProvidesStructuredResponse` trait declares as a public array. laravel/ai's own `ChatCommand` reads it, so it is a first-class access path rather than an internal.

The subclass walk is load-bearing, not a courtesy: `StructuredAgentResponse` and `StructuredTextResponse` both inherit `$text` from `TextResponse` and are tainted through it, even though neither is named in the `$text` list. `TranscriptionResponse` is named explicitly because it sits in its own hierarchy (it does not extend `TextResponse`), so no walk reaches it.

Casts are a separate path, and missing one is easy: `__toString()` is a method return, so it takes a plain stub annotation, but a subclass that overrides `__toString()` drops the parent's. Each of `TextResponse`, `AgentResponse`, `StreamableAgentResponse`, `TranscriptionResponse`, `StructuredAgentResponse` and `StructuredTextResponse` therefore carries its own. Adding a class to `TAINTED_PROPERTIES` covers the property read only; check whether the class also declares `__toString()` and needs the stub.

### Array-access sources do not work: `$response['field']` (upstream gap)

`@psalm-taint-source` on `offsetGet()` sources an explicit `$response->offsetGet('field')` call and nothing else. Psalm's `ArrayFetchAnalyzer` resolves the `$response['field']` sugar by synthesizing a `VirtualMethodCall` to `offsetGet()`, analyzing it against a **cloned** node-data set, then copying back only the resulting type. Everything that call recorded about data flow stays in the clone and is discarded, so no edge reaches the outer expression. Type inference is unaffected, which is why the gap is invisible without a taint test.

The loss is not specific to source annotations: any taint edge crossing the sugar is dropped, including a plain argument-to-return pass through `offsetGet()`. This is a Psalm core soundness bug, not a Laravel one, and it silently breaks every `ArrayAccess`-based taint source in any codebase. Filed upstream as [vimeo/psalm#11912](https://github.com/vimeo/psalm/issues/11912); `#1304` here was closed for the same reason. Do not add an annotation and assume the sugar is covered.

An `ArrayDimFetch` branch on `LlmOutputTaintHandler` would close it, and was prototyped, but is deliberately not shipped: `ArrayDimFetch` is among the hottest node types in any codebase, so the branch charges a per-expression cost on every analysis, and it becomes dead code the moment upstream lands. Keep the `offsetGet()` annotations anyway, since the explicit call form is genuinely covered by them.

What that costs, pinned by fixtures rather than left implicit:

- `SubAgentToolDelegationKnownLimitation.phpt`: `Tools\AgentTool::handle()` forwards `(string) $request['task']` straight into a sub-agent's `prompt()`, so the whole delegation chain is silent.
- `StructuredResponseArrayAccessKnownLimitation.phpt`: `$response['field']` on the structured responses.
- `StructuredArrayReturnKnownLimitation.phpt` is a *different* gap with the same shape of consequence. Both the `toArray()` return and the `$structured` property are sourced, but reading one element back out of the resulting array loses the edge under whole-project analysis. The same fixtures report the flow when Psalm analyzes the file alone, so it is the hop-loss bug rather than a missing source.

All three assert the current (wrong) behavior with empty expectations, so an upstream fix turns them red and names itself.

The practical consequence for writing positive coverage: a source whose type is an array needs a zero-hop sink in the fixture, or the test passes vacuously in the batch while looking like it proves something. `StructuredPayloadProperty.phpt` passes the whole payload to `extract()` for exactly that reason.

The handler is registered in `Plugin::registerHandlers()` behind the same version gate as the stubs, and self-disables when `Codebase::$taint_flow_graph === null`, so it costs nothing on non-taint runs.

Note the mechanism split: this handler re-sources from `AfterExpressionAnalysisEvent`, while the plugin's other property-read taint path (`ValidationTaintHandler`) implements `AddTaintsInterface`, which Psalm dispatches from `AtomicPropertyFetchAnalyzer` for every property fetch. Prefer `AddTaintsInterface` for new property-taint work: it hooks the taint bitmask Psalm already threads through the data-flow edge, instead of rewriting the expression type afterwards. `AddTaintsInterface` also fires twice per node (property-read pass and argument-binding pass), so it needs per-node dedupe that the `AfterExpressionAnalysis` route avoids.

### Return-value sinks are not yet expressible

`Tool::description()` and `Agent::instructions()` produce values that the framework later concatenates into the LLM prompt (the static signature of MCP-style tool poisoning, CVE-2025-54136). The natural annotation shape is "the return value is a sink," but Psalm's docblock scanner only matches **parameter names** for `@psalm-taint-sink`. The `return` token is silently dropped; the annotation is inert.

These return-value sinks are intentionally not annotated in stubs today (the comment in the stub says so). Coverage requires a dedicated `AfterMethodCallAnalysisInterface` / `MethodReturnTypeProvider`-style handler that wires the return expression into a synthetic sink — tracked in `#484`.
