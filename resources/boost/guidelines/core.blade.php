<?php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
?>
## psalm-plugin-laravel

Package provides Laravel-aware static analysis and taint (security) checks to Psalm.
Enabled via `<pluginClass class="Psalm\LaravelPlugin\Plugin"/>` in `psalm.xml`.

### Running

- Types and Security: `{{ $assist->binCommand('psalm') }}`
- Security scan only: `{{ $assist->binCommand('psalm') }} --taint-analysis --no-cache`

For the full security workflow (incremental vs full audit, triage, JSON report parsing), invoke the `psalm-security-analysis` skill instead of running commands directly.

### Rules

- For type issues, never add `@psalm-suppress`. Use `psalm-baseline.xml` instead: generate with `{{ $assist->binCommand('psalm') }} --set-baseline=psalm-baseline.xml`, prune fixed entries with `--update-baseline`.
- For taint (security) findings, fix or escape first: remove the flow, or add `@psalm-taint-escape <kind>` where the value really is sanitized. If neither is practical, record a `psalm-baseline.xml` entry for that one finding. Reserve `@psalm-suppress TaintedInput` for last, because it is not a per-finding suppression (see below). Document the decision inline in code either way, and commit `psalm-baseline.xml` so CI surfaces only new issues.
- **Prefer `@psalm-taint-escape <kind>`** on a docblock above an assignment whose right-hand side is a function call or cast (e.g. `$safe = strval($tainted)` — a bare alias `$safe = $tainted` will not apply the escape). Kinds are lowercase: `header`, `ssrf`, `sql`, `html`, `file`, `callable`, `shell`, `user_secret`, `system_secret`. One kind per `@psalm-taint-escape` line; rationale goes in a `//` comment above the docblock. Prefer `strval()`/`intval()` over `(string)`/`(int)` casts to avoid `RedundantCast`. The kind-specific annotation documents *what* was sanitized, which reviewers care about.
- **Last resort: `@psalm-suppress TaintedInput`** works at method-docblock level and inline, with no variable extraction and no cast, but it makes Psalm stop tracking taint in the whole function or statement it annotates: every taint kind in that scope disappears, including unrelated flows that only pass through it on the way to a sink elsewhere. Prefer a baseline entry, which hides one finding and keeps every other flow in the method visible. Note: the specific sink names (`TaintedHeader`, `TaintedSSRF`, `TaintedSql`, etc.) do **not** work with `@psalm-suppress`; only the generic source-side identifier `TaintedInput` does.
- For the full fix playbook (mail-chain extraction, preserving `class-string<T>`, re-assignments, cascade findings), invoke the `psalm-security-analysis` skill.
- Running Psalm with this plugin executes project code (it boots the app and resolves container bindings), so permission to run it amounts to permission to run `artisan`. Do not run it on an untrusted checkout outside a sandbox.

### Issue docs

For any reported issue code, the full description ships in `vendor/`. Try Psalm core first, then the plugin:

- Psalm core (majority of codes): `vendor/vimeo/psalm/docs/running_psalm/issues/{IssueType}.md`
- Laravel plugin: `vendor/psalm/psalm-plugin-laravel/docs/issues/{IssueType}.md`

To enumerate plugin-emitted codes, list `vendor/psalm/psalm-plugin-laravel/docs/issues/`.
