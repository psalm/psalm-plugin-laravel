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

This writes `.github/workflows/psalm.yml` (copied verbatim from the plugin's bundled template). Use `add ci` instead of `add github` to auto-detect the provider, and `--force` to overwrite an existing file.

## What it generates

A single Psalm job that, in one run, produces three outcomes:

* **Inline annotations.** Psalm auto-selects the GitHub format on stdout when it detects Actions, so findings appear on the PR's Files changed tab.
* **SARIF upload.** Results go to the Security tab (Code Scanning) via `github/codeql-action/upload-sarif`.
* **Failing gate.** A final step reads the SARIF and fails the build on any error-level finding.

The run is a plain `./vendor/bin/psalm`, no `--taint-analysis` flag. Psalm 7 enables taint analysis by default, so one run covers both type and taint analysis and reports both. On Psalm 6.x the flag is required (and switches Psalm to a taint-only mode), which is why a separate template targets that version.

The template also pins every action to a commit SHA and hardens the runner with a blocking egress policy plus an allowed-endpoints allowlist. Both are documented inline in the generated file.

> **Why not `psalm/psalm-github-actions` or `psalm/psalm-github-security-scan`?**
> Those official Docker actions bundle their own Psalm binary (Psalm 5.x) and support neither Psalm 7 nor Composer-installed plugins. psalm-plugin-laravel requires Psalm 7, so the generated workflow installs PHP with `shivammathur/setup-php` and runs the Composer-installed Psalm instead.

## Customizing

The generated file carries inline comments for each knob. The common edits:

* **PHP version.** Pin `php-version` in the `setup-php` step to match your project (8.2+ is supported by the plugin).
* **Default-branch baseline.** Add your release branches under `push:` so Code Scanning builds the baseline it diffs PRs against.
* **Egress allowlist.** Extend `allowed-endpoints` if your build reaches other hosts (private Composer registry, VCS or path repos, extra Psalm plugins). A blocked call shows in the step log. Switch to `egress-policy: audit` to discover endpoints without failing the build.

**Private repositories need GitHub Advanced Security.** Code Scanning (the SARIF upload) is free for public repos but requires GHAS for private ones. Without it the upload step fails with `Code Security must be enabled for this repository`. If you do not have GHAS, drop the upload-sarif step and the `security-events: write` permission. The inline annotations still work for free.

### Performance

Psalm defaults to a single thread in CI (it detects the `CI` variable). Standard `ubuntu-latest` runners have 4 cores, so add `--threads=4` to the Psalm step on larger codebases. Persisting `~/.cache/psalm` between runs (with `git-restore-mtime-action`, since `git checkout` resets file mtimes) and installing the `igbinary` extension further speed up repeated runs.

## Setting up a baseline

On existing projects Psalm reports many pre-existing issues. A [baseline](https://psalm.dev/docs/running_psalm/dealing_with_code_issues/#using-a-baseline-file) suppresses them so CI fails only on new issues.

```bash
# Type + taint baseline (Psalm 7 runs both by default)
./vendor/bin/psalm --set-baseline=psalm-baseline.xml
```

Commit `psalm-baseline.xml`. The command adds the `errorBaseline` attribute to your `psalm.xml` automatically.

## Troubleshooting

**Psalm runs out of memory.** Raise the PHP memory limit on the Psalm step:

```yaml
      - name: Run Psalm
        run: php -d memory_limit=4G ./vendor/bin/psalm --report=psalm.sarif --report-show-info=false
```

**Plugin cannot find the Laravel app.** Ensure `psalm.xml` registers the plugin and `composer.json` requires `laravel/framework`. The plugin boots a minimal Laravel app during analysis, so it needs a working Composer autoloader.
