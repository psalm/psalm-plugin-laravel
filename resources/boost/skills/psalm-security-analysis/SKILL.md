---
name: psalm-security-analysis
description: >
  Runs and interprets Psalm security (taint) analysis on a Laravel project. Use when the user asks
  to find security vulnerabilities, run a security scan, check for SQL injection or XSS, audit code
  for taint issues, or fix Psalm taint errors. Also use when investigating data flow from user input
  to sensitive operations, even if the user doesn't explicitly mention Psalm or taint analysis.
  Requires psalm-plugin-laravel installed.
metadata:
  author: alies-dev
  version: '1.1'
---

# Psalm Security Analysis for Laravel

This skill covers the run → triage → report workflow. For fixing confirmed findings and writing suppression/escape annotations, load `references/fixing.md`.

## Running the analysis

Determine which mode to use from the user's prompt before running anything:

- **Incremental** ("any new issues?", "check for regressions", "did I break anything?") → baseline run
- **Full audit** ("find vulnerabilities", "full scan", "audit security", "show all" or `psalm-baseline.xml` file is not available in the project) → full run

`--output-format` and `--report` are independent simultaneous channels. One run produces both a compact stdout summary and a structured JSON report with full taint traces, covering all triage needs.

**Do not pipe the command output** (e.g. `| grep` or `| head`). Psalm writes the `--report` file at the very end of execution; piping to a command that exits early sends SIGPIPE and kills Psalm before the file is written. The Bash tool captures all stdout automatically, so this is only a risk if you write a piped command explicitly.

**Baseline run** respects `psalm-baseline.xml`, reports only *new* findings since it was created:

```bash
./vendor/bin/psalm --taint-analysis --no-cache --no-progress --no-suggestions \
  --output-format=compact \
  --report=/tmp/psalm_taint.json
echo "Exit: $?"
```

Exit 0 with no compact output = no new issues.

**Full run** ignores baseline, surfaces all findings:

```bash
./vendor/bin/psalm --taint-analysis --no-cache --no-progress --no-suggestions \
  --ignore-baseline \
  --output-format=compact \
  --report=/tmp/psalm_taint.json
echo "Exit: $?"
```

- stdout: compact `FILE:LINE:COL - ERROR_CODE - message` lines, visible immediately in the tool result without reading any file
- `/tmp/psalm_taint.json`: structured report with full source→sink taint trace chains; use `jq` to pull individual findings without reading the whole file (`txt` format is redundant with stdout and lacks trace data)

## Triage

Use the compact stdout output to assess the scope. For individual findings, query the JSON (this avoids reading the entire report):

```bash
# All taint issue types found
jq -r '[.issues[].type] | unique[]' /tmp/psalm_taint.json | grep Tainted

# Full trace for a specific issue type
jq '.issues[] | select(.type == "TaintedSql")' /tmp/psalm_taint.json

# Issues in a specific directory
jq '.issues[] | select(.file_path | contains("app/Http"))' /tmp/psalm_taint.json

# Affected files (unique)
jq -r '.issues[] | select(.type | startswith("Tainted")) | .file_name' /tmp/psalm_taint.json | sort -u
```

### Triage each finding

Use the taint trace from the JSON report as your primary source. For each finding, answer three questions:

