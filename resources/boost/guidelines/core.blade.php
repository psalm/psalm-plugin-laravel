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
- For taint (security) false positives, the opposite applies: document the decision inline in code, **not** in the baseline. Commit `psalm-baseline.xml` so CI surfaces only new issues; many projects also fail CI if `<Tainted*>` entries appear there.
- **Prefer `@psalm-taint-escape <kind>`** on a docblock above an assignment whose right-hand side is a function call or cast (e.g. `$safe = strval($tainted)` — a bare alias `$safe = $tainted` will not apply the escape). Kinds are lowercase: `header`, `ssrf`, `sql`, `html`, `file`, `callable`, `shell`, `user_secret`, `system_secret`. One kind per `@psalm-taint-escape` line; rationale goes in a `//` comment above the docblock. Prefer `strval()`/`intval()` over `(string)`/`(int)` casts to avoid `RedundantCast`. The kind-specific annotation documents *what* was sanitized, which reviewers care about.
- **Fallback: `@psalm-suppress TaintedInput`** — works at method-docblock level and inline, with no variable extraction and no cast. Use it only when `@psalm-taint-escape` would add meaningful code (e.g. splitting a chained expression, preserving a `class-string<T>`, handling a sink-free method that returns a tainted expression). Note: the specific sink names (`TaintedHeader`, `TaintedSSRF`, `TaintedSql`, etc.) do **not** work with `@psalm-suppress` — only the generic source-side identifier `TaintedInput` does.
- For the full fix playbook (mail-chain extraction, preserving `class-string<T>`, re-assignments, cascade findings), invoke the `psalm-security-analysis` skill.

### Issue docs

For any reported issue code, the full description ships in `vendor/`. Try Psalm core first, then the plugin:

- Psalm core (majority of codes): `vendor/vimeo/psalm/docs/running_psalm/issues/{IssueType}.md`
- Laravel plugin: `vendor/psalm/psalm-plugin-laravel/docs/issues/{IssueType}.md`

To enumerate plugin-emitted codes, list `vendor/psalm/psalm-plugin-laravel/docs/issues/`.
