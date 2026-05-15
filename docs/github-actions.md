---
title: GitHub Actions
nav_order: 3
---

# Running Psalm on GitHub Actions

Production-ready GitHub Actions workflows for Laravel projects using psalm-plugin-laravel.

> **Fastest path.** Run `./vendor/bin/psalm-laravel add github` to have the plugin write a ready-to-commit workflow for you.
> The generated workflow covers the same setup described below (PHP via `shivammathur/setup-php`, Composer cache, plugin-aware Psalm invocation), so the content of this page is primarily reference for customizing the generated workflow or hand-rolling your own.

> **About psalm/psalm-github-actions and psalm/psalm-github-security-scan:**
> These official Psalm Docker actions bundle their own Psalm binary (Psalm 5.x) and do not support Psalm 7 or Composer-installed plugins.
> For projects using psalm-plugin-laravel (which requires Psalm 7), use the `shivammathur/setup-php` approach shown below instead.


## Minimal workflow

Create `.github/workflows/psalm.yml`:

```yaml
name: Psalm

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  psalm:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v6

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Run Psalm
        run: ./vendor/bin/psalm --output-format=github --threads=4
```

`--output-format=github` annotates your pull requests inline with Psalm issues.

`--threads=4` is important: Psalm [defaults to 1 thread in CI environments](https://github.com/vimeo/psalm/issues/11761) (it detects the `CI` environment variable that GitHub Actions sets). Standard GitHub-hosted runners (`ubuntu-latest`) have 4 cores.


## Recommended workflow

Adds Psalm cache, igbinary, path filters, timeout, and SARIF upload to GitHub Security tab:

```yaml
name: Psalm

on:
  push:
    branches: [main]
    paths:
      - '**.php'
      - 'psalm*.xml'
      - 'composer.*'
      - '.github/workflows/psalm.yml'
  pull_request:
    types: [opened, synchronize, reopened, ready_for_review]
    paths:
      - '**.php'
      - 'psalm*.xml'
      - 'composer.*'
      - '.github/workflows/psalm.yml'

jobs:
  psalm:
    name: Psalm
    runs-on: ubuntu-latest
    timeout-minutes: 10
    permissions:
      actions: read
      contents: read
      security-events: write
    steps:
      - uses: actions/checkout@v6

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none
          extensions: igbinary

      - name: Install dependencies
        uses: ramsey/composer-install@v4

      # Psalm cache relies on file mtimes, but git checkout sets all mtimes to "now".
      # Without restoring mtimes, cached results are always invalidated.
      - name: Restore file modification times
        uses: chetan/git-restore-mtime-action@v2

      - name: Cache Psalm data
        uses: actions/cache@v5
        with:
          path: ~/.cache/psalm
          key: psalm-${{ hashFiles('psalm.xml', 'psalm-baseline.xml', 'composer.lock') }}

      - name: Run Psalm
        run: ./vendor/bin/psalm --output-format=github --report=results.sarif --threads=4

      - name: Upload Security Analysis to GitHub
        if: always() && hashFiles('results.sarif') != ''
        uses: github/codeql-action/upload-sarif@v4
        with:
          sarif_file: results.sarif
          category: psalm
```


### Key details

**SARIF upload:** The `--report=results.sarif` flag generates a [SARIF](https://docs.github.com/en/code-security/code-scanning/integrating-with-code-scanning/sarif-support-for-code-scanning) report. The `upload-sarif` step sends it to GitHub's Security tab. The `if: always() && hashFiles(...)` condition uploads results even when Psalm exits with errors, but skips if Psalm crashed before generating the file. The `category: psalm` identifies this tool -- required if you also upload SARIF from other tools (e.g., PHPStan, Semgrep).

**SARIF upload requires GitHub Advanced Security for private repos.** Code scanning (including SARIF upload) is free for public repositories but requires [GitHub Advanced Security (GHAS)](https://docs.github.com/en/get-started/learning-about-github/about-github-advanced-security) for private repos -- a paid add-on available on Enterprise and Team plans. Without it, the `upload-sarif` step will fail with `Code Security must be enabled for this repository`. If you don't have GHAS, remove the `upload-sarif` step and the `permissions` block -- the `--output-format=github` flag already provides inline PR annotations for free.

**Permissions:** When you set `permissions` at the job level, all unspecified permissions default to `none`. The SARIF upload needs `security-events: write` to upload results, `contents: read` for `actions/checkout`, and `actions: read` for the CodeQL action to query the workflow run context. Missing any of these causes `Resource not accessible by integration` errors.

**igbinary:** Psalm auto-detects the extension and uses it for faster serialization -- both for cache I/O and inter-thread communication. No `psalm.xml` config needed.

**Psalm cache:** The `git-restore-mtime-action` is essential -- without it, `git checkout` sets all file mtimes to "now", invalidating the entire cache on every run. The cache key includes `psalm.xml`, baseline, and `composer.lock` so it refreshes when config or dependencies change.

**Path filters:** The `paths` filter avoids running Psalm on documentation-only or asset-only changes. The `types` filter on `pull_request` limits which PR events trigger the workflow (e.g., skips `labeled`, `assigned` events).


## Setting up a baseline

On existing projects, Psalm will report many pre-existing issues.
A [baseline](https://psalm.dev/docs/running_psalm/dealing_with_code_issues/#using-a-baseline-file) lets you suppress them and only fail on new issues.

```bash
# Generate the baseline locally
./vendor/bin/psalm --set-baseline=psalm-baseline.xml
```

Commit `psalm-baseline.xml` to the repository. The `--set-baseline` command adds the `errorBaseline` attribute to your `psalm.xml` automatically:

```xml
<psalm errorLevel="2" errorBaseline="psalm-baseline.xml">
    <projectFiles>
        <directory name="app" />
    </projectFiles>

    <plugins>
        <pluginClass class="Psalm\LaravelPlugin\Plugin" />
    </plugins>
</psalm>
```


## Performance tips

| Technique              | Effect                                                                                          |
|------------------------|-------------------------------------------------------------------------------------------------|
| `--threads=4`          | Use all cores for analysis (Psalm defaults to 1 in CI); add `--scan-threads=4` for scanning too |
| Psalm cache + mtime    | Persist `~/.cache/psalm` between runs; requires `git-restore-mtime-action` to work              |
| `igbinary` extension   | Faster serialization for cache and thread IPC (auto-detected by Psalm)                          |
| `paths` filter         | Skip workflow on non-PHP changes                                                                |
| OPcache JIT            | Speeds up Psalm itself (see example below)                                                      |

Example with OPcache JIT enabled:

```yaml
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none
          # For PHP 8.4+, use opcache.jit=true (the string values were deprecated)
          ini-values: opcache.enable_cli=1, opcache.jit=tracing, opcache.jit_buffer_size=256M
```


## Troubleshooting

**Psalm runs out of memory**

Increase the PHP memory limit:

```yaml
      - name: Run Psalm
        run: php -d memory_limit=4G ./vendor/bin/psalm --output-format=github --threads=4
```

**Plugin cannot find Laravel app**

Ensure your `psalm.xml` includes the plugin and your project's `composer.json` requires `laravel/framework`. The plugin boots a minimal Laravel app during analysis -- it needs a working Composer autoloader.
