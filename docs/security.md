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
| Prompt injection | LLM01:2025 | `laravel/ai` 0.11.x and 1.x prompt sinks: agents, messages, media, documents, reranking, and tool metadata; 1.x-only boundaries are noted below (enforced by default when the supported integration is installed; [`findPromptInjection`](config.md#findpromptinjection) can explicitly suppress D-in findings; an annotated guard in the agent's middleware exempts the call site) |
| LLM output reuse | LLM01:2025 | `laravel/ai` tool and model-output sources: text, structured output, reasoning, and 1.x classification answers and citations |

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

Applies to projects using `laravel/ai`. The stubs, LLM-output handler,
prompt-guard handler, and prompt-injection issue policy load together only when
that package is installed and satisfies `>=0.11.0 <2.0.0` (0.11.x and 1.x), so
projects without a supported package pay nothing.

Two directions are covered. Both are errors by default; the explicit opt-out only
suppresses the D-in `TaintedLlmPrompt` issue. A **1.x-only** boundary below is
present in the `v1/` stubs and does not exist in laravel/ai 0.11.x.

* **Prompt sinks.** Untrusted input reaching any listed sink is reported as
  `TaintedLlmPrompt` at the normal error level by default when the supported
  `laravel/ai` integration is installed. Set
  [`findPromptInjection`](config.md#findpromptinjection) to `false` only to
  suppress this D-in issue; an explicit issue handler still wins. The integration
  gate is `laravel/ai >=0.11.0 <2.0.0`, covering 0.11.x and 1.x, and `true`
  cannot bypass it.
* **Sinks in 0.11.x and 1.x.** `Promptable` and `Contracts\Agent` each annotate
  `prompt()`, `stream()`, `queue()`, `broadcast()`, `broadcastNow()`, and
  `broadcastOnQueue()`; `agent()` and `AnonymousAgent::__construct()` annotate
  their instructions and messages. `AgentPrompt::__construct()`, `prepend()`,
  `append()`, and `revise()`; the `Messages\Message` and
  `Messages\UserMessage` constructors; `Audio::of()`, `Image::of()`, and
  `Reranking::of()`; `PendingReranking::rerank()`; `Files\Document::fromString()`
  / `fromBase64()` and `Files\Image::fromBase64()`; and
  `Tools\SimilaritySearch::withDescription()` are also sinks.
* **1.x-only sinks.** The agent delivery methods above accept
  `AgentInput|UserMessage|Decisions|string`. `Promptable::withMessages()` and
  `AgentPrompt::withTools()` are sinks. Middleware mutations through
  `PendingStep::withInstructions()`, `withMessages()`, `withTools()`, and
  `withProviderOptions()` are sinks. Classification annotates
  `Classification::of()`, the constructors of `Classification\Boolean`,
  `Classification\Choice`, and `Classification\Score`, and
  `PendingClassification::__construct()`.
* **Sources in 0.11.x and 1.x.** `Contracts\Tool::handle()` returns a source so
  tool output keeps flowing into later prompt sinks. `Tools\Request::validate()`,
  `all()`, `toArray()`, `offsetGet()`, `str()`, `string()`, `array()`, `only()`,
  `except()`, and `collect()` are sources. The LLM-output handler sources
  `$text` on `TextResponse`, `AgentResponse`, `StreamedAgentResponse`,
  `StreamableAgentResponse`, and `TranscriptionResponse`, and `$structured` on
  `StructuredAgentResponse` and `StructuredTextResponse`. String casts on
  `AgentResponse`, `TextResponse`, `TranscriptionResponse`,
  `StructuredAgentResponse`, and `StructuredTextResponse` are sources.
  `StructuredAgentResponse::toArray()`, `toJson()`, and `jsonSerialize()`, plus
  `ProvidesStructuredResponse::offsetGet()`, are sources too.
* **1.x-only sources.** The handler sources `$reasoning` on `TextResponse`,
  `StreamableAgentResponse`, `Responses\Data\Step`, and `Gateway\StepResponse`,
  and `$citations` on `StreamableAgentResponse`. `AgentResponse::fakeWithReasoning()`
  and `TextResponse::withReasoning()` are sources. Classification sources are
  `ClassificationResponse::answer()`, `collect()`, `toArray()`,
  `jsonSerialize()`, `getIterator()`, and `offsetGet()`; `Data\Answer::toArray()`
  and `jsonSerialize()`; `BooleanAnswer::isTrue()` and `toArray()`;
  `ChoiceAnswer::probabilityOf()` and `toArray()`; and
  `ScoreAnswer::level()`, `label()`, `normalized()`, and `toArray()`.

Model-output sources are unaffected by `findPromptInjection`: their ordinary
SQL/HTML/shell findings have the ordinary fix (parameterize or escape). This
models indirect prompt injection, where the payload arrives through a page,
document, or tool result the model read. Transcripts count on both paths
(`TranscriptionResponse::$text` and its string cast): the audio was supplied by a
user, so the transcript is attacker-authored text a speech model merely re-typed.

#### Marking prompt-guard middleware as trusted

For middleware authors (a guard library, or an app with its own guard). Use this when your
middleware genuinely stops prompt injection before the prompt reaches the provider. It tells the
plugin to stop reporting a mitigation you already ship, instead of asking your users to suppress
the finding by hand.

Two annotations, both phpdoc:

1. On the guard, annotate the method `laravel/ai` invokes with
   `@psalm-taint-escape llm_prompt`. Use `handle()` for a cross-major guard:
   laravel/ai 1.x invokes it once per `PendingStep`, while 0.11.x invokes it for
   an `AgentPrompt`. In 0.11.x, an object middleware entry with `__invoke()` uses
   that method instead; a class-string entry uses `handle()` first and falls back
   to `__invoke()`.
2. On the agent's unchanged `middleware()` method, declare a `@return` naming
   your guard class: `@return list<PromptGuard>` (a
   `@return list<class-string<PromptGuard>>` entry works too).

This laravel/ai 1.x guard wraps each generation step:

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

For laravel/ai 0.11.x, import `Laravel\Ai\Prompts\AgentPrompt` instead and use
`handle(AgentPrompt $prompt, \Closure $next): mixed`; retarget the flow
annotation and `$next()` call to `$prompt`.

What it does: `TaintedLlmPrompt` stops being reported for `prompt()` and `stream()` on that agent.
Every other taint kind keeps flowing, so the same value reaching SQL, HTML, or a shell command is
still reported.

The `@psalm-flow` line does not affect the exemption, which reads only the escape. Include it
anyway: an escape with no `@psalm-flow` beside it leaves the return value carrying no taint at all,
not just no `llm_prompt` (see
[the pairing rule](contributing/taint-analysis.md#critical-rule-always-pair-psalm-taint-escape-with-psalm-flow)),
so anything calling the guard directly would read a fully clean value. Psalm can often infer the
same flow from a body that returns the argument, which is why omitting it usually costs nothing;
the line makes the contract hold when the body is not visible.

Caveats:

* This is a trust declaration, not a proof. It records that a mitigation is attached, not that a
  given payload is neutralised, the same as every other `@psalm-taint-escape`.
* Closure middleware is never exempted. The guard lives in the closure body, which no declared type
  describes. Write the guard as a class.
* No `@return` on `middleware()` means no exemption. A bare `array` names nothing to check.
* An escape on a method the pipeline skips does not count. An annotated `handle()` on a class that
  also declares `__invoke()` is never reached at runtime, so it never exempts.
* The agent must implement `HasMiddleware` (inherited counts). Without it `laravel/ai` never calls
  `middleware()` at all.
* An agent whose `middleware()` some subclass in the project replaces is not exempted at all: the
  subclass could be running a different stack, and the call site only names the parent. Calling on
  the subclass directly is still exempt when that subclass keeps the guarded stack.
* The subclass checks only see analysed code. If you ship an agent or a guard as a library, a
  consumer's own subclass can replace the stack or drop the escape without the analysis of your
  package ever seeing it.
* A guard class that some subclass in the project extends is only trusted when every one of those
  subclasses also carries the escape on its own dispatched method. The declared type is a bound, so
  a subclass could otherwise drop the mitigation or move dispatch onto an unannotated `__invoke()`.
* A template bound (`@return list<class-string<T>>`) names no concrete guard and is not exempted.
* `@return list<PromptGuard>` says what the entries are, not that there is one. A body returning
  `[]` still exempts: the declaration is your claim, the same as the escape itself.
* A class-string entry is resolved through the container, so a binding that swaps your guard for
  another pipe is invisible here. This is the same trust layer as the guard's own configuration.
* `queue()` and `broadcast*()` run the same pipeline but are not exempted yet, so they keep
  reporting.

Whether a guard blocks or only logs is usually runtime configuration and is not statically
distinguishable, and the middleware list is read from the declared return type rather than from the
method body. Deeper mechanics, including the exact `Pipeline` dispatch order, are in
[`docs/contributing/taint-analysis.md`](contributing/taint-analysis.md).

Known limitations. Each is an upstream limitation rather than a judgement that
the flow is safe, so treat it as a blind spot when reviewing.

* **Return-value sinks.** `Tool::description()` and `Agent::instructions()` are
  not covered: Psalm's `@psalm-taint-sink` matches parameter names only. Tracked
  in [#484](https://github.com/psalm/psalm-plugin-laravel/issues/484).
* **1.x `decide()` macros.** `Str::decide()` and `Stringable::decide()` are not
  sinks. They are macro pseudo-methods with no per-method docblock on which to
  attach an `llm_prompt` sink. This is pinned by
  `tests/Type/tests/PromptInjection/ClassificationDecideKnownLimitation.phpt` and
  documented in `stubs/integrations/laravel-ai/v1/Classification.phpstub`.
* **1.x citation collection reads.** `StreamableAgentResponse::$citations` is a
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
