# Fixing Psalm taint findings

Load this reference when a taint finding has been confirmed real and needs a code fix, or when writing a `@psalm-taint-escape` / `@psalm-suppress` annotation.

Contents:

1. Per-issue-type fix patterns (`TaintedSql`, `TaintedShell`, `TaintedHtml` / `TaintedTextWithQuotes`, `TaintedSSRF`, `TaintedFile`, `TaintedHeader`, `TaintedCallable`)
2. Cross-function taint flows
3. Suppressing false positives (escape vs suppress, kinds, syntax pitfalls)
4. Common false positive patterns

## Per-issue-type fixes

### TaintedSql

```php
// BAD — string interpolation
DB::statement("DELETE FROM users WHERE id = " . $request->input('id'));

// GOOD — parameterized
DB::statement("DELETE FROM users WHERE id = ?", [$request->input('id')]);

// GOOD — Eloquent (always parameterized)
User::where('id', $request->input('id'))->delete();
```

**Subtle case, `orderBy()` column injection**: Unlike value parameters, column names passed to `orderBy()` are NOT parameterized by Eloquent.
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

Also watch for unescaped user data in mail notifications and API-fetched content echoed directly:

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

**Subtle case, Referer header redirect**: The `Referer` header is fully user-controlled and redirecting to it is an open redirect. Use `back()` instead:

```php
// BAD — real open redirect: Referer header is user-controlled
return redirect(request()->header('Referer'));

// GOOD — back() is safe (uses server-managed history, not the header)
return back();
```

### TaintedCallable

Fires when a user-controlled string is used to instantiate a class or call a function dynamically. Often appears in admin testing or notification preview controllers.

```php
// BAD — any existing PHP class can be instantiated, not just Throwable subclasses
$class = $request->input('errorType');
if (!class_exists($class)) { abort(400); }
throw new $class(); // TaintedCallable

// GOOD — guard with is_subclass_of, then escape on a fresh assignment
$class = (string) $request->input('errorType');
if (!class_exists($class) || !is_subclass_of($class, \Throwable::class)) {
    abort(400, 'Unknown exception');
}
// restricted to Throwable subclasses by the guard above
/**
 * @var class-string<\Throwable> $safeClass
 * @psalm-taint-escape callable
 */
$safeClass = strval($class);
throw new $safeClass();
```

> Note: even with `class_exists()`, without a type check any instantiatable class can be constructed. `is_subclass_of()` is the correct guard. The `@var` reattaches the `class-string<T>` type that `strval()` would otherwise widen to plain `string` (which would trip `InvalidStringClass` on `new $safeClass()`).
>
> Lighter alternative: once `is_subclass_of` proves the type, drop the `@var` + `strval` ceremony and put `/** @psalm-suppress TaintedInput -- restricted to Throwable subclasses by guard above */` on the method docblock. Same safety guarantee, fewer moving parts. Pick whichever reads cleaner in context.

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

Two mechanisms. `@psalm-taint-escape <kind>` is preferred (it documents *which* taint was sanitized, which is what reviewers care about). `@psalm-suppress TaintedInput` is the fallback when escape would require more code (extracting variables, splitting chains, preserving `class-string<T>`).

**Important pitfall with `@psalm-suppress`**: only the generic source-side name `TaintedInput` works. The specific sink names (`TaintedHeader`, `TaintedSSRF`, `TaintedSql`, `TaintedFile`, `TaintedCallable`, etc.) are silently ignored (the code compiles, Psalm keeps reporting, and you waste an hour wondering why). If you reach for `@psalm-suppress`, it must be `TaintedInput` exactly. Rationale goes on the same line after `--`: `/** @psalm-suppress TaintedInput -- admin-only; URL validated by signed route */`.

### Primary: `@psalm-taint-escape <kind>`

On a docblock above an assignment whose right-hand side is a *function call or cast* (not a bare variable alias).

```php
// rationale: redirect_url is part of a validated signed URL; tampering invalidates the signature
/**
 * @psalm-taint-escape header
 * @psalm-taint-escape ssrf
 */
$redirectUrl = strval($request->input('redirect_url'));
return redirect()->to($redirectUrl);
```

