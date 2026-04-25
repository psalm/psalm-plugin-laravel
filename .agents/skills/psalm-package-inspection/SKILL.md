---
name: psalm-package-inspection
description: Audit a Laravel package or library (Filament, Nova, Horizon, Spatie packages, Livewire components, any package that declares Laravel as a dependency) with psalm-plugin-laravel to find plugin gaps, root-cause Mixed* cascades, and produce a detailed report. Use this skill whenever the user wants to analyze, audit, or inspect a Laravel package with Psalm, run psalm on a library, find where the plugin falls short on real-world code, investigate why a package produces so many MixedAssignment/MixedArgument/MixedMethodCall/DocblockTypeContradiction errors, identify which Psalm errors come from plugin limitations vs genuine code issues, or compare how the plugin handles a third-party Laravel codebase.
disable-model-invocation: true
---

# Psalm Plugin Inspection for Laravel Packages

Audit a Laravel package (library, framework addon, UI kit, or monorepo) with `psalm-plugin-laravel` to identify **plugin gaps** (false positives / missing inference) versus genuine code issues. The output is a detailed markdown report the user can act on, most commonly to file issues on the plugin repo.

## Argument

One target repository per invocation. Accept either:

- Slug: `filamentphp/filament`
- URL: `https://github.com/filamentphp/filament`

Parse `OWNER/REPO` from the invocation. If none was provided, ask the user which package to analyze. Note common mistakes: `filament/filament` does not exist; the actual org is `filamentphp`. Validate with `gh search repos "$SLUG"` if the slug looks wrong before cloning.

## Workflow

Five phases: **setup, run, categorize, verify, report**. Verification is the one most worth taking seriously; an unverified "this looks like a plugin gap" claim is worse than no claim, because the whole point of the report is to drive fixes.

### Phase 1. Setup

#### 1.1 Clone to a predictable path

Working directory convention: `/tmp/psalm-plugin-laravel/<package-name>`. Use only the repo part of the slug as `<package-name>` (so `filamentphp/filament` -> `/tmp/psalm-plugin-laravel/filament`). This makes it easy for the user to find and revisit the workspace across sessions.

```bash
PACKAGE_DIR=/tmp/psalm-plugin-laravel/<package-name>
mkdir -p /tmp/psalm-plugin-laravel
rm -rf "$PACKAGE_DIR"
gh repo clone OWNER/REPO "$PACKAGE_DIR" -- --depth=1
```

#### 1.2 Quick sanity check

```bash
cd "$PACKAGE_DIR"
# Confirm it is Laravel-adjacent
grep -E '"(laravel/framework|illuminate/support|illuminate/contracts)"' composer.json composer.lock | head -3
# Check PHPStan level if present
grep -E 'level\s*:' phpstan.neon phpstan.neon.dist phpstan-*.neon 2>/dev/null | head -3
# Check whether Larastan is installed (useful context for the final report)
grep -E '"(larastan/larastan|nunomaduro/larastan)"' composer.json | head -1
```

If the package only requires `illuminate/support` (not `laravel/framework`), that is still a Laravel package — many libraries only pull in `support`.

#### 1.3 Install dependencies + plugin

```bash
# Existing dependencies first so Laravel can boot inside the plugin
composer install --no-interaction --prefer-dist --no-scripts

# Psalm 7 is currently in beta; allow beta-stable plugin resolution
composer config minimum-stability beta
composer config prefer-stable true

# If this is a monorepo whose sibling packages are path repositories
# (check for a "repositories" block with type: path), stability 'beta' will
# fail because the path repos report themselves as 'dev'. Switch to 'dev'.
if grep -qE '"type":\s*"path"' composer.json; then
    composer config minimum-stability dev
fi

# Always pin ^4.x — Composer otherwise resolves the older v3.x / Psalm 6 line
composer require --dev "psalm/plugin-laravel:^4.2" --no-interaction -W
```

The Filament, Laravel Nova, and Orchestra Testbench monorepos all have path-based sibling packages and need `minimum-stability: dev`. A single-package library (e.g. `spatie/laravel-permission`) does not.

#### 1.4 Write `psalm.xml`

