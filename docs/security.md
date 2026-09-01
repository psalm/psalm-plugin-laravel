---
title: Security (Taint) Checks
nav_order: 6
---

# Security (Taint) Checks

### What it detects

| Vulnerability   | OWASP    | Examples                                                      |
|-----------------|----------|---------------------------------------------------------------|
| SQL Injection   | A03:2021 | `DB::statement()`, `DB::unprepared()`, raw query methods      |
| Shell Injection | A03:2021 | `Process::run()`, `Process::command()`                        |
| XSS             | A03:2021 | `Response::make()` with unescaped content                     |
| SSRF            | A10:2021 | `Http::get()`, `Http::post()` with user-controlled URLs       |
| File Traversal  | A01:2021 | `Storage::get()`, `File::delete()` with user-controlled paths |
| Open Redirect   | A01:2021 | `redirect()`, `Redirect::to()` with user-controlled URLs      |
| Crypto misuse   | A02:2021 | Tracks encryption/hashing taint escape and unescape           |
| Timing attack   | A02:2021 | Secret compared with `===`, `<=>`, `strcmp()` (CWE-208)       |
| Prompt injection | LLM01:2025 | `laravel/ai` agents and prompt sinks (enforced by default when the supported integration is installed; [`findPromptInjection`](config.md#findpromptinjection) can explicitly suppress D-in findings; an annotated guard in the agent's middleware exempts the call site) |
| LLM output reuse | LLM01:2025 | Model output as a source: `$response->text`, `$response->structured`, response string casts, `toArray()` / `toJson()` / `jsonSerialize()`, tool results |

`UploadedFile::getClientOriginalExtension()` is deliberately not a `file` source:
Symfony's `File::getName()` and `UploadedFile::getClientOriginalExtension()` yield a
slash-, backslash-, and dot-free extension, which cannot form a traversal segment.

`UploadedFile::clientExtension()` is deliberately not a taint source. The client
chooses the MIME lookup key, but Symfony returns a value from the application's
configured MIME registry. This avoids false positives for generated upload
filenames; applications that populate that registry from untrusted data must model
that boundary separately.

Security scanning runs automatically alongside type analysis, no extra configuration needed.

### `ResponseFactory::make()` and `new Response()` HTML responses

`ResponseFactory::make()` and the `Illuminate\Http\Response` constructor both
report XSS for unescaped content because their default response is HTML. The
finding is dropped for a positional call whose headers array proves either
that the browser downloads the response instead of rendering it, or that the
declared content type is never sniffed as HTML. It is dropped as it is
reported, so no other flow through the same code is affected.

The proof is deliberately syntactic, never control-flow aware. Two independent
checks over the header entries; either alone is enough:

- **Attachment disposition.** A literal `Content-Disposition: attachment`
  value, or an interpolated or concatenated value whose literal leading part
  already contains the `attachment;` token: nothing after a literal parameter
  separator can retract it. Control characters other than a horizontal tab
  keep the finding, since a runtime that drops the header serves the response
  as HTML. Parameters after the token are not validated.
- **Safe content type.** A literal, well-formed `Content-Type` that does not
  contain `html`, `xml` (XML can carry an XHTML-namespaced `<script>`) or
  `script` (the JavaScript media types), is not `multipart/*`, and is not one
  of the sniffing escapes `unknown/unknown` and `application/unknown`. Every
  other well-formed type is exempt, vendor download types included; parameters
  after the first `;` are discarded.

The headers array may be written inline or held in a local variable assigned
exactly once, as a plain statement before the call, and never reassigned,
mutated, passed elsewhere, or captured. Anything the proof cannot see keeps
the finding: `$$x`, the `extract()` family in the same scope, dynamic or
duplicate or underscore-spelled headers (names are folded the way Symfony
folds them), a malformed content type, and every named-argument call. The
exemption applies to the concrete factory, its contract, the
`Illuminate\Support\Facades\Response` facade (intersections included), and a
direct `new Illuminate\Http\Response(...)`. The root `\Response` alias and
custom classes with a `make()` method keep the sink. A receiver typed as the
factory or its contract is trusted to apply its headers argument; an
implementation that silently discards it violates that contract and is out of
scope.

One accepted gap. All `make()` calls in a project meet at a single taint graph
node (and, separately, all `new Response()` calls meet at their own), and
Psalm walks each node once, so it already reports only the shortest flow
reaching it and discards the rest. When that shortest flow is the exempt one,
the call reports nothing at all instead of reporting one of its flows. The
longer flows are discarded whether or not the exemption applies, so this costs
no coverage relative to running without the plugin.

### Known limitation: named arguments

Psalm keys a named argument's taint node by the argument's written position rather than by the
parameter it names ([vimeo/psalm#11923](https://github.com/vimeo/psalm/issues/11923)), so taint
can be reported against the wrong parameter. Until that is fixed upstream, the plugin drops
taint from a named argument it cannot prove is attributed correctly.

Detection is unaffected when the callee is statically known (a plain function, a facade, a
static call, a constructor, or a method on a receiver typed as exactly one class) and the
argument names the parameter at its own position, which covers ordinary application code. It is
lost for a dynamic callee, a receiver Psalm cannot resolve to a single class (including a
chained call such as `Storage::disk('local')->put(path: $input)`, where the receiver is an
expression rather than a variable), an argument captured by a variadic, and a `static::` call
resolved through a subclass override. Passing the same values positionally always reports.

### Timing-unsafe secret comparison (CWE-208)

Comparing a secret (a password hash, remember-token, or decrypted value) with a
variable-time operator leaks it byte-by-byte to an attacker who can measure
response time. The plugin flags secret-tainted values that flow into `===`, `==`,
`!==`, `!=`, `<=>`, or the `strcmp()` / `strcasecmp()` / `strncmp()` /
`strncasecmp()` / `substr_compare()` family. Use `hash_equals()` for a
constant-time comparison instead.

```php
$user->getAuthPassword() === $given;            // flagged
hash_equals($user->getAuthPassword(), $given);  // safe
```

Comparisons against a literal (`$token === null`, `$key === ''`) are not flagged:
the literal is the known half, so nothing about the secret leaks.

The finding is reported as `TaintedUserSecret` or `TaintedSystemSecret`, and the
flagged location is the comparison itself. The message text is the generic
`Detected tainted user secret leaking` rather than a CWE-208-specific one, because
Psalm hardcodes taint messages per kind ([vimeo/psalm#11762](https://github.com/vimeo/psalm/issues/11762)).
Treat any such finding from this plugin as a timing issue and fix it with
`hash_equals()`.

### LLM prompt injection (OWASP LLM01:2025)

Applies to projects using `laravel/ai`. The stubs and the LLM-output handler load
only when that package is installed and satisfies `>=0.11.0 <1.0.0`, so projects
without it pay nothing.

Two directions are covered. Both are errors by default; the explicit opt-out only
suppresses the D-in `TaintedLlmPrompt` issue.

* Untrusted input reaching a prompt is reported as `TaintedLlmPrompt` at the
  normal error level by default when the supported `laravel/ai` integration is
  installed. Set [`findPromptInjection`](config.md#findpromptinjection) to
  `false` only to suppress this D-in issue; an explicit issue handler still wins.
  The integration gate is `laravel/ai >=0.11.0 <1.0.0`, and `true` cannot bypass
  it. Sinks are
  `Promptable::prompt()` / `stream()` / `queue()` / `broadcast*()`, the
  `Laravel\Ai\agent()` helper, `AgentPrompt::prepend()` / `append()` / `revise()`,
  `Files\Document::fromString()` / `fromBase64()`,
  `PendingReranking::rerank()`, and the
  `Messages\UserMessage` / `Messages\Message` constructors.
* Model output is itself a taint source, reported as an error out of the box and
  unaffected by `findPromptInjection`: these findings have the ordinary fix
  (parameterize, escape). Reading `$response->text` (or casting the
  response to string) yields tainted data, so an answer echoed into HTML, SQL, or a
  shell command is reported like any other user input. That models indirect prompt
  injection, where the payload arrives through a page, document, or tool result the
  model read. Transcripts count on both paths (`TranscriptionResponse::$text` and
  the string cast): the audio was supplied by a user, so the transcript is
  attacker-authored text a speech model merely re-typed.
* Structured output is a source on the same footing. On
  `StructuredAgentResponse` / `StructuredTextResponse`, the covered reads are the
  `$structured` property, `toArray()`, `toJson()`, `jsonSerialize()`, the string
  cast, and an explicit `offsetGet()` call. The keys come from the application's
  schema, the values come from the model.

`prompt()` and `stream()` are exempted on an agent whose middleware stack declares a
guard, so a project that already runs a prompt-injection filter is not asked to
suppress the finding by hand. The provable shape, all four parts required:

1. The agent class implements `Laravel\Ai\Contracts\HasMiddleware` (inherited counts).
   Without the interface `laravel/ai` never calls `middleware()`, so the stack is dead code.
2. Its `middleware()` has a declared return type naming the element class, e.g.
   `@return list<PromptGuard>`. A bare `array` proves nothing and keeps the finding.
3. That element class declares `handle()`, the one method the middleware pipeline invokes.
4. That `handle()` carries `@psalm-taint-escape llm_prompt`.

Part 4 is the opt-in: nothing is exempted until a guard's author (or the application,
on its own guard) writes that line, and no guard package is named in the plugin. It is a
policy, not a proof, on the same trust model as every other `@psalm-taint-escape`: it
records that a mitigation is attached, not that a given payload is neutralised. Whether
the guard blocks or only logs is usually runtime configuration and is not statically
distinguishable, and the middleware list is read from the declared return type rather than
the method body. `queue()` and `broadcast*()` run the same pipeline but are not exempted
yet, so they keep reporting.

Two shapes are not covered. Each is an upstream limitation rather than a
judgement that the flow is safe, so treat them as blind spots when reviewing.

Return-value sinks (`Tool::description()`, `Agent::instructions()`) are not covered
yet: Psalm's `@psalm-taint-sink` matches parameter names only. Tracked in
[#484](https://github.com/psalm/psalm-plugin-laravel/issues/484).

Array-access reads (`$response['field']`, `$request['task']`) are not covered
either, on any class: Psalm drops the taint edge when it resolves the `[]` sugar,
which affects every `ArrayAccess`-based taint source and is left for an upstream
fix ([vimeo/psalm#11912](https://github.com/vimeo/psalm/issues/11912)). It is worth
knowing about, because `Tools\AgentTool` uses exactly that shape to pass a task to
a sub-agent. Prefer `Tools\Request::str()` / `string()` / `array()`, or an explicit
`offsetGet()` call, all of which are covered.

Reading a single element back out of an array-typed source is covered. Both the
`$structured` property and the `toArray()` return keep the taint through
`$payload['body']`, so a sink reached that way reports like any other flow.

### How it compares

| Tool              | Laravel-aware types | Taint analysis     | Free               |
|-------------------|---------------------|--------------------|--------------------|
| **psalm-laravel** | Yes                 | Yes (dataflow)     | Yes                |
| Larastan          | Yes                 | No (PHPStan can't) | Yes                |
| SonarQube         | Generic PHP         | Yes (generic)      | Paid editions only |
| Semgrep           | Pro tier only       | Pattern-based      | Limited free tier  |
| Snyk Code         | Generic             | Yes (generic)      | Freemium           |
