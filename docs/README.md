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
- [Upgrading to v4](upgrade-v4.md) — migration guide from v3
- Contribution:
    - [Overview](contribute/README.md) — how the plugin works, getting started, adding stubs and handlers
    - [Architecture Decisions](contribute/decisions.md) — key design decisions and rationale
    - [Debugging with Xdebug](contribute/xdebug.md) — stepping through plugin code

## Custom Issues

The plugin emits custom issues that Psalm does not have built-in.
Each one links to detailed documentation with examples and fix guidance.

- [NoEnvOutsideConfig](issues/NoEnvOutsideConfig.md)
- [InvalidConsoleArgumentName](issues/InvalidConsoleArgumentName.md)
- [InvalidConsoleOptionName](issues/InvalidConsoleOptionName.md)
