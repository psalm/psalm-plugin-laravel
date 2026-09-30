# Tests

There are 3 types of tests:
1. **Type** (the main one): uses .phpt files to run Psalm over code snippets in the context of a fake Laravel app [using orchestra/testbench]
2. **Application**: creates an empty Laravel app, adds some typical classes of different types and run Psalm over its codebase.
3. **Unit**: uses PHPUnit to test internal logic. Most cases run in process, but some classes fork a real `vendor/bin/psalm` over a fixture project, for regressions that only reproduce under whole-program analysis. The Blade classes share one run per distinct command through `tests/Unit/Blade/AnalysesFixtureApp`. Each forking class is tagged `#[Group('subprocess')]`.

`composer test:unit` runs everything (via `paratest --processes=auto`); `composer test:unit:fast` excludes the subprocess group for a local loop of about one second.

[audit-2026-09.md](audit-2026-09.md) inventories all three suites with measured wall-clock, records where the runtime actually goes, and lists the traps (taint phpt batch interference, version-gate pairs, fixture-shape assumptions in the Rector and php-cs-fixer skip globs) that a later reorganisation has to respect.

## Fixture ownership (unit suite, subprocess and otherwise)

One test class owns each fixture directory under `Fixtures/`, and only that class runs Psalm in it. Every Psalm run writes per-directory state even with `--no-cache`: the plugin regenerates its stub cache under `sys_get_temp_dir()/psalm-laravel-<md5(cwd)>` on each run. So a test that mutates a fixture (writes files, relies on Psalm's cache), or analyses a fixture owned by another class, must copy it to a PID-suffixed directory first and remove the copy in a `finally` block. Use `Concerns\CopiesFixtureDirectories` (`IndirectMethodReferencesTest` copies to `.incremental-<pid>`, `WarmUpFailureVisibilityTest` copies `Fixtures/UnknownModelAttribute` to `.warm-up-<pid>`). Keep the copy outside the fixture's `<projectFiles>`. Concurrent runs (paratest workers) rely on this.
