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

The generated workflow does three things a hand-written Psalm job usually misses, all measured on a 7,600-file Laravel codebase:

* **Persists Psalm's cache** between runs, which removes most of the scan phase. A whole run went 158s to 78s. This is the largest of the three.
* **Sets both thread counts.** Psalm forces a single thread whenever it detects CI, and decides scanning and analysis separately, so passing only `--threads` leaves the scan phase serial. Fully single-threaded measured 177s against 47s. Note that `psalm.xml`'s `threads` and `scanThreads` attributes cannot do this: `getThreads()` tests for CI before it reads them.
* **Installs `igbinary`**, which Psalm's `ForkContext` uses to serialise worker results back to the parent. Roughly 6s of thread-merge with it against 50s without.

Each of these is a small number of steps in the generated file, and every non-obvious detail (why the cache restore sits after Composer, why restore and save are separate steps, why the `restore-keys` fallback is qualified, why the save keeps `success()`) is explained in an inline comment at the step it belongs to. Read the file before changing any of them.

Two knobs are worth turning for your project: raise `PSALM_THREADS` and `PSALM_SCAN_THREADS` to your runner's core count, and point the cache `path` at your own directory if `psalm.xml` sets `cacheDirectory`. Neither fails loudly when left wrong; they just run slower or cache nothing.

Two things not to add, both of which look like speedups and are not:

* **An OPcache or JIT `ini-values` block.** The one that circulates (originally from the `ghcr.io/danog/psalm` image) does nothing: Psalm restarts PHP through `PsalmRestarter`, which re-injects its own value for every `opcache.*` setting that block passes, and JIT stays off unless you pass `--force-jit`. Forcing it on measured slower.
* **`git-restore-mtime-action`.** Earlier versions of this page suggested pairing it with the cache, on the grounds that `git checkout` resets mtimes. Psalm validates its caches against a content hash of each file, so your project's source mtimes never enter into it. The one component that did key on `filemtime()` of project files was this plugin's migration schema cache, fixed in [#1346](https://github.com/psalm/psalm-plugin-laravel/pull/1346). A full-history checkout plus an mtime restore costs roughly 70s on a repository with real history and buys nothing.

## Troubleshooting

**Psalm runs out of memory.** Raise the PHP memory limit on the Psalm step:

```yaml
      - name: Run Psalm
        run: php -d memory_limit=4G ./vendor/bin/psalm --report=psalm.sarif --report-show-info=false
```

**Plugin cannot find the Laravel app.** Ensure `psalm.xml` registers the plugin and `composer.json` requires `laravel/framework`. The plugin boots a minimal Laravel app during analysis, so it needs a working Composer autoloader.
