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
  version: '1.0'
---

# Psalm Security Analysis for Laravel

## Running the analysis

Determine which mode to use from the user's prompt before running anything:

- **Incremental** ("any new issues?", "check for regressions", "did I break anything?") → baseline run
- **Full audit** ("find vulnerabilities", "full scan", "audit security", "show all") → full run

`--output-format` and `--report` are independent simultaneous channels — one run produces both a compact stdout summary and a structured JSON report with full taint traces, covering all triage needs.

**Do not pipe the command output** (e.g. `| grep` or `| head`). Psalm writes the `--report` file at the very end of execution; piping to a command that exits early sends SIGPIPE and kills Psalm before the file is written. The Bash tool captures all stdout automatically, so this is only a risk if you write a piped command explicitly.

**Baseline run** — respects `psalm-baseline.xml`, reports only *new* findings since it was created:

```bash
./vendor/bin/psalm --taint-analysis --no-cache --no-progress --no-suggestions \
  --output-format=text \
  --report=/tmp/psalm_taint.json
echo "Exit: $?"
```

Exit 0 with no compact output = no new issues.

**Full run** — ignores baseline, surfaces all findings:

```bash
./vendor/bin/psalm --taint-analysis --no-cache --no-progress --no-suggestions \
  --ignore-baseline \
  --output-format=text \
  --report=/tmp/psalm_taint.json
echo "Exit: $?"
```

- stdout — compact `FILE:LINE:COL - ERROR_CODE - message` lines, visible immediately in the tool result without reading any file
- `/tmp/psalm_taint.json` — structured report with full source→sink taint trace chains; use `jq` to pull individual findings without reading the whole file (`txt` format is redundant with stdout and lacks trace data)

## Triage

Use the compact stdout output to assess the scope. For individual findings, query the JSON — this avoids reading the entire report:

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
3. **What's the access level?** (public endpoint vs admin-only — affects real-world exploitability)

If the answer to #1 is yes and #2 is no → **real issue**. Document it with file, line, sink type, and severity.

If the taint trace is insufficient, fall back to manually opening the file and reading ~20 lines of context around the flagged line.

### Trace cross-file taint flows manually (when needed)

When Psalm flags a method inside a service class and the trace doesn't surface the origin, grep for usages:

```bash
grep -rn "->methodName(" app/
```

Then read the caller to see how arguments are passed — whether they originate from `$request->input()` or from trusted internal sources.

### Report

Group findings by type (SQL injection, open redirect, SSRF, etc.) and severity. For each:
- Confirmed real: document file, line, input source, sink, fix
- False positive: document why (allowlist protection, admin-only, model-generated value, etc.) and whether to suppress inline with `@psalm-suppress`

## How taint analysis works

Psalm tracks data from **sources** (user input) through the code to **sinks** (dangerous operations). Unsanitized taint reaching a sink triggers an issue.

**Sources** -- including but not limited to:
- `$request->input()`, `$request->query()`, `$request->all()`, `$request->post()`, `$request->cookie()`, `$request->header()`, `$request->json()`, `$request->only()`, `$request->except()`
- `$request->ip()`, `$request->userAgent()`, `$request->url()`, `$request->fullUrl()`, `$request->path()`, `$request->getContent()`
- Route parameters via `$request->route()->parameter('id')` or `$request->route('id')`
- `Session::get()`, `Session::pull()`, `Session::all()`, `session()` values
- Validator `validated()`, `safe()` output, `ValidatedInput` methods
- `UploadedFile::getClientOriginalName()`, `getClientOriginalExtension()`, `getClientMimeType()`
- `request()` and `old()` helper functions
- `Http\Client\Response` methods: `->body()`, `->json()`, `->object()`, `->collect()` — HTTP client responses are taint sources (in SSRF chains, the response body may carry user-influenced data)

