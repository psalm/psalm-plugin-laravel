# Tests

There are 3 types of tests:
1. **Type** (the main one): uses .phpt files to run Psalm over code snippets in the context of a fake Laravel app [using orchestra/testbench]
2. **Application**: creates an empty Laravel app, adds some typical classes of different types and run Psalm over its codebase.
3. **Unit**: uses PHPUnit to test internal logic. Most cases run in process, but 8 classes fork a real `vendor/bin/psalm` over a fixture project, for regressions that only reproduce under whole-program analysis. Those 13 runs cover 12 distinct (command, directory) pairs, and the one repeated pair is an incremental-cache sequence whose second run must stay real, so there is no shared report to memoize.
