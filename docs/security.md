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
| Prompt injection | LLM01:2025 | `laravel/ai` agents: `Agent::prompt()`, `stream()`, `queue()`, `broadcast*()` (advisory unless [`findPromptInjection`](config.md#findpromptinjection) is enabled) |
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

Two directions are covered, and they ship at different reporting levels.

* Untrusted input reaching a prompt is reported as `TaintedLlmPrompt`, **advisory
  by default**: computed, visible with `--show-info=true`, never build-breaking.
  Turn it into an error with [`findPromptInjection`](config.md#findpromptinjection).
  The asymmetry is deliberate. Every other taint kind here names its own fix, but
  no reliable prompt-injection sanitizer exists, so an error on
  `$agent->prompt($request->input('message'))` flags a chatbot for being a chatbot
  and leaves suppression as the only remedy. Enforcement earns its keep where the
  prompt is assembled from data the user did not knowingly submit: retrieved
  documents, scraped pages, webhook bodies, tool results. Sinks are
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
