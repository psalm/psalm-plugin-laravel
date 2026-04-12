---
name: psalm-security-analysis
description: >
  Run and interpret Psalm security (taint) analysis on a Laravel project. Use this skill when the
  user asks to find security vulnerabilities, run a security scan, check for SQL injection or XSS,
  audit code for taint issues, or fix Psalm taint errors like TaintedSql, TaintedHtml, TaintedShell.
  Also use when investigating data flow from user input to sensitive operations.
---

# Psalm Security Analysis for Laravel

## When to use this skill

- User asks to scan for security vulnerabilities or run a security audit
- User asks about SQL injection, XSS, SSRF, shell injection, or other injection attacks
- User needs to fix a `Tainted*` Psalm error
- User wants to trace how user input flows through their code
- User asks to harden a route, controller, or service against injection

## Running a security scan

```bash
# Psalm 7 runs taint analysis by default alongside type checking
./vendor/bin/psalm --no-cache

# To see only taint-related issues, filter the output
./vendor/bin/psalm --no-cache 2>&1 | grep -E "Tainted"
```

If the project has a `psalm-baseline.xml`, existing issues are suppressed. To see all issues including baselined ones:

```bash
./vendor/bin/psalm --no-cache --ignore-baseline
```

## How taint analysis works

Psalm tracks data from **sources** (where user input enters) through the code to **sinks** (where it becomes dangerous). If tainted data reaches a sink without being sanitized, Psalm reports an issue.

**Sources** (where taint originates) -- including but not limited to:
- `$request->input()`, `$request->query()`, `$request->all()`, `$request->post()`, `$request->cookie()`, `$request->header()`, `$request->json()`, `$request->only()`, `$request->except()`
- `$request->ip()`, `$request->userAgent()`, `$request->url()`, `$request->fullUrl()`, `$request->path()`, `$request->getContent()`
- Route parameters via `$request->route()->parameter('id')` or `$request->route('id')`
- `Session::get()`, `Session::pull()`, `Session::all()`, `session()` values
- Validator `validated()`, `safe()` output, `ValidatedInput` methods
- `UploadedFile::getClientOriginalName()`, `getClientOriginalExtension()`, `getClientMimeType()`
- `request()` and `old()` helper functions

**Sinks** (where tainted data is dangerous) -- including but not limited to:
- SQL: `DB::statement()`, `DB::unprepared()`, `DB::select()`, `DB::insert()`, `DB::update()`, `DB::delete()`, `whereRaw()`, `selectRaw()`, `orderByRaw()`, `groupByRaw()`, `havingRaw()`, and other raw query methods
- Shell: `Process::run()`, `Process::start()`, `Process::command()`
- HTML/XSS: `Response::make()`, `response()`, `View::share()`, `HtmlString`, `Mailable::html()`
- SSRF: `PendingRequest::get()`, `PendingRequest::post()`, `PendingRequest::put()`, `PendingRequest::send()` and other HTTP client methods (note: `Http::get()` facade calls may not propagate taint due to a known `__callStatic` limitation -- call the underlying class directly for reliable detection)
- File: `Storage::get()`, `Storage::put()`, `Storage::delete()`, `File::get()`, `File::delete()`, and related filesystem methods
- Redirect: `redirect()->to()`, `Redirect::away()`, `Redirect::guest()`, `Redirect::intended()`
- Mail headers: `Mailable::to()`, `Mailable::cc()`, `Mailable::bcc()`, `Mailable::subject()`, `MailMessage::cc()`, `MailMessage::subject()`
- Cookie: `CookieJar::make()`, `CookieJar::forever()`, `cookie()` helper

**Escapes** (what removes taint):
- HTML: `e()` helper, `Js::from()`, `Js::encode()` — remove `html` taint
- SQL: `DB::escape()`, `Connection::escape()` — remove `sql` taint
- Crypto: `encrypt()` helper, `bcrypt()` helper, `Encrypter::encrypt()`, `HashManager::make()` — remove `user_secret`/`system_secret` taint (facade calls like `Crypt::encrypt()` may not propagate due to `__callStatic` limitation)
- Parameterized queries (`DB::select('...?', [$value])`) are safe — only string interpolation into raw SQL is flagged

