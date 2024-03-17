# Tests

There are 3 types of tests:
1. Type (main one): Use phpt files to run Psalm over code snippets in the context of fake Laravel app [using orchestra/testbench]
2. Application: create an [almost] empty Laravel app and run Psalm over its codebase.
3. Unit: use PHPUnit to test some internal logic without running Psalm.
