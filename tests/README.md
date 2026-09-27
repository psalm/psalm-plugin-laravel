# Tests

There are 3 types of tests:
1. **Type** (the main one): uses .phpt files to run Psalm over code snippets in the context of a fake Laravel app [using orchestra/testbench]
2. **Application**: creates an empty Laravel app, adds some typical classes of different types and run Psalm over its codebase.
3. **Unit**: uses PHPUnit to test internal logic. Most cases run in process, but 8 classes fork a real `vendor/bin/psalm` over a fixture project, for regressions that only reproduce under whole-program analysis. Each of those runs uses a command or a fixture directory no other run repeats, so there is no shared report to memoize.

[audit-2026-09.md](audit-2026-09.md) inventories all three suites with measured wall-clock, records where the runtime actually goes, and lists the traps (taint phpt batch interference, version-gate pairs, cross-suite fixture coupling, style tooling reaching into the subprocess fixtures) that a later reorganisation has to respect.
