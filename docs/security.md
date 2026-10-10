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
| XSS (Blade templates) | A03:2021 | `{!! $userInput !!}`                                    |
| Header Injection | A03:2021 | `Response::make()`, `response()`, and `new Response()` with user-controlled header values |
| SSRF            | A10:2021 | `Http::get()`, `Http::post()` with user-controlled URLs       |
| File Traversal  | A01:2021 | `Storage::get()`, `File::delete()` with user-controlled paths |
| Open Redirect   | A01:2021 | `redirect()`, `Redirect::to()` with user-controlled URLs      |
| Crypto misuse   | A02:2021 | Tracks encryption/hashing taint escape and unescape           |
| Timing attack   | A02:2021 | Secret compared with `===`, `<=>`, `strcmp()` (CWE-208)       |
| Prompt injection | LLM01:2025 | `laravel/ai` prompt sinks: agent prompts, messages, media, documents, reranking, classification, and tool descriptions (enforced by default when the supported integration is installed; [`findPromptInjection`](config.md#findpromptinjection) can explicitly suppress these findings; an annotated guard in the agent's middleware exempts the call site, except for agents that remember conversations) |
| LLM output reuse | LLM01:2025 | `laravel/ai` model-output and tool-result sources: response text, reasoning, structured output, streamed deltas, transcripts, and classification answers |

`UploadedFile::getClientOriginalExtension()` is deliberately not a `file` source:
Symfony's `File::getName()` and `UploadedFile::getClientOriginalExtension()` yield a
slash-, backslash-, and dot-free extension, which cannot form a traversal segment.

`UploadedFile::clientExtension()` is deliberately not a taint source. The client
chooses the MIME lookup key, but Symfony returns a value from the application's
configured MIME registry. This avoids false positives for generated upload
filenames; applications that populate that registry from untrusted data must model
that boundary separately.

Security scanning runs automatically alongside type analysis, no extra configuration needed.

`json_encode()` with literal HEX flags is treated as an escape. `JSON_HEX_TAG` clears the html taint (`TaintedHtml`): the output can no longer contain `<` or `>`. `JSON_HEX_APOS` together with `JSON_HEX_QUOT` also clears the quoted-text taint (`TaintedTextWithQuotes`), because every `'` and `"` in the payload is encoded as `\u0027` / `\u0022`. Either flag alone keeps it: without `JSON_HEX_QUOT` a `"` becomes `\"`, and a backslash escapes nothing in HTML. The Blade `@json($value)` directive defaults to all four HEX flags, so it reports nothing. Flags the analyzer cannot prove literal (a variable of unknown value, `(int) $request->input()`, a spread argument) and calls without these flags stay fully reported.

Known gap: `json_encode()`'s own `"` delimiters still close a double-quoted attribute or JS string, so `<div data-x="@json($value)">` is an unreported attribute breakout. It is accepted because such markup is visibly broken for every string, array or object value, so it rarely survives manual testing. Ints, bools and null encode bare and render fine, so a field that is numeric during testing can hide it. Single-quoted attributes (`data-x='@json($value)'`) and `<script>` blocks are safe with these flags. `@js($value)` / `Js::from($value)` is a complete JavaScript expression (never wrap it in quotes) that fits a script or a double-quoted attribute.

Blade template scanning is opt-in: enable it with `<blade />` to also get `TaintedHtml` findings on unescaped `{!! !!}` output inside `.blade.php` files. See [Blade template analysis](blade.md).

### `ResponseFactory::make()` and `new Response()` HTML responses

`ResponseFactory::make()`, the `response()` helper's direct 3-argument form,
and the `Illuminate\Http\Response` constructor all report XSS for unescaped
content because their default response is HTML. All three also sink their
`$headers` argument as `header`: a tainted header value lets an attacker
inject or override response headers (for example a `Content-Disposition`
filename), independent of the content sink below. The attachment/content-type
exemption applies only to the `TaintedHtml` content finding and never
suppresses a genuinely tainted header value.

For `ResponseFactory::make()` (in its direct, contract, and facade forms) and
the `Illuminate\Http\Response` constructor, the XSS finding is dropped for a
positional call whose headers array proves either that the browser downloads
the response instead of rendering it, or that the declared content type is
never sniffed as HTML. It is dropped as it is reported, so no other flow
through the same code is affected. The `response()` helper's direct 3-argument
form is not part of this exemption and always keeps its XSS finding.

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

### Known limitation: named arguments captured by a variadic

Two Psalm bugs ([vimeo/psalm#12251](https://github.com/vimeo/psalm/issues/12251),
[#12252](https://github.com/vimeo/psalm/issues/12252)) misattribute a named argument bound to a
variadic parameter. The plugin drops the taint of such an argument when the callee resolves
exactly to a concrete method or function, so a genuine sink in the variadic's body or behind a
re-spread (`handle(...$args)`) is not reported. Every other named-argument call keeps full detection.

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

Applies to projects using `laravel/ai`. The stubs, LLM-output handler, prompt-guard handler, and
prompt-injection issue policy load together only when that package is installed and satisfies
`>=1.0.0 <2.0.0`, so projects without a supported package pay nothing. `laravel/ai` 0.11.x is not
supported: the integration stays disabled and the plugin contributes nothing for it (no stubs, no
handlers, no issue policy).

Two directions are covered. Both are errors by default; the explicit opt-out only suppresses the
prompt-sink issue (`TaintedLlmPrompt`).

* **Prompt sinks.** Untrusted input reaching a sink is reported as `TaintedLlmPrompt`. Set
  [`findPromptInjection`](config.md#findpromptinjection) to `false` only to suppress this issue; an
  explicit issue handler still wins, and `true` cannot bypass the version gate.
* **Model-output sources.** Model output reaching SQL, HTML, a shell command, and the like is
  reported as the ordinary `Tainted*` issue with the ordinary fix (parameterize or escape).
  `findPromptInjection` does not affect these. This models indirect prompt injection, where the
  payload arrives through a page, document, or tool result the model read.

Sink kinds (one example each):

* **Prompting an agent.** `$agent->prompt($input)`.
* **Ad-hoc agents, messages, and tools.** `agent()` instructions, `new UserMessage($input)`.
* **Prompt mutation.** `PendingStep::withInstructions()` inside middleware.
* **Classification questions.** `Classification::of($question)`.
* **Media and document factories.** `Image::of($description)`.
* **Tool descriptions.** `SimilaritySearch::withDescription()`.

Source kinds (one example each):

* **Response text.** `AgentResponse::$text`, including its string cast.
* **Reasoning.** `TextResponse::$reasoning`.
* **Structured payloads.** `StructuredAgentResponse::$structured`.
* **Streamed deltas.** `TextDelta::$delta`.
* **Transcripts.** `TranscriptionResponse::$text` (and its string cast). The audio was supplied by a
  user, so the transcript is attacker-authored text a speech model merely re-typed.
* **Classification answers.** `ClassificationResponse::answer()`.
* **Tool results.** `Contracts\Tool::handle()` returns a source so tool output keeps flowing into
  later prompt sinks.

This is a summary. The authoritative, current lists are the taint annotations under
`stubs/integrations/laravel-ai/` and `TAINTED_PROPERTIES` in
`src/Handlers/Ai/LlmOutputTaintHandler.php`.

#### Marking prompt-guard middleware as trusted

For middleware authors (a guard library, or an app with its own guard): use this when your
middleware genuinely stops prompt injection before the prompt reaches the provider, so users need
not suppress the finding by hand. Two phpdoc annotations:

1. On the guard, annotate `handle()` with `@psalm-taint-escape llm_prompt`. `laravel/ai` calls
   `handle(PendingStep $step, Closure $next)` once per generation step on each non-closure
   middleware entry (a class-string entry is resolved through the container first).
2. On the agent's unchanged `middleware()` method, declare a `@return` naming your guard class:
   `@return list<PromptGuard>` (`@return list<class-string<PromptGuard>>` works too). The agent
   must implement `HasMiddleware` (inherited counts); otherwise `laravel/ai` never calls
   `middleware()`.

A guard wraps each generation step:

```php
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Promptable;

final class PromptGuard
{
    /**
     * @psalm-taint-escape llm_prompt
     * @psalm-flow ($step) -> return
     */
    public function handle(PendingStep $step, \Closure $next): mixed
    {
        return $next($step);
    }
}

final class SupportAgent implements HasMiddleware
{
    use Promptable;

    /** @return list<PromptGuard> */
    public function middleware(): array
    {
        return [new PromptGuard()];
    }
}
```

What it does: `TaintedLlmPrompt` stops being reported for `prompt()` and `stream()` on that agent.
Every other taint kind keeps flowing, so the same value reaching SQL, HTML, or a shell command is
still reported.

The `@psalm-flow` line does not affect the exemption, which reads only the escape. Include it
anyway: an escape with no `@psalm-flow` leaves the return value carrying no taint at all, not just
no `llm_prompt` (see
[the pairing rule](contributing/taint-analysis.md#critical-rule-always-pair-psalm-taint-escape-with-psalm-flow)),
so a direct caller of the guard would read a fully clean value when the body is not visible to Psalm.

Not exempted (the finding keeps reporting):

* **Closure middleware.** The guard lives in the closure body, which no declared type describes.
  Write the guard as a class.
* **No `@return` on `middleware()`.** A bare `array` names nothing to check.
* **An escape anywhere but `handle()`.** `laravel/ai` never calls `__invoke()` on a middleware
  object, so a guard whose `handle()` is unannotated does not exempt, even if `__invoke()` carries
  the escape. The same applies to a guard class whose analysed subclass overrides `handle()`
  without the annotation.
* **An agent whose `middleware()` a subclass replaces.** The call site only names the parent, and
  the subclass could run a different stack. Calling on the subclass directly is still exempt when
  it keeps the guarded stack.
* **Agents that remember conversations.** This is intended, not a limit of the annotation. An agent
  remembers conversations when it uses the `Concerns\RemembersConversations` trait (anywhere up its
  parent chain), implements `Contracts\RemembersConversations`, or has an analysed subclass that
  adds either. `laravel/ai` then names each new conversation with a second model call carrying the
  first 500 characters of the raw prompt, and that call runs no agent middleware, so the guard never
  sees it. Setting `ai.conversations.generate_title` to `false` removes the second call, but the
  plugin cannot read that setting: suppress the finding explicitly once you have turned title
  generation off.
* **`queue()` and `broadcast*()`.** They run the same pipeline but are not exempted yet.

The exemption is a declaration the plugin trusts, not proof that a payload is neutralised or that a
guard blocks rather than logs. It is bounded by what the analysis can see: the middleware list comes
from the declared `@return` type, not the method body (so an empty-array body still exempts, and a
template bound such as `class-string<T>` names no guard and does not), and subclasses outside the
analysed project (a library consumer's own) or container bindings that swap a class-string entry are
invisible. Mechanics: [`docs/contributing/taint-analysis.md`](contributing/taint-analysis.md).

Known limitations. Each is an upstream limitation rather than a judgement that
the flow is safe, so treat it as a blind spot when reviewing.

* **Return-value sinks.** `Tool::description()` and `Agent::instructions()` are
  not covered: Psalm's `@psalm-taint-sink` matches parameter names only. Tracked
  in [#484](https://github.com/psalm/psalm-plugin-laravel/issues/484).
* **Prompt macros.** `AiServiceProvider` registers several macros that forward
  their input to a model, and none of them is a sink: `Str::summarize()` and
  `Stringable::summarize()` (they prompt a `SummarizeAgent`),
  `Stringable::toAudio()` (it calls `Audio::of()`), `Str::decide()`,
  `Stringable::decide()`, and `Collection::decide()` (it calls
  `CollectionChoice::decide()`), and `Collection::rerank()` (it calls
  `PendingReranking::rerank()`, which the plugin does sink when called
  directly). A macro is a `Macroable` pseudo-method with no per-method docblock
  on which to attach an `llm_prompt` sink, so `Str::summarize($request->input('d'))`
  and `collect($items)->rerank('name', $query)` report nothing even when the
  argument is tainted. Closing the gap takes a call-analysis handler for the
  macro forms, or Psalm support for taint annotations on registered macro
  signatures. The `decide()` form is pinned by
  `tests/Type/tests/PromptInjection/ClassificationDecideKnownLimitation.phpt`
  and the list is documented in `stubs/integrations/laravel-ai/Classification.phpstub`.
  Call the underlying class (`Classification::of()`, `Audio::of()`,
  `Reranking::of()`, or an agent's `prompt()`) where the input is untrusted.
* **Citation collection reads.** `StreamableAgentResponse::$citations` is a
  registered source, but no end-to-end flow is observable through its
  `Collection` reads because Psalm drops the taint edge there. The handler
  caveat in `src/Handlers/Ai/LlmOutputTaintHandler.php` records this gap.
* **Array-access reads.** `$response['field']` and `$request['task']` are not
  covered on any class: Psalm drops the taint edge when it resolves the `[]`
  sugar, which affects every `ArrayAccess`-based taint source and is left for an
  upstream fix ([vimeo/psalm#11912](https://github.com/vimeo/psalm/issues/11912)).
  It is worth knowing about, because `Tools\AgentTool` uses exactly that shape
  to pass a task to a sub-agent. Prefer `Tools\Request::str()` / `string()` /
  `array()`, or an explicit `offsetGet()` call, all of which are covered.

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
