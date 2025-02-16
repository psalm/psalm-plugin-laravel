# Application tests

Idea of application test: create an [almost] empty Laravel app and run Psalm over its codebase.

## FAQ

 - Q1: How to update the baseline file for the Laravel Application test?
 - A1: Run `laravel-test.sh` script with the `-u` (or `--update`) flag, e.g., `./laravel-test.sh -u`.
____
