# Tests

There are 3 types of tests:
1. **Type** (the main one): uses .phpt files to run Psalm over code snippets in the context of a fake Laravel app [using orchestra/testbench]
2. **Application**: creates an empty Laravel app, adds some typical classes of different types and run Psalm over its codebase.
3. **Unit**: uses PHPUnit to test some internal logic without running Psalm.
