# Tests

There are 3 types of tests:
1. **Type** (the main one): uses .phpt files to run Psalm over code snippets in the context of a fake Laravel app [using orchestra/testbench]
2. **Application**: creates an empty Laravel app, adds some typical classes of different types and run Psalm over its codebase.
3. **Unit**: uses PHPUnit to test internal logic. Most cases run in process, but some classes fork a real `vendor/bin/psalm` over a fixture project, for regressions that only reproduce under whole-program analysis. A Psalm boot costs several seconds whatever the fixture's size, so a forking class runs Psalm once (or twice for a warm-cache case) and every assertion reads that shared run, and unrelated single-purpose regressions share one fixture app (`Fixtures/BladeTemplates`, one template per behavior) rather than each owning a fixture. Prefer adding a template to an existing fixture over a new forking class. The Blade classes get that memoized run through `tests/Unit/Blade/AnalysesFixtureApp`. Each forking class is tagged `#[Group('subprocess')]`.

`composer test:unit` runs everything (via `paratest --processes=auto`); `composer test:unit:fast` excludes the subprocess group for a local loop that finishes in seconds.

Rules for the shared runs, their cost, and where they execute:

- **Positive control.** Every shared (memoized) Psalm run asserts at least one known finding before any test asserts silence, and each test filters findings by its own file or scenario directory. A silence assertion on a shared run passes vacuously if analysis never reached the code. `BladeTemplatesTest` asserts a `PossiblyInvalidArgument` from `php-e.blade.php` in its shared run, and `ContainerNarrowedMethodResolutionTest` filters by its entry file.
- **Scheduling and budget.** Paratest schedules per class, so the slowest class bounds the wall time of `composer test:unit`. Keep a forking class at no more than 2 Psalm runs and roughly 15 seconds. One Psalm boot costs about 5 to 6 seconds of CPU whatever the fixture's size, so split work across classes only when a class would exceed that budget.
- **CI coverage.** The `subprocess` group runs only on the PHP 8.2 with Laravel 12.27.0 and PHP 8.5 lockfile unit cells; the `in_process` cells in `.github/workflows/tests.yml` skip it. Behavior that depends on the Laravel or PHP version must be covered by a phpt or an in-process test, otherwise it is checked on those two cells only.

## What a unit test is for

Keep a unit test only when it guards a behavior that no phpt type test and no other unit test already covers. Keep one case per distinct branch of the code under test, not one per literal: a data provider with many rows that all take the same path should shrink to the rows that differ. Do not add:

- tests that read `src/`, `docs/` or stubs as text to check their shape (wiring, source order, doc paths, catalog completeness);
- tests that compare a call with itself, echo a mock's return value, or pin an incidental default or getter;
- a unit test for handler behavior that a phpt in `tests/Type/tests` already asserts.

A text check is acceptable only when it guards something a user would otherwise hit and nothing else catches (for example `IssueDocumentationTest`, because every issue links to its docs page).

## Fixture ownership (unit suite, subprocess and otherwise)

One test class owns each fixture directory under `Fixtures/`, and only that class runs Psalm in it. A Psalm run keeps per-project state: Psalm's own cache directory, and the plugin's cache (`aliases.phpstub` and the migration schema cache) under it. With `--no-cache` the plugin falls back to `sys_get_temp_dir()/psalm-laravel-<md5(cwd)>`. The plugin rewrites these files only when their content changes, so reading is safe, but a run that changes the fixture's files or migrations can invalidate another run's cache or report. So a test that mutates a fixture (writes files, relies on Psalm's cache), or analyses a fixture owned by another class, must copy it to a PID-suffixed directory first and remove the copy in a `finally` block. Use `Concerns\CopiesFixtureDirectories` (`IndirectMethodReferencesTest` copies to `.incremental-<pid>`). Keep the copy outside the fixture's `<projectFiles>`. Concurrent runs (paratest workers) rely on this. Tests that read the same Psalm report from one fixture share a single memoized run per class (`WithPivotSchemaColumnsEmissionTest`, `ContainerNarrowedMethodResolutionTest`) rather than forking Psalm per test method.
