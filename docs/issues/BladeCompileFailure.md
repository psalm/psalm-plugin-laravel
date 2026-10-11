---
title: BladeCompileFailure
parent: Custom Issues
nav_order: 13
---

# BladeCompileFailure

Emitted on line 1 of a Blade template that the Blade compiler threw on, so the template is not analyzed. The message names the compiler's exception class and message. Needs [Blade template analysis](../blade.md) enabled. See [Reporting level](#reporting-level).

## Why it matters

A template that does not compile is skipped, and every finding it would have produced disappears with it. A template that used to report issues and now fails to compile looks like an improvement. Reported as an issue, the skip survives `--no-progress` and shows up in every `--output-format`. At the default `info` level Psalm only prints it with `--show-info=true`; once it is an `error` (see [Reporting level](#reporting-level)) it also fails the run and is recorded by `--set-baseline`.

## Example

```text
INFO: BladeCompileFailure - resources/views/dashboard.blade.php:1:1 - Blade template could not be compiled and is not analyzed: LogicException: the feature directive needs a request
```

## Common causes

* A directive, condition, or precompiler callback that throws when called outside a request.
* A component tag (`<x-...>`) whose class or view cannot be resolved by the booted application (see [Known limits](../blade.md#known-limits)).

## How to fix

Fix the template, or make the failing directive or component resolvable in the booted application. When the failure is expected (the directive genuinely needs a request), suppress the issue for that template:

```xml
<issueHandlers>
    <PluginIssue name="BladeCompileFailure">
        <errorLevel type="suppress">
            <file name="resources/views/dashboard.blade.php" />
        </errorLevel>
    </PluginIssue>
</issueHandlers>
```

A `{{-- @psalm-suppress --}}` comment in the template cannot silence it: that comment only takes effect in the compiled template, and this template never compiled.

## Reporting level

Without an explicit issue handler, it is reported as `info` by default: hidden unless Psalm runs with `--show-info=true`, never failing the run, and left out of a baseline. Promote that default to an error in `psalm.xml`:

```xml
<pluginClass class="Psalm\LaravelPlugin\Plugin">
    <blade />
    <experimental value="true" />
</pluginClass>
```

Any explicit `<PluginIssue>` entry owns this issue's complete reporting policy, regardless of `<experimental>`.

## Limitations

* Not reported when Blade analysis turned itself off for the run (see [Degradation](../blade.md#degradation)). The run-level warning covers every template instead.
* A template that cannot be read, or whose compiled shadow cannot be written, is not a compile failure. It stays in the run-level warning.
