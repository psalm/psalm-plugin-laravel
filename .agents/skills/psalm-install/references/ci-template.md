# GitHub Actions CI Template for Psalm + psalm-plugin-laravel

Adapt this template to the target project's PHP version and setup.

```yaml
name: Psalm Security Analysis

on:
  workflow_dispatch:
  pull_request:
    types: [opened, synchronize, reopened, ready_for_review]
    paths:
      - '**.php'
      - 'composer*'
      - 'psalm*'
  push:
    branches: [main, master]
    paths:
      - 'composer*'
      - 'psalm*'

permissions:
  contents: read
  security-events: write  # Required for SARIF upload to Code Scanning

jobs:
  psalm:
    name: Psalm Static & Security Analysis
    runs-on: ubuntu-latest
    timeout-minutes: 15

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite3, pdo_sqlite, bcmath
          coverage: none

      - name: Install Composer dependencies
        uses: ramsey/composer-install@v3
        with:
          composer-options: "--no-interaction --prefer-dist --no-scripts"

      - name: Run Psalm
        run: ./vendor/bin/psalm --no-cache --no-progress --no-suggestions --output-format=github

      - name: Run Psalm (SARIF for Code Scanning)
        run: ./vendor/bin/psalm --no-cache --no-progress --no-suggestions --output-format=sarif > psalm-results.sarif
        continue-on-error: true

      - name: Upload SARIF to GitHub Code Scanning
        if: always()
        uses: github/codeql-action/upload-sarif@v3
        with:
          sarif_file: psalm-results.sarif
          category: psalm
        continue-on-error: true
```

## Adaptation Notes

- Match the PHP version to the project's `composer.json` requirement
- `ramsey/composer-install@v3` handles caching automatically (no separate cache step needed)
- The `paths:` filter avoids running Psalm on non-PHP changes (CSS, JS, docs)
- `workflow_dispatch:` allows manual triggering for debugging
- `timeout-minutes: 15` prevents runaway analysis on very large codebases
- `--no-suggestions` keeps output focused on errors
- The first step uses `--output-format=github` for inline PR annotations
- The second step generates SARIF output for GitHub Code Scanning (Security tab),
  with `continue-on-error: true` so it doesn't block the build
- `permissions: security-events: write` is required for the SARIF upload step
- The baseline ensures the type analysis step passes from day one
- Some projects may need `.env` setup or `php artisan key:generate` before Psalm runs.
  Check the project's existing CI for setup steps and replicate them.
- If the project already has a CI workflow, consider adding Psalm as a new job
  in the existing workflow file instead of creating a separate file

## SARIF Benefits

When SARIF upload is enabled, findings appear in the repository's **Security > Code Scanning**
tab. This gives maintainers:
- A dashboard view of all findings with severity levels
- Inline annotations on PRs showing exactly where issues are
- Automatic tracking of which findings are fixed over time
- Integration with GitHub's security overview for organizations

This is especially compelling for the PR — it shows what the tool can do beyond CLI output.