**Sinks** -- including but not limited to:
- SQL: `DB::statement()`, `DB::unprepared()`, `DB::select()`, `DB::insert()`, `DB::update()`, `DB::delete()`, `whereRaw()`, `selectRaw()`, `orderByRaw()`, `groupByRaw()`, `havingRaw()`, and other raw query methods
- Shell: `Process::run()`, `Process::start()`, `Process::command()`
- HTML/XSS: `Response::make()`, `response()`, `View::share()`, `HtmlString`, `Mailable::html()`, `MailMessage::line()`, `MailMessage::subject()` — includes `TaintedHtml` (raw output) and `TaintedTextWithQuotes` (attribute injection via quote chars)
- SSRF: `PendingRequest::get()`, `PendingRequest::post()`, `PendingRequest::put()`, `PendingRequest::send()` and other HTTP client methods (note: `Http::get()` facade calls may not propagate taint due to a known `__callStatic` limitation -- call the underlying class directly for reliable detection)
- File: `Storage::get()`, `Storage::put()`, `Storage::delete()`, `File::get()`, `File::delete()`, and related filesystem methods
- Redirect: `redirect()->to()`, `Redirect::away()`, `Redirect::guest()`, `Redirect::intended()`
- Mail headers: `Mailable::to()`, `Mailable::cc()`, `Mailable::bcc()`, `Mailable::subject()`, `MailMessage::cc()`, `MailMessage::subject()`
- Cookie: `CookieJar::make()`, `CookieJar::forever()`, `cookie()` helper; `$path` and `$domain` parameters on `Cookie`/`CookieJar` methods (including `expire()`, `forget()`) are header-injection sinks
- Redis: `Redis::eval()`, `Redis::executeRaw()` — Lua script injection sinks (note: facade calls may not propagate taint due to the `__callStatic` limitation; the stubs target `PhpRedisConnection` directly)
- Secret leaking: `TaintedUserSecret` and `TaintedSystemSecret` fire when encrypted or hashed values (marked as secrets by `bcrypt()`, `encrypt()`, etc.) reach HTML output

**Escapes** (what removes taint):
- HTML: `e()` helper, `Js::from()`, `Js::encode()` — remove `html` taint
- SQL: `DB::escape()`, `Connection::escape()` — remove `sql` taint
- Crypto: `encrypt()` helper, `bcrypt()` helper, `Encrypter::encrypt()`, `HashManager::make()` — remove `user_secret`/`system_secret` taint (facade calls like `Crypt::encrypt()` may not propagate due to `__callStatic` limitation)
- Parameterized queries (`DB::select('...?', [$value])`) are safe — only string interpolation into raw SQL is flagged

> **Note**: `Str::of($input)` and `str($input)` propagate input taint to the returned `Stringable` but do not escape it. Psalm cannot track taint through subsequent chain calls (`->upper()`, `->slug()`) due to a `$this` flow limitation.

## Severity Classification

Use this when reporting findings:

- **HIGH** — public endpoint, direct exploitation, data exfiltration or code execution possible
- **MEDIUM** — public endpoint but requires specific conditions, or significant impact (open redirect, SSRF with format validation only)
- **LOW** — admin/authenticated-only endpoints; reduces blast radius but still warrants fixing
- **FALSE** — false positive

Open redirect in an auth flow (post-login redirect) is always HIGH — it enables phishing with a legitimate domain URL.

## Fixing taint issues

### TaintedSql

```php
// BAD — string interpolation
DB::statement("DELETE FROM users WHERE id = " . $request->input('id'));

// GOOD — parameterized
DB::statement("DELETE FROM users WHERE id = ?", [$request->input('id')]);

// GOOD — Eloquent (always parameterized)
User::where('id', $request->input('id'))->delete();
```

**Subtle case — `orderBy()` column injection**: Unlike value parameters, column names passed to `orderBy()` are NOT parameterized by Eloquent.
They go directly into the SQL as identifiers. This is a very common real vulnerability in sort/filter API endpoints.

```php
// BAD — real SQL injection: column name is not parameterized
$col = $request->query('col', 'id');
$dir = $request->query('dir', 'desc');
User::orderBy($col, $dir)->get();

// GOOD — validate against an allowlist before use
$allowedCols = ['id', 'username', 'created_at'];
$allowedDirs = ['asc', 'desc'];
$col = in_array($request->query('col'), $allowedCols) ? $request->query('col') : 'id';
$dir = in_array($request->query('dir'), $allowedDirs) ? $request->query('dir') : 'asc';
User::orderBy($col, $dir)->get();
```

