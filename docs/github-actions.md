---
title: GitHub Actions
nav_order: 3
---

# Running Psalm on GitHub Actions

The plugin ships a ready-to-commit workflow and a CLI command that installs it for you. The CLI does the main job, so this page is reference for understanding and customizing what it generates.

## Generate the workflow

```bash
./vendor/bin/psalm-laravel add github
```

This writes `.github/workflows/psalm.yml`, copied verbatim from the plugin's bundled template ([view on GitHub](https://github.com/psalm/psalm-plugin-laravel/blob/master/resources/ci/github-actions/psalm.yml)). Pass `--force` to overwrite an existing file.

## What it generates

A single Psalm job that, in one run, produces three outcomes:

* **Inline annotations.** Psalm auto-selects the GitHub format on stdout when it detects Actions, so findings appear on the PR's Files changed tab.
* **SARIF upload.** Results go to the Security tab (Code Scanning) via `github/codeql-action/upload-sarif`. The step is skipped on fork and Dependabot pull requests, where GitHub caps the `GITHUB_TOKEN` at read-only and the upload would fail. Those PRs are still gated by the annotations and the failing step below.
* **Failing gate.** A final step reads the SARIF and fails the build on any error-level finding.

The run is a plain `./vendor/bin/psalm`, no `--taint-analysis` flag. Psalm 7 enables taint analysis by default, so one run covers both type and taint analysis and reports both. On Psalm 6.x the flag is required (and switches Psalm to a taint-only mode), which is why a separate template targets that version.

The template also pins every action to a commit SHA and hardens the runner with a blocking egress policy plus an allowed-endpoints allowlist. Checkout runs with `persist-credentials: false` so the `GITHUB_TOKEN` is not left in the git config for later steps to reuse (nothing runs a git operation after checkout, and the SARIF upload uses the Actions token, not git credentials). All are documented inline in the generated file.

## Customizing

The generated file carries inline comments for each knob. The common edits:

* **PHP version.** Set `PSALM_PHP_VERSION` in the `env:` block at the top (8.2+ is supported by the plugin). The `setup-php` step and the cache key both read it, so a bump starts a fresh cache instead of reusing entries serialized by another runtime.
* **Default-branch baseline.** Add your release branches under `push:` so Code Scanning builds the baseline it diffs PRs against.
* **Egress allowlist.** Extend `allowed-endpoints` if your build reaches other hosts (private Composer registry, VCS or path repos, extra Psalm plugins). A blocked call shows in the step log. Switch to `egress-policy: audit` to discover endpoints without failing the build.

