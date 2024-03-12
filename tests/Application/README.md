# Application tests

Idea of application test: create an [almost] empty Laravel app and run Psalm over its codebase.

## FAQ

 - Q1: How to update baselines
 - A1: Inside .sh files change `--use-baseline` to `--set-baseline`, run sh files and revert changes in .sh files.
____