Use `errorLevel="1"`. Level 1 surfaces `MixedAssignment` / `MixedArgument` / `MixedMethodCall` / `MixedReturnStatement`, which is exactly what the user wants to investigate. Level 3 suppresses them and produces a misleadingly clean report for this use case.

Start from `references/psalm-xml-template.md` and adapt `<projectFiles>` to the package's actual source directories. Read `composer.json`'s `autoload.psr-4` block — every mapped directory is a candidate for `<projectFiles>`. Exclude tests, resources/views, migrations, and any `bin`/`Rector` scaffolding that the package itself excludes from PHPStan. Wildcards are **not** allowed in `<directory name="...">`, so expand them explicitly.

Keep `<failOnInternalError>true</failOnInternalError>` — plugin internal errors are themselves findings for the report.

#### 1.5 Laravel environment (usually not needed)

Package analyses rarely need `.env`, a database, or key generation. Skip these unless the package's bootstrap explicitly demands them; if it does, set up the minimum viable environment (`.env.example -> .env`, `php artisan key:generate`) only for the failing step.

### Phase 2. Run Psalm

```bash
cd "$PACKAGE_DIR"
php -d memory_limit=-1 ./vendor/bin/psalm \
  --no-cache --no-diff --no-progress --no-suggestions \
  --output-format=json \
  > /tmp/psalm-plugin-laravel-<package>-out.json \
  2> /tmp/psalm-plugin-laravel-<package>-err.log
```

Run in the background for any package larger than a few hundred files — a monorepo like Filament takes several minutes. A Monitor watch (or a sleeping bash loop) beats polling.

Do **not** add `--taint-analysis` for this skill. Taint analysis is valuable for `psalm-install`-style security audits; here it inflates the workload for questions the user is not asking. If taint coverage is specifically requested, run a second pass with `--taint-analysis` and summarize separately.

If the psalm invocation exits with a config error ("Could not resolve config path" / unsupported wildcard), fix `psalm.xml` and rerun — do not paper over it.

#### 2.1 Surface plugin internal errors

`failOnInternalError="true"` routes plugin-side exceptions to stderr, not the JSON output. These are first-class findings — a plugin crash during analysis is more valuable than any number of `Mixed*` reports. Always check the err.log before moving on:

```bash
# If non-empty, the contents belong at the top of the report as an "Internal errors" section.
test -s /tmp/psalm-plugin-laravel-<package>-err.log && head -200 /tmp/psalm-plugin-laravel-<package>-err.log
```

If the err.log has contents, add them verbatim (trimmed to the unique stack traces) to a new top-of-report section labelled **"Plugin internal errors"**, placed above **Totals**. Treat each distinct exception as its own Bucket A candidate during Phase 4.

### Phase 3. Categorize

The goal is a clear partition of findings into:

| Bucket | Meaning | Report treatment |
| --- | --- | --- |
| **A. Plugin gap (confirmed)** | Error exists because the plugin does not model a Laravel / Eloquent / container pattern correctly | Candidate for a plugin issue. Include root cause + proposed fix. |
| **B. Correct behavior (not a gap)** | Error reflects genuine `mixed` or a real type problem in the package code | Explain why the plugin is right. |
| **C. Third-party gap** | Error comes from another library's typing (Livewire, Spatie, etc.) | Note the library, do not file against the plugin. |
| **D. Noise** | Strictness artifacts the user explicitly tolerates | Mentioned only in totals. |

Use `scripts/analyze.py` to build the initial breakdown — it prints issue type counts, top files per type, and the most common message snippets. Those "top snippets" are the single fastest way to spot cascading root causes: if 50+ `MixedMethodCall` errors all share the snippet `$static->configure();`, there is one upstream cause, not fifty.

```bash
python3 .Codex/skills/psalm-package-inspection/scripts/analyze.py \
  /tmp/psalm-plugin-laravel-<package>-out.json
```

For drill-downs (prefer these over ad-hoc Python one-liners):

```bash
# Every finding of one type, grouped by file.
python3 scripts/analyze.py out.json --filter-type MixedAssignment

# Everything in one file (substring match against file_name).
python3 scripts/analyze.py out.json --filter-file Zip.php

# Regex over snippets + cascade-size estimate. The cascade count estimates
# how many downstream errors (within --cascade-window lines of a hit, in the
# same file) likely share the same root cause.
python3 scripts/analyze.py out.json --trace 'app\([A-Z]\w+::class\)'
python3 scripts/analyze.py out.json --trace 'config\(' --cascade-window 50
```