### TaintedShell

```php
// BAD — tainted
Process::run('grep ' . $request->input('pattern') . ' /var/log/app.log');

// GOOD — array syntax avoids shell interpolation
Process::run(['grep', $request->input('pattern'), '/var/log/app.log']);
```

### TaintedHtml / TaintedTextWithQuotes

`TaintedHtml` fires when user input reaches raw HTML output. `TaintedTextWithQuotes` is a stricter variant that flags content that could break HTML attributes via quote characters.

```php
// BAD — tainted
return response()->make('<h1>' . $request->input('name') . '</h1>');

// GOOD — escape output (removes both html and text-with-quotes taint)
return response()->make('<h1>' . e($request->input('name')) . '</h1>');

// GOOD — Blade auto-escapes with {{ }}
return view('greeting', ['name' => $request->input('name')]);
```

**Also watch for**: unescaped user data in mail notifications and API-fetched content echoed directly:

```php
// BAD — password in notification email is unescaped user data
(new MailMessage)->line('Password: ' . $server->authentication['pass']);

// GOOD — escape it (or better: don't send passwords in emails at all)
(new MailMessage)->line('Password: ' . e($server->authentication['pass']));

// BAD — SSRF → XSS chain: HTTP response body echoed directly
$response = Http::get($url)->throw();
echo $response->body(); // TaintedHtml — response may contain attacker-controlled content

// GOOD — only safe if $url is hardcoded or from a trusted source; otherwise escape
echo e($response->body());
```

### TaintedSSRF

```php
// BAD — tainted
Http::get($request->input('callback_url'));

// GOOD — derive endpoint from validated input, never use the URL directly
$endpoint = match ($request->input('service')) {
    'users' => 'https://api.example.com/users',
    'orders' => 'https://api.example.com/orders',
    default => abort(422, 'Invalid service'),
};
Http::get($endpoint);
```

### TaintedFile

```php
// BAD — tainted
Storage::get($request->input('path'));

// GOOD — map user input to a known path
$allowedFiles = ['report' => 'reports/q1.pdf', 'invoice' => 'invoices/latest.pdf'];
$path = $allowedFiles[$request->input('file')] ?? abort(404);
Storage::get($path);
```

### TaintedHeader

```php
// BAD — tainted (open redirect)
return redirect($request->input('next'));

// GOOD — named route or allowlist
return redirect()->route('dashboard');

$redirects = ['profile' => '/profile', 'settings' => '/settings'];
return redirect($redirects[$request->input('next')] ?? '/');
```

**Subtle case — Referer header redirect**: The `Referer` header is fully user-controlled and redirecting to it is an open redirect. Use `back()` instead:

```php
// BAD — real open redirect: Referer header is user-controlled
return redirect(request()->header('Referer'));

// GOOD — back() is safe (uses server-managed history, not the header)
return back();
```

### TaintedCallable

Fires when a user-controlled string is used to instantiate a class or call a function dynamically. Often appears in admin testing or notification preview controllers.

```diff
$class = $request->input('errorType');
- if (!class_exists($class)) { abort(400); } / BAD — any existing PHP class can be instantiated, not just Throwable subclasses
+ if (!class_exists($class) || !is_subclass_of($class, \Throwable::class)) { abort(400, 'Unknown exception'); } // // GOOD — restrict to the expected type with is_subclass_of
throw new $class(); // TaintedCallable
```

> Note: even with `class_exists()`, without a type check any instantiatable class can be constructed. `is_subclass_of()` is the correct guard.

## Cross-function taint flows

Taint flows across function/method boundaries:

```php
// BAD — taint flows: $request->input() → getQuery() → DB::select()
class ReportService {
    public function getQuery(string $filter): string {
        return "SELECT * FROM reports WHERE status = '$filter'";
    }
}
$results = DB::select($service->getQuery($request->input('status')));

// GOOD — parameterize inside the service
class ReportService {
    public function getResults(string $filter): array {
        return DB::select("SELECT * FROM reports WHERE status = ?", [$filter]);
    }
}
```