Three rules, each one is mandatory; missing any one silently breaks the escape:

1. **The right-hand side must be a function call or cast.** `$x = $request->input(...)` is a bare alias and the escape will *not* apply. Wrap in `strval(...)` (preferred, avoids `RedundantCast`) or `(string) ...`. For an `int` sink, use `intval(...)` or `(int) ...`.
2. **Each kind on its own `@psalm-taint-escape` line.** `@psalm-taint-escape header ssrf` and `@psalm-taint-escape header, ssrf` both fail. Two annotations, two lines.
3. **No prose inside the same docblock.** A `Reason:` line, `@var`, `@param`, or any free text between or after the `@psalm-taint-escape` lines breaks parsing (the escape silently doesn't apply). Put rationale in a `//` comment *above* the docblock instead. (One exception: a leading `@var` for type narrowing is fine, see "preserving types" below.)

### Kinds

`header`, `ssrf`, `sql`, `html`, `file`, `callable`, `shell`, `user_secret`, `system_secret`. Lowercase, single word. The kind matches the *suffix* of the issue name (`TaintedSSRF` → `ssrf`, `TaintedSql` → `sql`).

### `strval()` vs `(string)` cast

Both apply taint-escape, but `(string)` triggers `RedundantCast` / `RedundantCastGivenDocblockType` when the operand is already typed as `string` (which is the common case for `$model->email`, `$redirection->url`, `class-string<T>` parameters, etc.).
**Default to `strval()`**: it's a function call so Psalm doesn't flag it, and the taint-escape still applies.

### Preserving `class-string<T>` for `TaintedCallable`

`strval()` widens `class-string<T>` to `string`, which then trips `InvalidStringClass` on the `new $var()` line. Add a `@var` to re-narrow:

```php
if (! is_subclass_of($notificationFqcn, MailNotification::class)) {
    throw new \InvalidArgumentException("{$notificationFqcn} is not a MailNotification subclass.");
}
// admin-only; restricted to MailNotification subclasses by the guard above
/**
 * @var class-string<\App\Notifications\Support\Mail\MailNotification> $safeFqcn
 * @psalm-taint-escape callable
 */
$safeFqcn = strval($notificationFqcn);
return new $safeFqcn();
```

`@var` *before* `@psalm-taint-escape` parses cleanly; the rule about "no prose in the docblock" is about free-text lines, not other recognized annotations.

### Mail notifications: extract before chaining

`@psalm-taint-escape` works on assignments, not on chained method calls. For mail builders, extract the tainted values to local variables first:

```php
// emails are stored model properties, not direct user input from the current request
/** @psalm-taint-escape header */
$ccEmail = strval($this->coachAsMember->getEmail());

return (new MailMessage())
    ->cc($ccEmail) // safe — taint escaped at assignment
    ->subject(...);
```

For methods that take multiple tainted values (`->from($email, $name)`), extract each separately, each with its own escape annotation.

If the chain has three or more tainted inputs flowing into different headers, the extraction noise outweighs the documentation benefit. Drop a single `/** @psalm-suppress TaintedInput -- model-stored email/name; not request input */` on the `buildMail` method docblock and keep the original chain untouched.

### Re-assignments don't re-taint

When the same variable is assigned twice in a method (e.g., `$redirectUrl` set once before validation, again after), each assignment needs its own `@psalm-taint-escape` block. Psalm tracks taint per-assignment-site, not per-variable.

### Fallback: `@psalm-suppress TaintedInput`

When escape would force a refactor (split a chain, extract from a `match`/ternary expression, introduce a typed local just to satisfy the cast), reach for `@psalm-suppress TaintedInput` instead. It works on method docblocks **and** inline above the offending statement, with no cast and no variable extraction.

```php
// admin-only controller; redirect_url is a navigation convenience
/** @psalm-suppress TaintedInput -- admin-only; redirect_url is a navigation convenience */
public function store(Request $request, Dispatcher $dispatcher): RedirectResponse
{
    return $request->input('redirect_url')
        ? redirect()->to($request->input('redirect_url'))
        : redirect()->back();
}
```

### Decision table: escape vs suppress

| Situation | Use |
|---|---|
| Clear `$x = strval(source); sink($x);` with no surrounding chain | `@psalm-taint-escape <kind>` (kind documents what was sanitized) |
| Mail builder chain `(new MailMessage)->cc(...)->subject(...)` | `@psalm-suppress TaintedInput` on method docblock (no need to extract every header) |
| Single-expression `__invoke`/match/ternary that ends in the sink | `@psalm-suppress TaintedInput` on method docblock |
| `class-string<T>` flowing into `new $fqcn()` (would need `strval()` + `@var` to escape) | `@psalm-suppress TaintedInput` if the type is already proven by `is_subclass_of` |
| Multiple distinct kinds reaching distinct sinks in the same method | escape per assignment (keeps each kind narrow) |

Always include `--` rationale after `TaintedInput` so the reason survives in the diff.

## Common false positives

For each, the fix is the `@psalm-taint-escape` pattern above (assignment + `strval()` + escape annotation, with rationale in a `//` comment).

- **Validated input**: `$request->validated()` and `$request->safe()` are marked as taint sources because validation rules don't guarantee safety against all sink types (a valid email is still dangerous in raw SQL). If the validated data is used safely, escape with the matching kind (`@psalm-taint-escape sql`, etc.).
- **Eloquent and Query Builder**: `User::where('col', $value)` is safe. Eloquent and Builder parameterized methods carry `@psalm-taint-escape sql`. Psalm should not flag these; if it does, escape it and report a plugin false-positive.
- **Blade view data**: Passing variables to Blade templates (`view('name', ['key' => $value])`) does **not** trigger `TaintedHtml`. Use `{{ $value }}` for auto-escaped output; `{!! $value !!}` only for explicitly trusted HTML.
- **Integer casting**: `(int) $request->input('page')` should remove taint via `intval()`/`(int)`. If still flagged, escape with `@psalm-taint-escape sql`.
- **Runtime validation**: Psalm cannot track runtime checks (URL allowlists, `basename()`, `str_starts_with()`) as taint escapes. After manually validating input, escape on the next assignment.
- **Model-generated redirects / mail headers**: `redirect($model->url())` and `->cc($member->email)` are flagged because taint propagates from user input stored in the database through model properties. If the value is set server-side (not passed through directly from a current request), extract to a local variable with `@psalm-taint-escape header` (see "Mail notifications: extract before chaining" above). The practical risk for stored, validated values is near-zero.
- **Service provider / cloud API clients**: Apps that make authenticated HTTP calls to external APIs (Linode, Bitbucket, Stripe, fediverse/ActivityPub, payment gateways) will show `TaintedSSRF` for expected outgoing requests. Escape with `@psalm-taint-escape ssrf` and a `//` comment naming the target service.
- **`Js::from()` with encrypted component state**: `TaintedUserSecret`/`TaintedSystemSecret` may fire when Filament (or similar) passes encrypted state to Alpine.js via `Js::from()`. `Js::from()` JSON-encodes the data safely but does not escape secret taint (known plugin limitation). Escape with `@psalm-taint-escape user_secret` and `@psalm-taint-escape system_secret` (separate lines).
- **Allowlist-protected file access**: `TaintedFile` is a false positive when user input is validated against an explicit allowlist (`in_array($path, $allowedPaths, true)`) before being passed to `Storage::get()` or similar. Read the surrounding code; if there's an allowlist check that aborts on mismatch, escape with `@psalm-taint-escape file` on the post-allowlist assignment.
- **SSRF with `url` validation rule**: `TaintedSSRF` is a real issue even when `['url']` validation is applied. The rule only validates format, not destination. An internal-URL allowlist or private-IP block is required to make this safe; only then escape it.
- **Hidden findings behind earlier ones**: when fixing a batch of taint findings, expect new ones to surface after the obvious ones are escaped (Psalm's analysis can short-circuit on related flows). After each round of fixes, re-run the full scan to catch newly-visible findings (e.g., gateway controllers using `$transaction->checkout_url`).

Potential low-risk taints:

- **Cache**: considered safe, but may contain user-controlled data. Plugin does not report about it to reduce noise.