Use `--trace` as the primary quantification tool for Bucket A claims — the "direct + cascaded" total is what goes into the gap section's "Estimated impact".

### Phase 4. Verify each suspected plugin gap

For every candidate Bucket A finding:

1. **Read the actual code in the target package.** What does the Psalm error really point at? A top snippet like `$static = app(static::class, [...])` tells you the error is on a `class-string<static>` arg to `app()`.
2. **Read the corresponding plugin source.** For type providers check `src/Handlers/**/*.php`; for stubs check `stubs/common/**/*.stubphp` (plus `stubs/12/`, `stubs/13/` for version-specific ones); for Application container behavior check `src/Util/ContainerResolver.php` and `src/Providers/`.
3. **Prove the root cause.** You should be able to point at a specific line of plugin code that explains the failure — e.g., "`ContainerResolver::resolvePsalmTypeFromApplicationContainerViaArgs()` only handles `isSingleStringLiteral()`, so `class-string<static>` falls through to `mixed`."
4. **Build a minimum reproducer.** Write the smallest failing snippet (typically 5 to 15 lines) to an isolated project and confirm the issue fires *without* the target codebase. This is the strongest proof a gap is real and is the artifact reviewers on the plugin repo will ask for anyway.

   ```bash
   REPRO=/tmp/psalm-plugin-laravel/_repros/<gap-slug>
   mkdir -p "$REPRO/app"
   cat > "$REPRO/psalm.xml" << 'XMLEOF'
   <?xml version="1.0"?>
   <psalm errorLevel="1" findUnusedCode="false"
       xmlns="https://getpsalm.org/schema/config">
       <projectFiles><directory name="app" /></projectFiles>
       <plugins><pluginClass class="Psalm\LaravelPlugin\Plugin"><failOnInternalError>true</failOnInternalError></pluginClass></plugins>
   </psalm>
   XMLEOF
   # Write the minimal snippet to "$REPRO/app/Repro.php" then:
   cd "$REPRO" && /path/to/target/vendor/bin/psalm --no-cache --no-progress
   ```

   When the reproducer fires the same issue, attach its path to the gap section in the report (`Reproducer: /tmp/psalm-plugin-laravel/_repros/<gap-slug>/`). When it does *not* fire in isolation, the gap is not actually in the plugin — demote the finding to Bucket B or C before writing it up.
5. **Estimate impact.** Use `scripts/analyze.py --trace '<snippet-regex>'` to report both direct hits and the cascade-size estimate (issues within `--cascade-window` lines of each hit in the same file). The `direct + cascaded` total is the number that goes in the report and lets the user prioritize which issues to file first.
6. **Check against Larastan (when installed in the package).** If the package already uses Larastan, look up how Larastan handles the same pattern — it is often the cleanest source for a proposed fix. Larastan source is available at `.alies/larastan/` for reference across sessions; use it rather than asking the user to install Larastan ad hoc. The most useful cross-reference points:
   - `.alies/larastan/src/Methods/Extension/` — facade, helper, and macro return-type extensions. Cross-check here first for `app()`, `config()`, `resolve()`, `make()`, collection helpers, and any facade static-call return type question.
   - `.alies/larastan/src/Rules/` — custom static-analysis rules. Useful when the plugin has a parallel rule (e.g. `NoEnvOutsideConfig`) — Larastan's rule file shows how they handle edge cases.
   - `.alies/larastan/extension.neon` — the `services:` catalogue. Lists every extension Larastan registers, so scanning it tells you whether a given Laravel feature has coverage at all before you chase individual files.

Skip this verification step and the report will be wrong. Psalm's message text is not enough to classify a finding; only the plugin-side evidence is.

**Effort budget**: verification is expensive — do not attempt it on every candidate. Concretely:

- **Fully verify** the top 3 gaps by cascade count (from `--trace` totals), plus any gap whose direct+cascaded count exceeds 10% of total issues.
- **List without deep verification** the remaining Bucket A candidates in a separate sub-section and mark them `confidence: low`. A one-line description is enough; the user can escalate them individually later.

