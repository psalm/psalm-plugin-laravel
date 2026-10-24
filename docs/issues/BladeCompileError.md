---
title: BladeCompileError
parent: Custom Issues
nav_order: 13
---

# BladeCompileError

Emitted on line 1 of a Blade template that the Blade compiler (or the plugin's own declaration parser) threw on. The template is skipped, and the message reads `Template not analyzed: <cause>`, where the cause is the compiler's own message on one line. Needs [Blade template analysis](../blade.md) enabled.

## Why it matters

A template that does not compile is skipped, and every finding it would have produced disappears with it. A template that used to report issues and now fails to compile would otherwise look like an improvement. The issue is an `error`: it fails the run, survives `--no-progress`, shows up in every `--output-format`, and is recorded by `--set-baseline`.

## Example

```blade
{{-- resources/views/help.blade.php --}}
<p>Wrap the card in a @component block.</p>
```

```text
ERROR: BladeCompileError - resources/views/help.blade.php:1:1 - Template not analyzed: @component needs arguments; write @@component to print it as text
```

## Common causes

* Prose that Blade reads as a directive (`@component`, `@section`) without arguments. Escape it as `@@component`.
* A malformed directive, such as `@foreach` without `as`.
* A directive, condition, or precompiler callback that throws when called outside a request.
* A component tag (`<x-...>`) whose class or view cannot be resolved by the booted application (see [Known limits](../blade.md#known-limits)).
* The plugin's own declaration parser crashed on the template. This is a plugin bug: report it with the template.

The line is always 1: the compiler reports no template line.

## How to fix

Fix the template, or make the failing directive or component resolvable in the booted application. When the failure is expected (the directive genuinely needs a request), suppress the issue for that template:

```xml
<issueHandlers>
    <PluginIssue name="BladeCompileError">
        <errorLevel type="suppress">
            <file name="resources/views/dashboard.blade.php" />
        </errorLevel>
    </PluginIssue>
</issueHandlers>
```

A `{{-- @psalm-suppress --}}` comment in the template cannot silence it: that comment only takes effect in the compiled template, and this template never compiled.

## Limitations

* Not reported when Blade analysis turned itself off for the run (see [Degradation](../blade.md#degradation)). The run-level warning covers every template instead.
* A template that cannot be read, or whose compiled shadow cannot be written, is not a compile failure. It stays in the run-level warning.
