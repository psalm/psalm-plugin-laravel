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
| Prompt injection | LLM01:2025 | `laravel/ai` agents: `Agent::prompt()`, `stream()`, `queue()`, `broadcast*()`, `Embeddings::for()` |

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
only when that package is installed and satisfies `>=0.10.0 <1.0.0`, so projects
without it pay nothing.

Two directions are covered:

* Untrusted input reaching a prompt is reported as `TaintedLlmPrompt`. Sinks are
  `Promptable::prompt()` / `stream()` / `queue()` / `broadcast*()`, the
  `Laravel\Ai\agent()` helper, `AgentPrompt::prepend()` / `append()` / `revise()`,
  `Embeddings::for()`, `Tools\Document::fromString()` / `fromBase64()`, and the
  `Messages\UserMessage` / `Messages\Message` constructors.
* Model output is itself a taint source. Reading `$response->text` (or casting the
  response to string) yields tainted data, so an answer echoed into HTML, SQL, or a
  shell command is reported like any other user input. That models indirect prompt
  injection, where the payload arrives through a page, document, or tool result the
  model read. Transcripts (`TranscriptionResponse::$text`) count: the audio was
  supplied by a user, so the transcript is attacker-authored text a speech model
  merely re-typed.
* Structured output is covered on the same footing. `$response['field']` on
  `StructuredAgentResponse` / `StructuredTextResponse`, plus `toArray()`,
  `toJson()`, `jsonSerialize()` and the string cast, all yield tainted values. The
  keys come from the application's schema, the values come from the model.
* Tool arguments are a source, including the `$request['task']` array form the
  framework's own `Tools\AgentTool` uses to hand a task to a sub-agent. That makes
  the sub-agent delegation chain (parent model picks the task text, sub-agent
  receives it as a prompt) report `TaintedLlmPrompt`.

Return-value sinks (`Tool::description()`, `Agent::instructions()`) are not covered
yet: Psalm's `@psalm-taint-sink` matches parameter names only. Tracked in
[#484](https://github.com/psalm/psalm-plugin-laravel/issues/484).

### How it compares

| Tool              | Laravel-aware types | Taint analysis     | Free               |
|-------------------|---------------------|--------------------|--------------------|
| **psalm-laravel** | Yes                 | Yes (dataflow)     | Yes                |
| Larastan          | Yes                 | No (PHPStan can't) | Yes                |
| SonarQube         | Generic PHP         | Yes (generic)      | Paid editions only |
| Semgrep           | Pro tier only       | Pattern-based      | Limited free tier  |
| Snyk Code         | Generic             | Yes (generic)      | Freemium           |
