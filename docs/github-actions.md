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

* **PHP version.** Pin `php-version` in the `setup-php` step to match your project (8.2+ is supported by the plugin).
* **Default-branch baseline.** Add your release branches under `push:` so Code Scanning builds the baseline it diffs PRs against.
* **Egress allowlist.** Extend `allowed-endpoints` if your build reaches other hosts (private Composer registry, VCS or path repos, extra Psalm plugins). A blocked call shows in the step log. Switch to `egress-policy: audit` to discover endpoints without failing the build.

**Private repositories need [GitHub Advanced Security](https://docs.github.com/en/get-started/learning-about-github/about-github-advanced-security).** Code Scanning (the SARIF upload) is free for public repos but requires GHAS for private ones. Without it the upload step fails with `Code Security must be enabled for this repository`. If you do not have GHAS, drop the upload-sarif step and the `security-events: write` permission. The inline annotations still work for free.

### Performance

The generated workflow sets `PSALM_THREADS` and `PSALM_SCAN_THREADS` and installs `igbinary`, because both matter more than they look.

**Threads.** Psalm forces a single thread whenever it detects CI, and it decides the two phases independently: `--threads` covers analysis, `--scan-threads` covers scanning (`Cli\Psalm::getThreads()`). Setting only `--threads` leaves the whole scan phase serial. On a 7,600-file Laravel codebase a fully single-threaded run measured 177s against 47s.

The template uses plain numbers rather than `$(nproc)`, defaulting to 4 for `ubuntu-latest`. `nproc` is absent on macOS runners, Windows runners default to PowerShell, and inside a container coreutils older than 9.8 reports the host's cores rather than the cgroup quota, which over-subscribes and can end in an OOM kill. Raise both to your runner's core count.

`--scan-threads` is worth setting here because this template runs without a persisted cache, so every run scans cold. With a warm cache it is neutral to slightly negative, since the parse work it parallelises has already been done. On runners with many cores the optimum sits slightly below the core count, since the per-worker merge grows as the analysis shrinks: on a 16-core runner, 12 threads beat 16 (47s against 50s). Sweep it if your runner is large.

**igbinary.** Psalm's `ForkContext` uses it to serialise each worker's results back to the parent, falling back to PHP's native serializer when absent. On the same codebase that was roughly 6s of thread-merge with it against 50s without.

**Persisting the cache** is the largest win, worth about 80s on that codebase (a whole run went 158s to 78s), but it is left out of the generated workflow deliberately: `actions/cache` needs endpoints that the template's `egress-policy: block` allowlist does not include, and the exact hosts vary. Add it with `egress-policy: audit` first to discover them, then extend `allowed-endpoints`.

If you do add it, two details are easy to get wrong:

* **Make the key roll forward.** `actions/cache` skips the save on an exact key hit, so a combined step whose key does not change never refreshes its snapshot. Either put something per-commit in the key (a commit SHA) and keep the combined step, or split into `actions/cache/restore` plus `actions/cache/save`. The split is the easier of the two to get right, not the only option.
* **Qualify the `restore-keys` fallback.** Psalm partitions its cache directory by a hash covering `composer.lock`'s contents and your config, so an entry built from a different lock file misses internally in every subcache. A bare catch-all prefix therefore tends to fire precisely when the restored archive is unusable, and on the default branch that is worse than a plain miss: Psalm writes the new generation alongside the restored dead one, never collects stale sibling directories, and the save then archives both. Qualify the fallback with the same lock and config hash as the primary key, and vary only the per-commit part.

Earlier versions of this page suggested pairing the cache with `git-restore-mtime-action`, on the grounds that `git checkout` resets mtimes. Drop it. Psalm validates its file and class-like storage caches against a content hash of each file, and its parser cache against the file's contents directly, so **your project's** source mtimes do not enter into it. The one component that did key on `filemtime()` of project files was this plugin's migration schema cache, fixed in [#1346](https://github.com/psalm/psalm-plugin-laravel/pull/1346). A full-history checkout plus an mtime restore costs roughly 70s on a repository with real history and buys nothing.

Psalm's cache-generation directories do still fold in `filemtime()` of Psalm's own files and of any plugin paths, but those come from `vendor/`, not from your checkout, and Composer preserves their timestamps on extraction.

## Troubleshooting

**Psalm runs out of memory.** Raise the PHP memory limit on the Psalm step:

```yaml
      - name: Run Psalm
        run: php -d memory_limit=4G ./vendor/bin/psalm --report=psalm.sarif --report-show-info=false
```

**Plugin cannot find the Laravel app.** Ensure `psalm.xml` registers the plugin and `composer.json` requires `laravel/framework`. The plugin boots a minimal Laravel app during analysis, so it needs a working Composer autoloader.
