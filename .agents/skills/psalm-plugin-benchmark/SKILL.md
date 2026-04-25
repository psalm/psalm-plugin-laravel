---
name: psalm-plugin-benchmark
description: "Benchmark psalm-plugin-laravel performance overhead on a real large Laravel project (IxDF). Runs Psalm with and without the plugin, reports time/memory in a comparison table. Use this skill when the user asks to benchmark, measure performance, check overhead, run perf tests, or compare plugin speed. Also use proactively after significant handler or stub changes to verify no performance regression."
argument-hint: "[runs] - number of runs per config (default: 3)"
---

# Plugin Performance Benchmark

Measure the performance overhead of psalm-plugin-laravel by running Psalm on the IxDF test project with and without the plugin.

## Quick Run

Parse `$ARGUMENTS` for the number of runs (default: 3). Then run the automated benchmark script:

```bash
php .Codex/skills/psalm-plugin-benchmark/scripts/bench.php --runs=<N>
```

This script handles everything automatically:
1. Updates Psalm to latest dev-master in the test project
2. Ensures the plugin symlink points to the main plugin repo (fixes stale worktree symlinks)
3. Creates a temporary no-plugin psalm config
4. Runs Psalm N times without plugin, then N times with plugin (Psalm auto-detects threads)
5. Parses timing and memory from each run
6. Outputs a markdown comparison table with overhead percentages
7. Cleans up temporary files
8. Exits with code 0 (PASS: overhead <=15%) or 1 (FAIL: >15%)

Each run takes ~60-75 seconds, so total time is roughly `N × 2 × 70s`. For 3 runs, expect ~7 minutes.

Present the script's markdown output directly to the user.

## If Overhead Exceeds 15%

When the verdict is FAIL, investigate the root cause:

1. **Run the microbenchmark** for component-level profiling:
   ```bash
   php .Codex/skills/psalm-plugin-benchmark/scripts/microbench.php --models=150 --migrations=300 --properties=50
   ```
   This profiles SchemaAggregator, ReflectionProperty lookups, handler dispatch, object cloning, and stub scanning individually.

2. **Phase analysis** — identify whether scanning or analysis is the bottleneck:
   ```bash
   cd /Users/alies/code/psalm/IxDF-as-example && rm -rf .cache/psalm
   php -d memory_limit=-1 vendor/bin/psalm -c psalm.xml --no-suggestions --no-cache --debug 2>&1 \
     | grep -nE '^(Scanning files|Analyzing files|Checks took)'
   ```
   - Line numbers tell you when each phase started; compare with total line count to estimate phase duration.
   - Scanning overhead → too many or too large stubs
   - Analysis overhead → handler hot paths

3. **Common culprits:**
   - New handlers with overly broad `getClassLikeNames()` or `AfterClassLikeVisit` hooks that fire for every class
   - Large new stub files adding scanning time
   - Uncached lookups in handlers (missing `$cache` arrays)
   - Object cloning in hot paths (the `ProxyMethodReturnTypeProvider` pattern)

## Typical Overhead Profile

From profiling on IxDF (~5,875 PHP files, ~150 models):
- **Plugin init** (boot Laravel, parse migrations, generate alias stubs): ~0.3s
- **Stub scanning** (52 stub files, ~4,500 lines): ~5s — the main inherent cost
- **Handler execution** (type providers during analysis): ~2s — well-scoped
- **Total typical overhead:** ~8-10% time, ~10% memory
