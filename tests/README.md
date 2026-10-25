# Tests

There are 3 types of tests:
1. **Type** (the main one): uses .phpt files to run Psalm over code snippets in the context of a fake Laravel app [using orchestra/testbench]
2. **Application**: creates an empty Laravel app, adds some typical classes of different types and run Psalm over its codebase.
3. **Unit**: uses PHPUnit to test internal logic. Most cases run in process, but some classes fork a real `vendor/bin/psalm` over a fixture project, for regressions that only reproduce under whole-program analysis. A Psalm boot costs several seconds whatever the fixture's size, so a forking class runs Psalm once (or twice for a warm-cache case) and every assertion reads that shared run, and unrelated single-purpose regressions share one fixture app (`Fixtures/BladeTemplates`, one template per behavior) rather than each owning a fixture. Prefer adding a template to an existing fixture over a new forking class. The Blade classes get that memoized run through `tests/Unit/Blade/AnalysesFixtureApp`. Each forking class is tagged `#[Group('subprocess')]`.

`composer test:unit` runs everything (via `paratest --processes=auto`); `composer test:unit:fast` excludes the subprocess group for a local loop that finishes in seconds.

## What a unit test is for

Keep a unit test only when it guards a behavior that no phpt type test and no other unit test already covers. Keep one case per distinct branch of the code under test, not one per literal: a data provider with many rows that all take the same path should shrink to the rows that differ. Do not add:

- tests that read `src/`, `docs/` or stubs as text to check their shape (wiring, source order, doc paths, catalog completeness);
- tests that compare a call with itself, echo a mock's return value, or pin an incidental default or getter;
- a unit test for handler behavior that a phpt in `tests/Type/tests` already asserts.

A text check is acceptable only when it guards something a user would otherwise hit and nothing else catches (for example `IssueDocumentationTest`, because every issue links to its docs page).

## Fixture ownership (unit suite, subprocess and otherwise)

One test class owns each fixture directory under `Fixtures/`, and only that class runs Psalm in it. Every Psalm run writes per-directory state even with `--no-cache`: the plugin regenerates its stub cache under `sys_get_temp_dir()/psalm-laravel-<md5(cwd)>` on each run. So a test that mutates a fixture (writes files, relies on Psalm's cache), or analyses a fixture owned by another class, must copy it to a PID-suffixed directory first and remove the copy in a `finally` block. Use `Concerns\CopiesFixtureDirectories` (`IndirectMethodReferencesTest` copies to `.incremental-<pid>`). Keep the copy outside the fixture's `<projectFiles>`. Concurrent runs (paratest workers) rely on this. Tests that read the same Psalm report from one fixture share a single memoized run per class (`WithPivotSchemaColumnsEmissionTest`, `ContainerNarrowedMethodResolutionTest`) rather than forking Psalm per test method.

## Opcache file cache for forked Psalm runs

`tests/bootstrap.php` (the PHPUnit and paratest bootstrap of the unit suite) points every `vendor/bin/psalm` child at one `opcache.file_cache` directory, so a fresh child loads precompiled Psalm, vendor and plugin scripts instead of recompiling them (a single boot gets about 8% faster). The directory is `psalm-laravel-opcache-<pid>` under the system temp dir, created fresh per run (paratest workers share their main process's) and removed on exit. It must never outlive a run: Psalm's restarter forces `opcache.validate_timestamps=0`, so a reused cache would serve plugin code from before your last edit. Set `PSALM_LARAVEL_TEST_NO_OPCACHE_FILE_CACHE=1` to turn it off; it is also off without `ext-posix`. `composer test:type` keeps the plain `vendor/autoload.php` bootstrap: it boots Psalm only a handful of times, too few to repay the cache writes.
