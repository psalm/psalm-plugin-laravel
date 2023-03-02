# Tests

There are 3 types of tests:
1. Acceptance (main one): Use Codeception to run Psalm over code snippets i a context of fake Laravel app [using orchestra/testbench]
2. Application: create an [almost] empty Laravel (or Lumen) app and run Psalm over its codebase.
3. Unit: use PHPUnit to test some internal logic without running Psalm.
