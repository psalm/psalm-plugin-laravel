# Audit Report: {OWNER}/{REPO}

<!--
Template for psalm-package-inspection reports.
Placeholders in {curly braces} are meant to be filled in; leave them as `{...}`
when the value is not yet known and the user will refine later.
Keep sections in this order so subsequent audits are easy to compare.
-->

- **Package**: `{OWNER}/{REPO}` ({stars}-star, {laravel-version-constraint})
- **Cloned to**: `/tmp/psalm-plugin-laravel/{package-name}`
- **Plugin version**: `psalm/plugin-laravel {version}` (Psalm {psalm-version})
- **errorLevel**: `{1|3}`
- **Larastan present in target**: `{yes, level X | no}`
- **Run date**: {YYYY-MM-DD}

<!--
Include the following "Plugin internal errors" section only if
`/tmp/psalm-plugin-laravel-{package}-err.log` is non-empty. Plugin crashes
during analysis are first-class findings — promote them above Totals.
Omit the section entirely when stderr is clean.
-->

## Plugin internal errors

{Paste unique stack traces from the err.log here, one per subsection. Each distinct exception is a Bucket A candidate — cross-reference in the "Confirmed plugin gaps" section below.}

## Totals

{total} issues across {file-count} analyzed files. Top issue types:

| Count | Type |
| ---:  | --- |
| {n}   | {IssueType} |
| {n}   | {IssueType} |
| ...   | ... |

{optional one-line comment: e.g., "Numbers align with the benchmark tracking file — no regression from prior versions."}

## Confirmed plugin gaps (Bucket A)

For each gap: one H3 heading, followed by root cause, impact, and proposed fix. One gap per section — do not bundle.

### {short title of the gap, e.g., "`app(static::class, [...])` returns mixed"}

**Symptom in target**: {what the user sees, with file:line}

```php
{minimal snippet from the package that reproduces the issue}
```

**Cascades to**: `{IssueType1}` ({n}x), `{IssueType2}` ({n}x), ...

**Root cause in plugin**: `{src/path/Handler.php:LINE}` — {one- to two-sentence explanation of what the plugin does and why it is wrong}

**Proposed fix**: {specific change, referencing the exact Psalm API / stub construct}

**Estimated impact**: {direct + cascaded} errors in this package (direct: N, cascaded: N via `--trace` with window {W}); pattern is common across {"the Laravel ecosystem" | "Filament-style packages" | "any package using X"}.

**Reproducer**: `{/tmp/psalm-plugin-laravel/_repros/<gap-slug>/}` — minimal PHP snippet + psalm.xml that fires the issue in isolation. Omit this line only if the gap is marked `confidence: low` below.

**Status**: {"not filed" | "filed as #NNN" | "distinct from #NNN, not filed" | "already exists as #NNN"}

---

{repeat per fully-verified gap}

### Low-confidence candidates

Bucket A suspects that were not fully verified (outside the effort budget: top 3 by cascade + anything over 10% of total issues). Each is one line — the user can escalate individually.

- **{title}** ({n}x): {one-line root-cause hypothesis}. `confidence: low`.
- ...

## Not plugin gaps (Bucket B)

These errors are correct. Do not file against the plugin.

- **{Pattern / function name}** ({n} instances): {one-line explanation, e.g., "`config('key')` is mixed by design — the value can hold anything."}
- **{Pattern}** ({n}): {explanation}

## Third-party typing issues (Bucket C)

Errors whose root cause is another library's type annotations. Do not file against the plugin.

- **{Library / class}** ({n}): {explanation, e.g., "Livewire's `Testable::instance()` returns a loosely typed Component."}
- **{Library / macro}** ({n}): {explanation}

## Noise (Bucket D)

Counts only — included so they do not get mistaken for gaps later.

- `{IssueType}` ({n})
- ...

## Interesting genuine type issues

Up to 5-10 bugs in the package that the plugin flagged correctly. Useful as evidence when pitching the plugin to the maintainers later.

1. `{package/path/File.php:line}` — `{IssueType}`: {short description}
2. ...

## Larastan comparison

{Include this section only if Larastan is installed in the target package.}

- Larastan level: {N} (baseline: {yes/no})
- Overlap: Larastan flags {these | these-same | these-different} categories. Psalm plugin uniquely finds {...}.
- Divergences worth investigating: {...}

**Where to look in `.alies/larastan/`** (cross-reference before filing or proposing a fix):

- `src/Methods/Extension/` — facade, helper, and macro return-type extensions. Primary source for `app()`, `config()`, `resolve()`, `make()`, collection helpers, and facade static-call return types.
- `src/Rules/` — custom static-analysis rules. Useful when the plugin has a parallel rule (e.g. `NoEnvOutsideConfig`) — Larastan's version shows how they handle edge cases.
- `extension.neon` — `services:` catalogue. Scan first to know whether a given Laravel feature has any coverage at all before chasing specific files.

## Suggested next actions

- [ ] File plugin issue for: {gap title}
- [ ] File plugin issue for: {gap title}
- [ ] {other, e.g., "Open a PR on {OWNER}/{REPO} proposing Psalm as a companion to Larastan" — use `psalm-install` skill for that flow}
- [ ] {other}

## Workspace

- Output JSON: `/tmp/psalm-plugin-laravel-{package}-out.json`
- Error log: `/tmp/psalm-plugin-laravel-{package}-err.log`
- psalm.xml: `/tmp/psalm-plugin-laravel/{package-name}/psalm.xml`