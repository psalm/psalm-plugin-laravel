# Tests

There are 3 types of tests:
1. **Type** (the main one): uses .phpt files to run Psalm over code snippets in the context of a fake Laravel app [using orchestra/testbench]
2. **Application**: creates an empty Laravel app, adds some typical classes of different types and run Psalm over its codebase.
3. **Unit**: uses PHPUnit to test internal logic. Most cases run in process, but 8 classes fork a real `vendor/bin/psalm` over a fixture project, for regressions that only reproduce under whole-program analysis. Those 13 runs cover 12 distinct (command, directory) pairs, and the one repeated pair is an incremental-cache sequence whose second run must stay real, so there is no shared report to memoize. Each is tagged `#[Group('subprocess')]`.

`composer test:unit` runs everything (via `paratest --processes=auto`); `composer test:unit:fast` excludes the subprocess group for a sub-second local loop.

## Fixture ownership (unit suite, subprocess and otherwise)

One test class owns each fixture directory under `Fixtures/`. A test that mutates a fixture (writes files, relies on Psalm's cache) must copy it to a PID-suffixed directory first (`self::FIXTURE . '/.incremental-' . getmypid()`, as `IndirectMethodReferencesTest` does) and remove the copy in a `finally` block. Concurrent runs (paratest, or two classes racing on the same fixture) rely on this: a shared directory is only safe when every test pointed at it is read-only analysis with `--no-cache` (`WarmUpFailureVisibilityTest` and `UnknownModelAttributeEmissionTest` share `Fixtures/UnknownModelAttribute` this way); anything that writes to a fixture needs its own copy.