## Taint issue types and how to fix them

### TaintedSql

User input reaches a raw SQL method without parameterization.

```php
// BAD — tainted
DB::statement("DELETE FROM users WHERE id = " . $request->input('id'));

// GOOD — parameterized
DB::statement("DELETE FROM users WHERE id = ?", [$request->input('id')]);

// GOOD — Eloquent (always parameterized)
User::where('id', $request->input('id'))->delete();
```

### TaintedShell

User input reaches a shell execution method.

```php
// BAD — tainted
Process::run('grep ' . $request->input('pattern') . ' /var/log/app.log');

// GOOD — use array syntax or escapeshellarg
Process::run(['grep', $request->input('pattern'), '/var/log/app.log']);
```

### TaintedHtml

User input is sent in an HTTP response without HTML escaping.

```php
// BAD — tainted
return response()->make('<h1>' . $request->input('name') . '</h1>');

// GOOD — escape output
return response()->make('<h1>' . e($request->input('name')) . '</h1>');

// GOOD — use Blade templates (auto-escaped with {{ }})
return view('greeting', ['name' => $request->input('name')]);
```

### TaintedSSRF

User input controls a URL used in an HTTP request.

```php
// BAD — tainted
Http::get($request->input('callback_url'));

// GOOD — use a hardcoded URL, derive the endpoint from validated input
$endpoint = match ($request->input('service')) {
    'users' => 'https://api.example.com/users',
    'orders' => 'https://api.example.com/orders',
    default => abort(422, 'Invalid service'),
};
Http::get($endpoint);
```

If you must use the user-provided URL after validation, suppress via baseline — Psalm cannot track runtime validation as an escape.

### TaintedFile

User input controls a file path.

```php
// BAD — tainted
Storage::get($request->input('path'));

// GOOD — map user input to a known path instead of using it directly
$allowedFiles = ['report' => 'reports/q1.pdf', 'invoice' => 'invoices/latest.pdf'];
$key = $request->input('file');
$path = $allowedFiles[$key] ?? abort(404);
Storage::get($path);
```

If you need to use user input in the path after validation (e.g., `basename()`), suppress via baseline — Psalm cannot track `basename()` as a taint escape.

### TaintedHeader

User input is used in a redirect URL or HTTP header (open redirect).

```php
// BAD — tainted
return redirect($request->input('next'));

// GOOD — use a named route instead of user-provided URL
return redirect()->route('dashboard');

// GOOD — map user input to known routes
$redirects = ['profile' => '/profile', 'settings' => '/settings'];
return redirect($redirects[$request->input('next')] ?? '/');
```

Runtime URL validation (e.g., checking `str_starts_with($url, '/')`) is good security practice but Psalm cannot track it as a taint escape. Suppress via baseline when you have validated the input manually.

## Cross-function taint flows

Psalm tracks taint across function and method boundaries. A common pattern that gets flagged:

```php
class ReportService
{
    public function getQuery(string $filter): string
    {
        // This receives tainted $filter and builds a raw query
        return "SELECT * FROM reports WHERE status = '$filter'";
    }
}

// In a controller — taint flows: $request->input() -> getQuery() -> DB::select()
$results = DB::select($service->getQuery($request->input('status')));
```

Fix by using parameterized queries inside the service:

```php
class ReportService
{
    public function getResults(string $filter): array
    {
        return DB::select("SELECT * FROM reports WHERE status = ?", [$filter]);
    }
}
```

## Common false positives

- **Validated input**: `$request->validated()` and `$request->safe()` are marked as taint sources because validation rules don't guarantee safety against all sink types (a valid email is still dangerous in raw SQL). If the validated data is used safely (e.g., in Eloquent), suppress with a baseline.
- **Eloquent methods**: `User::where('col', $value)` is safe because Eloquent parameterizes automatically. Psalm should not flag this. If it does, this is a plugin bug.
- **Integer casting**: `(int) $request->input('page')` should remove taint. If Psalm still flags it, use the baseline.