1. **Is there a real path from user input to the sink?** (or is the tainted value from a model/service that doesn't actually accept user data?)
2. **Is there any sanitization/validation between source and sink?** (allowlist check, `in_array`, cast to int, `e()`, parameterized query?)
3. **What's the access level?** (public endpoint vs admin-only, affects real-world exploitability)

If the answer to #1 is yes and #2 is no → **real issue**. Document it with file, line, sink type, and severity.

If the taint trace is insufficient, fall back to manually opening the file and reading ~20 lines of context around the flagged line.

### Trace cross-file taint flows manually (when needed)

When Psalm flags a method inside a service class and the trace doesn't surface the origin, grep for usages:

```bash
grep -rn "->methodName(" app/
```

Then read the caller to see how arguments are passed, whether they originate from `$request->input()` or from trusted internal sources.

### Report

Group findings by type (SQL injection, open redirect, SSRF, etc.) and severity. For each:

- Confirmed real: document file, line, input source, sink, fix. For fix patterns by issue type, see `references/fixing.md`.
- False positive: document why (allowlist protection, admin-only, model-generated value, etc.) and either escape inline with `@psalm-taint-escape <kind>` (preferred) or `@psalm-suppress TaintedInput -- reason` (fallback). Full decision guide, syntax pitfalls, and common FP patterns in `references/fixing.md`.

## How taint analysis works

Psalm tracks data from **sources** (user input) through the code to **sinks** (dangerous operations). Unsanitized taint reaching a sink triggers an issue.

**Sources**, including but not limited to:

- `$request->input()`, `$request->query()`, `$request->all()`, `$request->post()`, `$request->cookie()`, `$request->header()`, `$request->json()`, `$request->only()`, `$request->except()`
- `$request->ip()`, `$request->userAgent()`, `$request->url()`, `$request->fullUrl()`, `$request->path()`, `$request->getContent()`
- Route parameters via `$request->route()->parameter('id')` or `$request->route('id')`
- `Session::get()`, `Session::pull()`, `Session::all()`, `session()` values
- Validator `validated()`, `safe()` output, `ValidatedInput` methods
- `UploadedFile::getClientOriginalName()`, `getClientOriginalExtension()`, `getClientMimeType()`
- `request()` and `old()` helper functions
- `Http\Client\Response` methods: `->body()`, `->json()`, `->object()`, `->collect()` (HTTP client responses are taint sources; in SSRF chains, the response body may carry user-influenced data)

**Sinks**, including but not limited to:

- SQL: `DB::statement()`, `DB::unprepared()`, `DB::select()`, `DB::insert()`, `DB::update()`, `DB::delete()`, `whereRaw()`, `selectRaw()`, `orderByRaw()`, `groupByRaw()`, `havingRaw()`, and other raw query methods
- Shell: `Process::run()`, `Process::start()`, `Process::command()`
- HTML/XSS: `Response::make()`, `response()`, `View::share()`, `HtmlString`, `Mailable::html()`, `MailMessage::line()`, `MailMessage::subject()` (includes `TaintedHtml` for raw output and `TaintedTextWithQuotes` for attribute injection via quote chars)
- SSRF: `PendingRequest::get()`, `PendingRequest::post()`, `PendingRequest::put()`, `PendingRequest::send()` and other HTTP client methods (note: `Http::get()` facade calls may not propagate taint due to a known `__callStatic` limitation; call the underlying class directly for reliable detection)
- File: `Storage::get()`, `Storage::put()`, `Storage::delete()`, `File::get()`, `File::delete()`, and related filesystem methods
- Redirect: `redirect()->to()`, `Redirect::away()`, `Redirect::guest()`, `Redirect::intended()`
- Mail headers: `Mailable::to()`, `Mailable::cc()`, `Mailable::bcc()`, `Mailable::subject()`, `MailMessage::cc()`, `MailMessage::subject()`
- Cookie: `CookieJar::make()`, `CookieJar::forever()`, `cookie()` helper; `$path` and `$domain` parameters on `Cookie`/`CookieJar` methods (including `expire()`, `forget()`) are header-injection sinks
- Redis: `Redis::eval()`, `Redis::executeRaw()` (Lua script injection sinks; note facade calls may not propagate taint due to the `__callStatic` limitation; the stubs target `PhpRedisConnection` directly)
- Secret leaking: `TaintedUserSecret` and `TaintedSystemSecret` fire when encrypted or hashed values (marked as secrets by `bcrypt()`, `encrypt()`, etc.) reach HTML output

**Escapes** (what removes taint):

- HTML: `e()` helper, `Js::from()`, `Js::encode()` (remove `html` taint)
- SQL: `DB::escape()`, `Connection::escape()` (remove `sql` taint)
- Crypto: `encrypt()` helper, `bcrypt()` helper, `Encrypter::encrypt()`, `HashManager::make()` (remove `user_secret`/`system_secret` taint; facade calls like `Crypt::encrypt()` may not propagate due to `__callStatic` limitation)
- Parameterized queries (`DB::select('...?', [$value])`) are safe. Only string interpolation into raw SQL is flagged.

> **Note**: `Str::of($input)` and `str($input)` propagate input taint to the returned `Stringable` but do not escape it. Psalm cannot track taint through subsequent chain calls (`->upper()`, `->slug()`) due to a `$this` flow limitation.

## Severity Classification

Use this when reporting findings:

- **HIGH**: public endpoint, direct exploitation, data exfiltration or code execution possible
- **MEDIUM**: public endpoint but requires specific conditions, or significant impact (open redirect, SSRF with format validation only)
- **LOW**: admin/authenticated-only endpoints; reduces blast radius but still warrants fixing
- **FALSE**: false positive

Open redirect in an auth flow (post-login redirect) is always HIGH. It enables phishing with a legitimate domain URL.

## Baseline policy

Do **not** add taint findings to `psalm-baseline.xml`. Many projects enforce this in CI:

```yaml
- name: Ensure psalm-baseline.xml does not hide taint findings
  run: |
    if grep -nE '<Tainted[A-Z]' psalm-baseline.xml; then
      echo "::error::Tainted* entries must be fixed (or escaped via @psalm-taint-escape), not baselined."
      exit 1
    fi
```

After fixing taint issues, scrub leftover `<Tainted*>` entries from the baseline. They will fail this check even though no taint flow remains.

## Additional resources

### `references/fixing.md`

Load when a finding is confirmed real and needs a code fix, or when writing a `@psalm-taint-escape` / `@psalm-suppress` annotation. Contents:

- Per-issue-type fix patterns (`TaintedSql`, `TaintedShell`, `TaintedHtml` / `TaintedTextWithQuotes`, `TaintedSSRF`, `TaintedFile`, `TaintedHeader`, `TaintedCallable`)
- Cross-function taint flow fixes
- Suppression mechanics: escape vs suppress decision table, kinds list, `strval()` vs cast, preserving `class-string<T>`, mail chain extraction, re-assignment trap
- Common false positive patterns (validated input, Eloquent, Blade, integer casting, runtime validation, model-generated values, cloud API clients, `Js::from()` with secrets, allowlist-protected file access, `url` validation rule, hidden findings)

## Reporting plugin false positives

If a finding looks like a plugin or Psalm bug, ask the user to open an issue at https://github.com/psalm/psalm-plugin-laravel/issues with a minimal reproduction.