**Private repositories need [GitHub Advanced Security](https://docs.github.com/en/get-started/learning-about-github/about-github-advanced-security).** Code Scanning (the SARIF upload) is free for public repos but requires GHAS for private ones. Without it the upload step fails with `Code Security must be enabled for this repository`. If you do not have GHAS, drop the upload-sarif step and the `security-events: write` permission. The inline annotations still work for free.

### Performance

The generated workflow does three things a hand-written Psalm job usually misses: it persists Psalm's cache, sets both thread counts, and installs `igbinary`.

**Persisting the cache** is the largest of the three. Psalm's cache holds parsed statements plus file and class-like storage, so restoring it removes most of the scan phase. Measured on a 7,600-file Laravel codebase by saving on one runner and restoring on another: the scan phase went 90.9s to 18.7s and the whole run 158s to 78s.

Four details in the generated steps are load-bearing.

* **Restore after Composer, not before.** The key hashes `composer.lock`, and `hashFiles` is evaluated when the step runs. Placing the restore after the install lets the key see the lock file the run actually installed.
* **Split `actions/cache/restore` from `actions/cache/save`.** A single combined step skips its save on a key hit, so a snapshot whose key does not move never rolls forward. The key carries `github.sha` so every default-branch commit writes a fresh generation. (Keeping the combined step and putting a commit SHA in its key works too; the split is simply harder to get wrong.)
* **Qualify the `restore-keys` fallback.** Psalm names each cache generation directory with a hash covering `composer.lock`'s contents and your config, so an entry built from a different lock file misses internally in every subcache while still costing the download. A bare `psalm-` catch-all fires precisely when the archive is unusable, and on the default branch that is worse than a plain miss: Psalm writes the new generation alongside the restored dead one, never collects stale sibling directories, and the save then archives both. The generated fallback repeats the lock and config hash verbatim and varies only the per-commit part.
* **Save only on the default branch, and on `!cancelled()`.** Pull request entries land under `refs/pull/<n>/merge`, which nothing else can read, so they spend the repository's cache budget for one run. `!cancelled()` rather than the implicit `success()` keeps a red default branch refreshing the snapshot: entries are validated against a per-file content hash on read, so a run that reported findings still leaves a correct cache.

No allowlist change is needed for any of this. `harden-runner` resolves the Actions cache blob host itself under `egress-policy: block` and appends it to the allowed endpoints; when that lookup fails its agent allows the cache endpoints implicitly. Listing them by hand would not help either, since the blob host is drawn from a numbered pool that rotates per run.

The generated `path` is `~/.cache/psalm`, Psalm's default on Linux (the XDG home cache directory). Change it if your `psalm.xml` sets `cacheDirectory`.

**Threads.** Psalm forces a single thread whenever it detects CI, and it decides the two phases independently: `--threads` covers analysis, `--scan-threads` covers scanning (`Cli\Psalm::getThreads()`). Setting only `--threads` leaves the whole scan phase serial. On the same codebase a fully single-threaded run measured 177s against 47s.

Note that `psalm.xml`'s `threads` and `scanThreads` attributes will not do this for you. `getThreads()` tests for CI before it reads either, so in CI only the command-line flags have an effect.

The template uses plain numbers rather than `$(nproc)`, defaulting to 4 for `ubuntu-latest`. `nproc` is absent on macOS runners, Windows runners default to PowerShell, and inside a container coreutils older than 9.8 reports the host's cores rather than the cgroup quota, which over-subscribes and can end in an OOM kill. Raise both to your runner's core count.

On runners with many cores the analysis optimum sits slightly below the core count, since the per-worker merge grows as the analysis shrinks: on a 16-core runner, 12 threads beat 16 (47s against 50s). Sweep it if your runner is large. `--scan-threads` is the one to sweep separately: it pays off on a cold cache and is neutral to slightly negative once the cache is warm, since the parse work it parallelises has already been done.

**igbinary.** Psalm's `ForkContext` uses it to serialise each worker's results back to the parent, falling back to PHP's native serializer when absent. On the same codebase that was roughly 6s of thread-merge with it against 50s without. It also selects the cache serializer, which Psalm folds into the generation hash, so adding or removing the extension invalidates existing caches once.

**Do not add an OPcache or JIT `ini-values` block.** The one that circulates (originally from the `ghcr.io/danog/psalm` image) does nothing here: Psalm restarts PHP through `PsalmRestarter`, which re-injects its own value for every `opcache.*` setting that block passes, and JIT stays off unless you pass `--force-jit` or set `forceJit="true"`. Forcing it on is not the fix either; measured, it enables JIT and produces the worst scan time of any variant tried.

**Do not pair the cache with `git-restore-mtime-action`,** which earlier versions of this page suggested on the grounds that `git checkout` resets mtimes. Psalm validates its file and class-like storage caches against a content hash of each file, and its parser cache against the file's contents directly, so **your project's** source mtimes do not enter into it. The one component that did key on `filemtime()` of project files was this plugin's migration schema cache, fixed in [#1346](https://github.com/psalm/psalm-plugin-laravel/pull/1346). A full-history checkout plus an mtime restore costs roughly 70s on a repository with real history and buys nothing.

For reference, the hash naming a cache generation directory folds in: `composer.lock`'s contents, `Config::computeHash()` (your `psalm.xml`), the serializer, `PHP_PARSER_VERSION` for the parser cache, and `filemtime()` of Psalm's own vendor sources plus, when a plugin registers an `AfterClassLikeVisit` handler (this one does), every registered plugin path. Those mtimes come from `vendor/`, not from your checkout, and Composer preserves them on extraction, so they are stable across runners and re-installs.

## Troubleshooting

**Psalm runs out of memory.** Raise the PHP memory limit on the Psalm step:

```yaml
      - name: Run Psalm
        run: php -d memory_limit=4G ./vendor/bin/psalm --report=psalm.sarif --report-show-info=false
```

**Plugin cannot find the Laravel app.** Ensure `psalm.xml` registers the plugin and `composer.json` requires `laravel/framework`. The plugin boots a minimal Laravel app during analysis, so it needs a working Composer autoloader.