This keeps reports comparable across audits and prevents rat-holing on long-tail patterns that may or may not be real gaps.

### Phase 5. Write the report

Fill in `references/report-template.md` with the findings. The template is structured for easy updating — leave placeholders (`{...}`) for sections the user will refine later, and populate the rest fully from the audit data.

Before marking any Bucket A gap as `Status: not filed`, check the plugin repo for existing issues (both open and closed) that cover the same root cause. Duplicates waste the maintainer's time and make the report look unresearched:

```bash
gh issue list --repo psalm/psalm-plugin-laravel --state all \
    --search "<keyword>" --json number,title,state --limit 5
```

Good keywords: the handler/class name from the root cause (`ContainerResolver`, `NoEnvOutsideConfig`), the Psalm error type (`MixedAssignment`), or the Laravel API at play (`config`, `app`, `resolve`). When a related-but-distinct issue exists, link it in the gap section (e.g. `Status: distinct from #750, not filed`) so the reader can see the relationship immediately.

Save the filled report to `.alies/docs/reports-packages/<package>.md` (relative to the plugin repo root: `/Users/alies/code/psalm/psalm-plugin-laravel/.alies/docs/reports-packages/<package>.md`). Use only the repo part of the slug as `<package>` (matching the `<package-name>` convention from Phase 1.1, so `spatie/laravel-activitylog` -> `laravel-activitylog.md`). Create the directory with `mkdir -p` if it does not exist. Tell the user the path. Do not commit anything or open issues/PRs automatically; those are follow-up actions the user decides on after reading the report.

## Common patterns and their classification

These recur across Laravel package audits. Use them as prior probabilities, not shortcuts — verify each one in the package under analysis before claiming it.

- **`app(static::class, [...])` / `resolve(static::class, [...])` -> mixed**: plugin gap. `ContainerResolver` only resolves literal class-strings; `class-string<static>` falls through. Cascades into `MixedAssignment` on the assignment, `MixedMethodCall` on the next `->method()` call, and `MixedArgument` at the next call site. One gap, three reported issue types.
- **`filled($nullableString)` / `blank($nullableString)` -> literal `false`**: plugin gap in `stubs/common/Support/helpers.stubphp`. The conditional return type includes `''` in the "falsy" branch, and Psalm considers `''` a subtype of `string`, collapsing the result to `false`. Manifests as `DocblockTypeContradiction: Operand of type false is always falsy` on idiomatic `if (filled($x = method()))` guards. Typical share: 40-60% of all `DocblockTypeContradiction`.
- **`config('some.key')` -> mixed**: not a plugin gap *today*, but a missing feature. The plugin has the infrastructure (`ConfigRepositoryProvider`) but only uses it for auth. Worth filing as an enhancement if many errors trace to this.
- **`$this->instance()->...` in Livewire testing traits -> `MixedMethodCall`**: third-party gap (Livewire's `Testable::instance()` returns a loosely typed Component). Not a plugin issue; do not report.
- **`$data[$key]` on `array<string, mixed>`**: correct behavior. Many config/state/session arrays really are mixed.
- **`$record->{$relationName}()` (dynamic relation call)**: plugin limitation that is hard to fix without runtime info. Note in report but do not file.
- **Magic methods on `ComponentAttributeBag` / macroed classes**: third-party (macros are registered at runtime in user service providers). Not a plugin issue.

## Tone of the final report

Neutral and factual. The user uses the report to file plugin issues — under-claiming wastes their time, over-claiming discredits them. For each Bucket A finding, say: what the plugin does now, where (file and line), why it is wrong, and what to change. For each Bucket B/C finding with a meaningful count, explain briefly why it is not a plugin gap so the user does not have to re-derive that on read two.

## Files in this skill

- `references/psalm-xml-template.md`: starting point for the per-package `psalm.xml`.
- `references/report-template.md`: the markdown structure the report follows. Keep it in sync with any change to the workflow.
- `scripts/analyze.py`: reads Psalm JSON and prints issue counts, top files, top snippets, and root-cause hot spots. Non-destructive — safe to run repeatedly.