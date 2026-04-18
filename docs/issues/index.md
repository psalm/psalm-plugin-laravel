---
title: Custom Issues
nav_order: 4
has_children: true
---

# Custom Issues

The plugin ships advanced Laravel-aware static analysis checks that extend Psalm's built-in diagnostics:

- [NoEnvOutsideConfig](NoEnvOutsideConfig.md) — `env()` called outside `config/` directory
- [InvalidConsoleArgumentName](InvalidConsoleArgumentName.md) — `argument()` references undefined console command argument
- [InvalidConsoleOptionName](InvalidConsoleOptionName.md) — `option()` references undefined console command option
- [MissingView](MissingView.md) — `view()` references a non-existent Blade template (opt-in)
- [MissingTranslation](MissingTranslation.md) — `__()` or `trans()` references an undefined translation key (opt-in)
- [ModelMakeDiscouraged](ModelMakeDiscouraged.md) — `Model::make()` used instead of `new Model()`

Each issue page explains what it detects, why it matters, and how to fix it.
