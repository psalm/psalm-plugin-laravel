---
name: psalm-app-benchmark
description: "Benchmark psalm-plugin-laravel on real-world Laravel applications and packages (bagisto, coolify, monica, pixelfed, filament, laravel-excel, corcel, etc.) to track issue counts across plugin versions. Runs Psalm with taint analysis on frozen app codebases and generates per-app markdown reports with version-over-version comparison tables. Use this skill when the user asks to benchmark on apps, run app benchmark, compare plugin versions on real projects, track regressions across apps, or test a branch against real-world codebases."
argument-hint: "<git-ref> as <version-label> [app-name]"
---

# Benchmark psalm-plugin-laravel on Real-World Apps

Run Psalm + plugin on frozen real-world Laravel applications to track issue counts across plugin versions.
Apps are pre-installed at `/Users/alies/code/psalm/benchmark-apps/{app-name}` with the plugin
symlinked from `/Users/alies/code/psalm/psalm-plugin-laravel`.

**Arguments:** `<git-ref> as <version-label> [app-name]`

### Run mode (default)

`<git-ref> as <version-label> [app-name]`

- `<git-ref>` -- what to `git checkout` in the plugin repo (branch, tag, or commit).
- `as <version-label>` -- the human-readable version name used in output filenames and table
  columns. Always required. Use semantic versions like `v4.3` or `v4.5.0`.
  When the git ref is already a version tag (e.g., `v4.2.0`), the `as` label and git ref
  will be the same -- that is fine. If the version label is not clear, ask the user.
- `[app-name]` -- optional, run only this app. If omitted, run all apps.

### Examples

```
/psalm-app-benchmark master as v4.3
/psalm-app-benchmark v4.2.0 as v4.2.0 monica
```

## App Registry

Apps are **frozen at specific commits** to ensure reproducible, comparable benchmarks.
Never update app source code, run `git pull`, or change `composer.json`/`composer.lock`.
The only thing that changes between benchmark runs is the plugin version.

| App Name         | Repository                           | Branch | Locked Commit |
|------------------|--------------------------------------|--------|---------------|
| bagisto          | bagisto/bagisto                      | 2.4    | 5b83de2       |
| coolify          | coollabsio/coolify                   | v4.x   | ffd69c1       |
| ixdf-web         | InteractionDesignFoundation/IxDF-web | main   | 9168169       |
| monica           | monicahq/monica                      | main   | e08e917       |
| pixelfed         | pixelfed/pixelfed                    | dev    | 67606eb       |
| solidtime        | solidtime-io/solidtime               | main   | 797fddf       |
| spatie-dashboard | spatie/dashboard.spatie.be           | main   | 2318999       |
| tastyigniter     | tastyigniter/TastyIgniter            | 4.x    | bb57c86       |
| unit3d           | HDInnovations/UNIT3D                 | master | 8b88f4c       |
| vito             | vitodeploy/vito                      | 3.x    | 07d265e       |
| laravel-excel    | SpartnerNL/Laravel-Excel             | 3.1    | 1854739       |
| filament         | filamentphp/filament                 | 4.x    | 7e4b8a5       |
| corcel           | corcel/corcel                        | 9.0    | 83506aa       |

## Storage Paths

- **Benchmark data** (raw JSON, crash logs): `.alies/.track/{app-name}/` — set via `OUTPUT_DIR` env var in `bench.sh`
- **Reports** (markdown): `.alies/.track/{app}.md` and `.alies/.track/SUMMARY.md` — set via `TRACK_DIR` env var in `report.py`

Check `.alies/.track/` for existing data before running, not `data/` (there is no `data/` directory).

## Tracked Metrics

Each benchmark run captures and reports:

- **Issue count** — total Psalm issues per type (all rows in "Results by Plugin Version")
- **Taint issues** — issues with a `taint_trace` (subset of total)
- **Type coverage** — `Psalm can infer types for X% of the codebase` from Psalm's stderr summary.
  Stored as `type_coverage_pct` in `--perf.json`. Shown as a `Coverage` row at the bottom of
  each app's "Results by Plugin Version" table, and as `Avg Coverage` in SUMMARY.md's
  "Total Issues by Version" table.
  Shows `?` for runs made before coverage tracking was added (pre-v4.7.0 data).
- **Wall time** — elapsed seconds (stored in `--perf.json`, shown as `Time` row)
- **Peak memory** — bytes (stored in `--perf.json`)

## Workflow

1. Parse `<git-ref>`, `<version-label>`, and optional `[app-name]` from `$ARGUMENTS`
2. Run benchmark: `bash .Codex/skills/psalm-app-benchmark/scripts/bench.sh "<git-ref>" "<version-label>" [app-name]`
3. Generate reports: `python3 .Codex/skills/psalm-app-benchmark/scripts/report.py [app-name]`
4. Display the summary from bench.sh stdout to the user

Output format is defined in `references/app-report-template.md` — read it if you need to understand the report structure.

## Important Rules

- **Never update apps** -- apps are frozen at specific commits for reproducibility.
  Only `composer update psalm/plugin-laravel -W` is acceptable if needed.
- Never modify the plugin source -- only switch branches/tags
- **Never run benchmarks in parallel** -- sequential only for accurate timing
- **List ALL issue types** in reports -- never condense rows
- **`ixdf-web` and `filament` are skipped on pre-v3.8.0** -- they exceed 100 GB RAM and crash the system.
  Fixed in v3.8.0 by an architecture change, so v3.8.0+ (and v4.x) can run them.
  `bench.sh` enforces this automatically by semver-comparing `VERSION_LABEL` against `v3.8.0`.

