---
title: Pest
nav_order: 8
---

# Pest

Pest runs each `test()`, `it()`, `beforeEach()` and `afterEach()` closure bound to a TestCase, so `$this->user` or `$this->actingAs()` inside a test is valid code. Pest only documents that binding as `@param-closure-this TestCall`, which Psalm 6.19+ reads literally, reporting every `$this->...` in a test as undefined on `TestCall`.

The plugin replaces that binding with the class Pest actually uses:

- the class passed to `uses(...)` or `pest()->extend(...)` in the test file itself;
- otherwise, the class `tests/Pest.php` assigns to the file's directory through `uses(...)->in(...)` or `pest()->extend(...)->in(...)` (`in()` accepts string literals, `__DIR__` and `__DIR__ . '/Feature'`). The other files Pest loads at boot (`tests/Helpers.php`, `tests/Expectations.php`, and the `tests/Helpers/` and `tests/Expectations/` trees) count too;
- otherwise `PHPUnit\Framework\TestCase`, Pest's default, for files inside `tests/`.

`tests/Pest.php` is read relative to the directory of your Psalm config and is never executed. When it is missing, when the test file lives outside `tests/` (a monorepo package with its own suite), or when a boot file holds a call the plugin cannot read statically (a variable argument, a call inside a condition), the plugin keeps Pest's own `TestCall` binding for tests it cannot resolve, rather than guess.

The plugin also stops reporting `InternalMethod` on Pest's public API (`expect()->toBe()`, `uses()->in()`, `test()->group()`): Pest marks those classes `@internal`, but they are how tests are written.
