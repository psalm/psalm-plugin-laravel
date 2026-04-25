# Strategy Data Reference

> **Last updated:** 2026-04-12 — verify download/star counts on Packagist and GitHub before citing.

This file contains the data points, tables, and research needed for marketing content. Source: `~/.alies/docs/marketing-strategy-security-niche.md`.

## Table of Contents
1. [Key Numbers](#key-numbers)
2. [Competitive Landscape](#competitive-landscape)
3. [Cost Comparison](#cost-comparison)
4. [Current Taint Coverage](#current-taint-coverage)
5. [Coverage Gaps](#coverage-gaps)
6. [OWASP Top 10 Mapping](#owasp-top-10-mapping)
7. [Cross-Function Dataflow Example](#cross-function-dataflow-example)
8. [The Moat](#the-moat)
9. [Where We're Stronger on Types](#where-were-stronger-on-types)
10. [Target Communities](#target-communities)
11. [Execution Playbook Summary](#execution-playbook-summary)
12. [Growth Hacks by Effort-to-Impact](#growth-hacks)
13. [Success Metrics](#success-metrics)
14. [Why People Leave Psalm](#why-people-leave-psalm)
15. [Research Sources](#research-sources)

---

## Key Numbers

- psalm-plugin-laravel: 89K monthly downloads, 4.8M total, 326 GitHub stars, 14 open issues
- Larastan: 2.9M monthly downloads, 42M total, 6,335 stars, ~113 open issues
- Larastan has 33x downloads and 19x stars
- PCI DSS v4.0 became mandatory March 2025 — every Laravel e-commerce app needs SAST evidence
- Psalm v7: 10x taint performance improvement, combined analysis (types + taint + dead code) by default
- Enlightn Pro: $99+/project, rule-based not dataflow
- Semgrep Pro: Laravel rules require paid tier
- Commercial SAST: $15K-250K+/year with no Laravel awareness
- RIPS (only PHP-specialized commercial SAST) acquired by SonarSource in 2020 and killed — market vacuum

## Competitive Landscape

| Tool                     | Laravel-aware types | Taint analysis         | Free                        | Laravel-specific taint stubs |
|--------------------------|---------------------|------------------------|-----------------------------|------------------------------|
| **psalm-plugin-laravel** | Yes                 | Yes                    | Yes                         | Yes (13 security surfaces)   |
| Larastan                 | Yes                 | **No** (PHPStan can't) | Yes                         | N/A                          |
| Enlightn Pro             | Partial             | Partial (dynamic)      | **No** ($99+/project)       | Partial                      |
| SonarQube Enterprise     | Generic PHP         | Yes (generic)          | **No** ($$$)                | **No**                       |
| Semgrep Pro              | **No**              | Pattern-based          | Partial (10 devs free)      | Yes (Pro only)               |
| Snyk Code                | Generic             | Yes (generic)          | Freemium                    | **No**                       |
| Qodana Ultimate Plus     | Generic             | Yes                    | **No** ($15/contributor/mo) | **No**                       |
| Checkmarx/Veracode       | Generic             | Yes                    | **No** ($15K-250K+/yr)      | **No**                       |

**No free tool combines taint analysis with deep Laravel framework knowledge.**

### Competitor Details

**Larastan** (dominant, NOT competing head-on):
- 17 custom Laravel rules, custom PHPDoc types (model-property, view-string, collection-of)
- Bladestan companion for Blade templates
- Nuno Maduro (Laravel core team) is co-maintainer — quasi-official
- Known pain points: belongsToMany bugs, whereHas broken since Laravel 11.42, form request $validated all mixed at level 9

**Semgrep** (closest security competitor):
- Laravel rules require paid Pro tier
- Pattern matching, not dataflow — weaker for cross-function taint flows
- Free tier limited to 10 contributors
- Relicensing caused Opengrep fork — cautionary tale

**Enlightn**: $99+/project Pro, rule-based not static taint, dynamic + static checks

**Phan**: taint-check-plugin used by MediaWiki/Wikipedia — focused entirely on MediaWiki, not Laravel

**SonarQube**: inherited RIPS technology, claims <5% FP rate, no Laravel awareness, Developer Edition from ~EUR 360/year

## Cost Comparison

| Tool                     | Annual Cost (10-person team) | Laravel Awareness | Taint Analysis       | Free Tier                    |
|--------------------------|------------------------------|-------------------|----------------------|------------------------------|
| Checkmarx                | $50K-250K+                   | None              | Yes (generic)        | No                           |
| Veracode                 | $15K-100K+                   | None              | Yes (generic)        | No                           |
| Snyk Team                | $3,000                       | Shallow           | Yes (generic)        | OSS only                     |
| Qodana Ultimate Plus     | $1,800                       | None              | Yes (generic)        | Limited                      |
| SonarQube Developer      | ~$360+ (LOC-based)           | None              | Yes (inherited RIPS) | Community Edition (no taint) |
| Semgrep Pro              | Custom pricing               | Good (Pro rules)  | Pattern-based        | 10 contributors              |
| Enlightn Pro             | $99+/project                 | Yes               | No (rule-based)      | 67 checks free               |
| **psalm-plugin-laravel** | **$0**                       | **Deep**          | **Yes (dataflow)**   | **Fully free**               |

## Current Taint Coverage

| Security Surface               | Stub File                                                                        | What is tracked                                                                 |
|-------------------------------|---------------------------------------------------------------------------------|---------------------------------------------------------------------------------|
| SQL Injection                  | `Database/Connection.stubphp`                                                   | 12 methods with `@psalm-taint-sink sql`                                         |
| Shell Injection                | `Process/PendingProcess.stubphp`                                                | 3 methods with `@psalm-taint-sink shell`                                        |
| File Traversal                 | `Filesystem/Filesystem.stubphp`                                                 | 15 methods with `@psalm-taint-sink file`                                        |
| SSRF                           | `Http/PendingRequest.stubphp`                                                   | 6 HTTP methods with `@psalm-taint-sink ssrf`                                    |
| XSS (Response)                 | `Http/Response.stubphp` + `Routing/ResponseFactory.stubphp`                    | HTML content injection                                                          |
| Open Redirect                  | `Routing/Redirector.stubphp`                                                    | 4 methods (dual: ssrf + header)                                                 |
| Crypto bypass                  | `Encryption/Encrypter.stubphp` + `Hashing/HashManager.stubphp`                 | taint-escape/unescape tracking                                                  |
| Cookie / HTTP Header Injection | `taintAnalysis/Cookie/CookieJar.stubphp`                                        | 5 methods: `make`, `forever`, `queue`, `expire`, `forget` — `@psalm-taint-sink header` |
| Email Header Injection         | `taintAnalysis/Mail/Mailable.stubphp`                                           | `from`, `to`, `cc`, `bcc`, `replyTo`, `subject` sinks (header); `html()` sink (html) |
| Redis Lua Injection            | `taintAnalysis/Redis/Connections/PhpRedisConnection.stubphp`                    | `eval`, `evalsha`, `executeRaw` — `@psalm-taint-sink eval`                     |
| Session Data Sources           | `taintAnalysis/Session/Store.stubphp`                                           | 8 methods as `@psalm-taint-source input` (`get`, `pull`, `all`, `only`, etc.)  |
| XSS via View Data              | `taintAnalysis/View/Factory.stubphp` + `taintAnalysis/View/View.stubphp`        | `make`, `file`, `first`, `renderWhen`, `renderUnless`, `share`, `with` — html sinks |
| XSS via HtmlString bypass      | `taintAnalysis/Support/HtmlString.stubphp`                                      | Constructor as html sink — catches input wrapped in `HtmlString` to bypass `{{ }}` escaping |
| Taint flow propagation         | `taintAnalysis/Support/Str.stubphp` + `taintAnalysis/Support/Stringable.stubphp`| `Str::of()` taint-specialize; `append`, `prepend`, `replace*`, `wrap` etc. propagate via `@psalm-flow` |
| XSS Escape (Js::from)          | `taintAnalysis/Support/Js.stubphp`                                              | `Js::from()` / `Js::encode()` annotated as `@psalm-taint-escape html` — prevents false positives for safely JSON-encoded data in Blade |

## Coverage Gaps

| Gap                                           | Impact | Effort                | Status              |
|-----------------------------------------------|--------|-----------------------|---------------------|
| `e()` helper as `@psalm-taint-escape html`    | High   | Low                   | Open                |
| Log injection sinks                           | Medium | Low                   | Open                |
| Cookie read sources (`$request->cookie()`)    | Medium | Low                   | Open (CookieJar write sinks covered; read source not yet annotated) |
| LDAP injection sinks                          | Low    | Low                   | Open                |
| Insecure deserialization                      | High   | Medium                | Open                |
| Blade `{!! !!}` XSS                           | High   | Hard (template layer) | Open                |
| `validated()` / `safe()` as taint sanitizer   | High   | Medium                | Resolved via ValidationHandler |
| `Mail::raw()` / Mailable header sinks         | Medium | Low                   | Resolved (Mailable.stubphp)     |

## OWASP Top 10 Mapping

| OWASP Category                                  | CWE     | Current Coverage                                                                 | Gap                                |
|-------------------------------------------------|---------|---------------------------------------------------------------------------------|------------------------------------|
| A03:2021 Injection (SQL)                        | CWE-89  | Partial (Connection, Query\Builder)                                             | Need all raw query methods         |
| A03:2021 Injection (OS Command)                 | CWE-78  | Partial (PendingProcess)                                                        | Need exec(), system(), passthru()  |
| A03:2021 Injection (XSS)                        | CWE-79  | Good (Response, ResponseFactory, View\Factory, View, HtmlString bypass)         | Need Blade `{!! !!}`, `e()` escape |
| A03:2021 Injection (Redis/Eval)                 | CWE-94  | Good (PhpRedisConnection: eval, evalsha, executeRaw)                            | —                                  |
| A10:2021 SSRF                                   | CWE-918 | Good (PendingRequest, Redirector)                                               | —                                  |
| A01:2021 Broken Access Control (Path Traversal) | CWE-22  | Good (Filesystem)                                                               | —                                  |
| A02:2021 Crypto Failures                        | CWE-327 | Partial (Encrypter, HashManager)                                                | Informational only                 |
| A07:2021 Identification Failures (Header Inj.)  | CWE-113 | Good (CookieJar: make/forever/queue; Mailable: from/to/cc/bcc/replyTo/subject) | Need cookie read sources           |
| A08:2021 Software Integrity (Deserialization)   | CWE-502 | None                                                                            | Need unserialize sinks             |
| A09:2021 Logging Failures (Log Injection)       | CWE-117 | None                                                                            | Need Log facade sinks              |

## Cross-Function Dataflow Example

This is the key demo showing why taint analysis beats pattern matching. Use in blog posts and talks:

```php
// Pattern matching tools miss this — the taint flows through a helper function:
function getUserQuery(Request $request): string {
    return "SELECT * FROM users WHERE name = '" . $request->input('name') . "'";
}

Route::get('/users', function (Request $request) {
    DB::statement(getUserQuery($request));
    // Psalm catches this: taint flows Request→getUserQuery()→DB::statement()
    // Semgrep free tier does NOT — it can't trace cross-function dataflow
});
```

## The Moat

PHPStan's creator Ondrej Mirtes explicitly rejected taint analysis in Issue #8038:
> "Nope, right now I'm not interested in reviewing and maintaining this."

The community POC (by staabm) was never released. This means PHPStan will not add taint analysis, Larastan structurally cannot add taint analysis, and this gap is permanent.

Psalm's own developers describe taint analysis as "a truck with half a tank of gas" — the engine works but needs framework-specific sources/sinks/flows. This plugin IS the other half of that tank for Laravel.

## Where We're Stronger on Types

Secondary messaging — worth mentioning in comparison content but not leading with. The full picture is in `larastan-vs-plugin.md`. High-signal wins where Larastan has N or ~:

**Validation (biggest gap — Larastan has nothing here):**
- `FormRequest::validated()` returns a typed array shape from rules, not `array<string, mixed>`
- `FormRequest::validated('email')` returns the specific field type (`string`)
- `FormRequest::safe()` returns a full shape (Larastan: only explicit keys, all `mixed`)
- `Request::validate([...])` inline rules are typed through to the result
- `ValidatedInput` accessor types — `integer()`, `boolean()`, `str()` return correct types
- Rule-to-type mapping: `integer` → `int`, `boolean` → `bool`, `email` / `uuid` / `url` → `string`, etc.
- Validation-based taint removal: `integer`, `uuid`, `boolean` rules strip taint from the validated value

**Eloquent type narrowing (Larastan has N):**
- `Model::pluck('column')` narrows the Collection value type from property types
- `Collection::pluck('column')` narrows on model collections
- Aggregate accessor properties: `withCount` → `int`, `withExists` → `bool`, `withSum`/`withAvg` → `numeric-string|null` (v4.7.0)
- `Job::dispatch()` validates that arguments match the job constructor signature (v4.7.0)

**Auth method returns (Larastan has N for both):**
- `Auth::authenticate()` returns non-nullable `Authenticatable` (not `Authenticatable|null`)
- `Auth::loginUsingId()` returns `Authenticatable|false`

**Helpers (Larastan has N):**
- `cache()` overload returns `CacheManager` / `bool` / `mixed` based on argument count
- Path helpers (`app_path()`, `base_path()`, etc.) resolve to actual literal strings at analysis time
- `env()` returns `string|null` or `string|T` where `T` is inferred from the default argument (v4.7.0)

**Other:**
- Auth config parsing — reads `auth.php` to type `$request->user()` correctly per guard
- `Attribute<TGet, TSet>` template types — type-safe accessor handling
- `#[Scope]` attribute support — modern Laravel 12+ scope detection
- AST-based cast parsing — extracts casts without method execution
- Memory-efficient relation proxying — prevents 50+ GB memory explosions

**Framing note:** The Type Inference wins above represent where the plugin invests — precise narrowing for framework-specific patterns. Best practices / linting rules (unused views, `$appends` validation, Octane compat, etc.) are Larastan's territory. Don't chase those in messaging.

## Target Communities

NOT general Laravel — that's Larastan's territory. Target security-adjacent:
- Stephen Rees-Carter (Laravel security auditor) — feedback/endorsement
- Securing Laravel newsletter
- OWASP Laravel cheatsheet contributors
- PHP security groups
- Fintech/healthtech Laravel teams (compliance-driven)
- Psalm community ("the other half of the gas tank" framing)

## Execution Playbook Summary

 - [x] **Phase 1 (Weeks 1-4):** Rebrand README to lead with security, add vulnerability examples, map findings to CWE
 - [ ] **Phase 2 (Weeks 2-8):** Blog posts, audit popular OSS Laravel apps, submit conference talks
 - [ ] **Phase 3 (Weeks 4-12):** GitHub Action "Laravel Security Scan", expand taint coverage (each stub = content opportunity)
 - [ ] **Phase 4 (Weeks 8-24):** "Contribute a Taint Stub" template, recruit contributors, engage security communities, Discord
 - [ ] **Phase 5 (Months 6+):** Compliance docs (CWE-mapped, OWASP matrix, PCI-DSS checklist), consider Pro tier

## Growth Hacks

| Action                               | Effort       | Impact                       | Model                     |
|--------------------------------------|--------------|------------------------------|---------------------------|
| Ship GitHub Action                   | 2-3 days     | Permanent adoption loop      | Semgrep, Bearer, Gitleaks |
| Audit popular OSS Laravel apps       | 3-4 days     | Blog posts + credibility     | Snyk "free scans for OSS" |
| Map findings to CWE                  | 1 day        | Unlocks compliance adoption  | Bearer OWASP/CWE mapping  |
| Submit conference talks              | 1 day        | Months of inbound per talk   | PHPStan's growth playbook |
| Create "contribute a stub" template  | 1-2 days     | Lowers barrier               | Semgrep community rules   |
| Set up Discord                       | 1 hour       | Ongoing engagement           | ESLint community          |
| Partner with security consultancies  | A few emails | Referral pipeline            | Snyk partnerships         |
| Responsible disclosure from findings | Variable     | Strongest credibility signal | All security tools        |
| "State of Laravel Security" report   | 3-5 days     | Press coverage, authority    | Snyk annual reports       |

## Success Metrics

| Metric                                 | Now | 6-month target | Signal                   |
|----------------------------------------|-----|----------------|--------------------------|
| Monthly downloads                      | 89K | 120K           | Growth resumes           |
| GitHub stars                           | 326 | 500            | Awareness                |
| "psalm laravel security" Google rank   | ?   | Page 1         | SEO working              |
| Taint stub files                       | 21  | 35+            | Coverage deepening       |
| External contributors (security stubs) | 0   | 3-5            | Community forming        |
| Blog posts / talks                     | 0   | 3-4            | Content engine           |
| GitHub Action installs                 | 0   | 500+           | New distribution channel |

## Why People Leave Psalm

Headwinds to acknowledge honestly in content:
- PHP version support lag (slow PHP 8.4 support)
- Larger PHPStan plugin ecosystem
- Faster PHPStan development cadence (full-time author)
- Better PHPStan social media presence
- More verbose PHPStan error messages
- "Stricter by default" = more noise for new teams

The security positioning mitigates these: teams add this plugin **for security specifically**, not as their primary type checker.

## Research Sources

- [Psalm Security Analysis docs](https://psalm.dev/docs/security_analysis/)
- [Psalm v7 performance](https://blog.daniil.it/2025/07/10/psalm-v7-up-to-10x-performance/)
- [PHPStan taint rejection (Issue #8038)](https://github.com/phpstan/phpstan/issues/8038)
- [Enlightn](https://www.laravel-enlightn.com/)
- [Larastan](https://github.com/larastan/larastan)
- [Laravel OWASP Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Laravel_Cheat_Sheet.html)
- [Semgrep community rules](https://semgrep.dev/products/community-edition/)
- [PHP SAST benchmarking study](https://www.mdpi.com/1099-4300/27/9/926)
- [Stephen Rees-Carter Laravel security audits](https://stephenreescarter.net/laravel-security-audits-and-pentesting/)
- [ESLint 2025 Year in Review](https://eslint.org/blog/2026/01/eslint-2025-year-review/)
- [OWASP Free Tools](https://owasp.org/www-community/Free_for_Open_Source_Application_Security_Tools)
- [Bearer CLI](https://github.com/Bearer/bearer)
- [Opengrep fork](https://www.opengrep.dev/)
- [Phan taint-check-plugin](https://www.mediawiki.org/wiki/Continuous_integration/Phan/Phan-taint-check-plugin)
