---
title: Home
nav_order: 1
---

# About plugin

The plugin helps Psalm to understand Laravel's code (which uses a lot of magic) better.
There are 2 main ways how it does it:

- **easy**: by providing stub files (you can find them in `/stubs` dir)
- **medium+**: using custom Handlers (see `/src/Handlers` dir)

## Documentation

- [Configuration](config.md) — plugin XML config options and environment variables
- [GitHub Actions](github-actions.md) — running Psalm in CI with GitHub Actions
- [Custom Issues](issues/index.md) — Laravel-aware checks the plugin adds on top of Psalm's built-ins
- [Security (Taint) Checks](security.md) — what the security analysis detects
- [Upgrading to v4](upgrade-v4.md) — migration guide from v3
- Contribution:
    - [Overview](contributing/README.md) — how the plugin works, getting started, adding stubs and handlers
    - [Architecture Decisions](contributing/decisions.md) — key design decisions and rationale
    - [Debugging with Xdebug](contributing/xdebug.md) — stepping through plugin code
    - [Laravel Magic Call Patterns](contributing/laravel-magic-call-patterns.md) — how `__call`/`__callStatic` chains resolve, and how Psalm sees them
    - [Taint Analysis](contributing/taint-analysis.md) — authoring taint stubs
    - [Types](contributing/types.md) — Psalm types and annotations (incl. internal/hidden)
