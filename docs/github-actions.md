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

This writes `.github/workflows/psalm.yml`, copied verbatim from the plugin's bundled template ([view on GitHub](https://github.com/psalm/psalm-plugin-laravel/blob/3.x/resources/ci/github-actions/psalm.yml)). Pass `--force` to overwrite an existing file.

## What it generates

Two jobs that run in parallel:

* **Type analysis.** A plain `./vendor/bin/psalm` run. Psalm auto-selects the GitHub annotation format on stdout when it detects Actions, so findings appear on the PR's Files changed tab. With no report written, Psalm exits non-zero on findings and fails the job directly.
* **Taint analysis.** A `./vendor/bin/psalm --taint-analysis` run that writes SARIF. When Psalm writes a report during taint analysis inside Actions it exits 0 even on findings, so a final step reads the SARIF and fails CI from it. Results also upload to the Security tab (Code Scanning) via `github/codeql-action/upload-sarif`, skipped on fork and Dependabot pull requests where GitHub caps the `GITHUB_TOKEN` at read-only and the upload would fail — those PRs are still gated by the annotations and the failing step.

Two jobs are required on Psalm 6.x because `--taint-analysis` switches Psalm to a taint-only mode that skips type analysis, so a single run cannot cover both. (Psalm 7 enables taint by default and the `master` branch ships a single-job template instead.)

Both jobs pin every action to a commit SHA and harden the runner with a blocking egress policy plus an allowed-endpoints allowlist. Checkout runs with `persist-credentials: false` so the `GITHUB_TOKEN` is not left in the git config for later steps to reuse (nothing here runs a git operation after checkout, and the SARIF upload uses the Actions token, not git credentials). Only the taint job needs `security-events: write` (for the SARIF upload); the type job runs with read-only permissions. Everything is documented inline in the generated file.

## Customizing

The generated file carries inline comments for each knob. The common edits:

* **PHP version.** Pin `php-version` in the `setup-php` step to match your project (8.2+ is supported by the plugin).
* **Default-branch baseline.** Add your release branches under `push:` so Code Scanning builds the baseline it diffs PRs against.
* **Egress allowlist.** Extend `allowed-endpoints` if your build reaches other hosts (private Composer registry, VCS or path repos, extra Psalm plugins). A blocked call shows in the step log. Switch to `egress-policy: audit` to discover endpoints without failing the build.

**Private repositories need [GitHub Advanced Security](https://docs.github.com/en/get-started/learning-about-github/about-github-advanced-security).** Code Scanning (the SARIF upload) is free for public repos but requires GHAS for private ones. Without it the taint job's upload step fails with `Code Security must be enabled for this repository`. If you do not have GHAS, drop the upload and gate steps from the taint job (or the whole job), keeping the type job and its inline annotations, which work for free.

### Performance

Psalm defaults to a single thread in CI (it detects the `CI` variable). Standard `ubuntu-latest` runners have 4 cores, so add `--threads=4` to the Psalm step on larger codebases. Persisting `~/.cache/psalm` between runs (with `git-restore-mtime-action`, since `git checkout` resets file mtimes) and installing the `igbinary` extension further speed up repeated runs.

## Troubleshooting

**Psalm runs out of memory.** Raise the PHP memory limit on the affected Psalm step:

```yaml
      - name: Run Psalm (type analysis)
        run: php -d memory_limit=4G ./vendor/bin/psalm
```

**Plugin cannot find the Laravel app.** Ensure `psalm.xml` registers the plugin and `composer.json` requires `laravel/framework`. The plugin boots a minimal Laravel app during analysis, so it needs a working Composer autoloader.
