---
name: psalm-security-analysis
description: >
  Run and interpret Psalm security (taint) analysis on a Laravel project. Use this skill when the
  user asks to find security vulnerabilities, run a security scan, check for SQL injection or XSS,
  audit code for taint issues, or fix Psalm taint errors like TaintedSql, TaintedHtml, TaintedShell.
  Also use when investigating data flow from user input to sensitive operations, even if the user
  doesn't explicitly mention Psalm or taint analysis.
compatibility: Requires psalm-plugin-laravel installed and runTaintAnalysis="true" in psalm.xml (or the --taint-analysis CLI flag).
---

# Psalm Security Analysis for Laravel

## Running a security scan

```bash
./vendor/bin/psalm --taint-analysis --no-cache --no-progress --no-suggestions --output-format=text

# To see only taint-related issues only
./vendor/bin/psalm --taint-analysis --no-cache --no-progress --no-suggestions --output-format=text 2>&1 | grep -E "Tainted"
```

If the project has a `psalm-baseline.xml`, existing issues are suppressed. To see all issues including baselined ones:

```bash
./vendor/bin/psalm --taint-analysis --no-cache --ignore-baseline --no-progress --no-suggestions --output-format=text
```

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

Potential low-risk taints:
- **Cache**: considered as safe, but may contain user-controlled data. Plugin does not report about it to reduce noise. 

## Reporting false positives

If you encounter a finding that looks like a plugin or Pslam bug  — ask the user to open an issue at https://github.com/psalm/psalm-plugin-laravel/issues with a minimal reproduction.
