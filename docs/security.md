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

The proof is deliberately syntactic, never control-flow aware, and stays two
independent checks over the same header entries; either alone is enough:

- **Attachment disposition.** A literal `Content-Disposition: attachment`
  entry, or one whose value is an interpolated string or `.`-concatenation
  whose literal leading part already contains the `attachment;` token
  (`"attachment; filename=\"{$name}.csv\""`, `'attachment; filename=' . $name`).
  The interpolated or concatenated suffix is never inspected: nothing that can
  follow a literal parameter separator can retract the token. A value carrying
  any control character other than a horizontal tab keeps the finding, since a
  runtime that drops the header serves the response as HTML. Parameters after
  the `attachment` token are not validated, because every browser downloads on
  the token whatever follows it.
- **Safe content type.** A literal `Content-Type` naming a well-formed media
  type that is not `text/html`, does not contain `html` or `xml` anywhere
  (covering every `*+xml` suffix, `application/xml`, and `image/svg+xml` —
  XML can carry an XHTML-namespaced `<script>`), and does not start with
  `multipart/`. Every other well-formed media type is exempt, vendor download
  types included. Parameters after the first `;` (`charset=`, ...) are
  discarded before matching.

The headers array itself may be written literally at the call site, or held in
a local variable proven to be a single, unmutated assignment: the enclosing
function or method assigns it exactly once, before the call, and neither
reassigns, mutates, passes it elsewhere, nor captures it in a nested closure.
A variable-variable (`$$x`) or an `extract()`/`compact()`/`get_defined_vars()`
call anywhere in the same scope keeps the finding regardless, since none of
those produce the AST nodes the proof looks for. Dynamic, duplicate, list
valued, underscore named, or non-attachment dispositions continue to report,
as does a dynamic or malformed content type, a variable that fails any of the
single-assignment conditions above, and every named argument call. Header
names are folded the way Symfony folds them (underscore onto hyphen, then
lower case), so a second entry that spells either header differently but
lands on the same name keeps the finding for that header. An entry that is
not itself an attachment or content-type candidate only needs a literal
string key (to still detect such a duplicate); its value may be any
expression. This applies to the concrete factory, its contract, and the
`Illuminate\Support\Facades\Response` facade, including a receiver whose type
intersects one of them with another interface, plus a direct `new
Illuminate\Http\Response(...)` call. The root `\Response` alias and custom
classes with a `make()` method keep the sink. A receiver typed as the factory
or its contract is trusted to apply the headers argument, as every conforming
implementation does. An implementation that silently discards its headers
argument violates that contract and is out of scope.

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

### How it compares

| Tool              | Laravel-aware types | Taint analysis     | Free               |
|-------------------|---------------------|--------------------|--------------------|
| **psalm-laravel** | Yes                 | Yes (dataflow)     | Yes                |
| Larastan          | Yes                 | No (PHPStan can't) | Yes                |
| SonarQube         | Generic PHP         | Yes (generic)      | Paid editions only |
| Semgrep           | Pro tier only       | Pattern-based      | Limited free tier  |
| Snyk Code         | Generic             | Yes (generic)      | Freemium           |