## Suppressing false positives

For confirmed false positives, use `@psalm-suppress` inline — this is more precise than a blanket baseline entry and keeps the reason next to the code:

```php
/** @psalm-suppress TaintedHeader -- redirect target is constructed by the application, not user-controlled */
return redirect($model->url());

/** @psalm-suppress TaintedSSRF -- intentional federation fetch; domain is a validated fediverse handle */
Http::get('https://' . $domain . '/api/v1/apps');

/** @psalm-suppress TaintedUserSecret TaintedSystemSecret -- Alpine.js component state; Js::from() JSON-encodes safely */
echo Js::from($componentState);
```

Do **not** add taint suppressions to `psalm-baseline.xml` — inline `@psalm-suppress` makes the decision visible and reviewable.

## Common false positives

- **Validated input**: `$request->validated()` and `$request->safe()` are marked as taint sources because validation rules don't guarantee safety against all sink types (a valid email is still dangerous in raw SQL). If the validated data is used safely (e.g., in Eloquent), use `@psalm-suppress TaintedSql`.
- **Eloquent and Query Builder**: `User::where('col', $value)` is safe — Eloquent and Builder parameterized methods carry `@psalm-taint-escape sql`. Psalm should not flag these; if it does, use `@psalm-suppress` and report about false-positive.
- **Blade view data**: Passing variables to Blade templates (`view('name', ['key' => $value])`) does **not** trigger `TaintedHtml`. Use `{{ $value }}` for auto-escaped output; `{!! $value !!}` only for explicitly trusted HTML.
- **Integer casting**: `(int) $request->input('page')` should remove taint. If Psalm still flags it, use `@psalm-suppress TaintedSql`.
- **Runtime validation**: Psalm cannot track runtime checks (URL allowlists, `basename()`, `str_starts_with()`) as taint escapes. After manually validating input, suppress with `@psalm-suppress`.
- **Model-generated redirects**: `redirect($model->url())` is flagged as `TaintedHeader` because taint propagates from user input stored in the database through model properties. If the URL is constructed by your model (not passed through directly from user input), use `@psalm-suppress TaintedHeader`.
- **Service provider / cloud API clients**: Apps that make authenticated HTTP calls to external APIs (Linode, Bitbucket, Stripe, fediverse/ActivityPub) will show `TaintedSSRF` for expected outgoing requests. Suppress with `@psalm-suppress TaintedSSRF` and a comment naming the target service.
- **`Js::from()` with encrypted component state**: `TaintedUserSecret`/`TaintedSystemSecret` may fire when Filament (or similar) passes encrypted state to Alpine.js via `Js::from()`. `Js::from()` JSON-encodes the data safely but does not escape secret taint — this is a known plugin limitation. Suppress with `@psalm-suppress TaintedUserSecret TaintedSystemSecret`.

- **Allowlist-protected file access**: `TaintedFile` is a false positive when user input is validated against an explicit allowlist (`in_array($path, $allowedPaths, true)`) before being passed to `Storage::get()` or similar. Read the surrounding code — if there's an allowlist check that aborts on mismatch, suppress it.
- **Database-stored emails in mail headers**: `TaintedHeader` on `->cc($member->getEmail())` or similar notification methods is a false positive when the email is a database-stored model property, not a value passed through directly from a current request. The practical risk is near-zero for stored, validated emails.
- **SSRF with `url` validation rule**: `TaintedSSRF` is a real issue even when `['url']` validation is applied — the rule only validates format, not destination. An internal-URL allowlist or private-IP block is required to make this safe.

Potential low-risk taints:
- **Cache**: considered as safe, but may contain user-controlled data. Plugin does not report about it to reduce noise.

## Reporting false positives

If you encounter a finding that looks like a plugin or Psalm bug — ask the user to open an issue at https://github.com/psalm/psalm-plugin-laravel/issues with a minimal reproduction.
