---
title: Blade Template Analysis
nav_order: 8
---

# Blade Template Analysis

Opt-in static analysis of `*.blade.php` templates. Enable it, and Psalm reports type issues, and with `--taint-analysis`, security findings, at the exact template file and line, not just in your PHP.

## What is analyzed

* Every `*.blade.php` file under the booted application's view paths (`config('view.paths')` plus whatever service providers added).
* Each template is compiled through the application's own Blade compiler into a standalone PHP file (a "shadow"), and the shadow is what Psalm actually scans. The template itself is never handed to Psalm as PHP.
* Each template is analyzed on its own. `@include`, `@extends`, and component tags are not followed into the files they reference.
* Issues found in the shadow are relocated onto the `.blade.php` path and the matching template line before they are reported. Nothing in the output points at the compiled shadow.

## Enabling it

Add a `<blade>` element as a child of `<pluginClass>` in your `psalm.xml`:

```xml
<plugins>
    <pluginClass class="Psalm\LaravelPlugin\Plugin">
        <blade enabled="true" />
    </pluginClass>
</plugins>
```

**default**: disabled. Omit the element, or write `<blade enabled="false" />`.

### `cacheDir`

Compiled shadows and their manifest are written to a cache directory:

```xml
<blade enabled="true" cacheDir="build/blade-shadows" />
```

**default**: a subdirectory inside the [plugin's cache directory](config.md#cache-directory), which itself lives inside Psalm's own cache directory, outside your project tree. Nothing needs to be gitignored with the default.

If you set `cacheDir` to a path inside your project, gitignore it and keep it out of `<projectFiles>`. A shadow that Psalm treats as one of your own project files gets analyzed twice, at the wrong location, and can make taint flows starting inside it disappear.

## Suppressing issues

Two suppression paths work exactly as they do for ordinary PHP files:

* An inline comment directly above the line, inside the template:

  ```blade
  {{-- @psalm-suppress UndefinedPropertyFetch --}}
  <p>{{ $user->nmae }}</p>
  ```

* An `<issueHandlers>` entry scoped to the view directory:

  ```xml
  <issueHandlers>
      <UndefinedPropertyFetch>
          <errorLevel type="suppress">
              <directory name="resources/views" />
          </errorLevel>
      </UndefinedPropertyFetch>
  </issueHandlers>
  ```

## Ambient variables

These template variables are typed automatically, without any annotation:

| Variable       | Type                                                                                                                     |
|----------------|----------------------------------------------------------------------------------------------------------------------------|
| `$__env`       | `Illuminate\View\Factory`                                                                                                |
| `$errors`      | `Illuminate\Support\ViewErrorBag`                                                                                        |
| `$attributes`  | `Illuminate\View\ComponentAttributeBag`                                                                                  |
| `$slot`        | `Illuminate\View\ComponentSlot`                                                                                          |
| `$component`   | `Illuminate\View\Component`                                                                                              |
| `$loop`        | `object{index: int, iteration: int, remaining: int\|null, count: int\|null, first: bool, last: bool\|null, odd: bool, even: bool, depth: int, parent: object\|null}` |

Every other variable a template uses without a type the plugin can prove gets `mixed`, silently. No `UndefinedGlobalVariable` is raised for it, and no error tells you the variable went untyped by default (see [`reportMixedIssues`](config.md#reportmixedissues)), so a typo in a variable name will not be caught this way.

## What gets reported

* **Type analysis** (a plain `psalm` run): the same issue types Psalm reports anywhere else, at the template's file and line — except Psalm's `MixedIssue` family (`MixedArgument`, `MixedAssignment`, and the rest), which is suppressed by default because an undeclared template variable typing as `mixed` produces it constantly. Opt back in with [`reportMixedIssues`](config.md#reportmixedissues).
* **Taint analysis** (`psalm --taint-analysis`): `TaintedHtml` on unescaped `{!! !!}` output that traces back to request input, with the whole trace shown against template lines, never against the compiled shadow. Escaped `{{ }}` output of the same tainted value is not flagged.

```blade
{{ request()->input('q') }}   {{-- escaped: not flagged --}}
{!! request()->input('q') !!} {{-- unescaped: TaintedHtml --}}
```

### Two runs on Psalm 6

Psalm 6 runs taint analysis exclusively: a plain run reports type issues only, and `--taint-analysis` reports taint issues only. Blade templates follow the same split, so covering both needs two runs, same as the rest of the plugin:

```bash
./vendor/bin/psalm                  # type issues, including Blade templates
./vendor/bin/psalm --taint-analysis # taint issues, including Blade templates
```

## Degradation

Blade analysis never fails a run. If the compiler or view finder cannot be resolved from the booted application, the cache directory cannot be written, or a Psalm internal the plugin depends on has changed shape, the feature turns itself off for that run and prints one warning naming the cause. Psalm's `--no-progress` installs a progress implementation that discards warnings, so a degradation is invisible under that flag.

A template that fails to compile (see [Known limits](#known-limits)) is skipped rather than aborting the run. Up to three failing template paths are named directly in the warning; beyond that, run with `--debug` for every individual cause.

## Unused templates

With [`reportUnusedViews`](config.md#reportunusedviews) enabled, the plugin also reports a template that no statically-provable reference ever names ([UnusedView](issues/UnusedView.md)). References are gathered from two places: the `view()` helper and `View::make()` in plain project files, and `@include` / `@extends` inside every compiled template. One reference this plugin cannot resolve statically — a dynamic `view($name)` or `@include($name)` anywhere in the project — turns the check off for the whole run.

## Unused view data

With [`reportUnusedViewData`](config.md#reportunusedviewdata) enabled, the plugin reports a data key a `view()` call site passes that the rendered template neither reads nor declares ([UnusedViewData](issues/UnusedViewData.md)). What each template reads (from its compiled output) and what it declares (`{{-- @var --}}`, `@props`) are taken together, then closed over its `@include` / `@extends` chain, since those directives inherit the including template's whole scope; `@includeIsolated` and `@each` do not, so they never launder a key into it.

The check declines for a call site instead of guessing, and the gate that matters most in practice is that a template whose compiled body hides which names it reads is never checked. `@props` and `@aware` both compile to `$$name` writes, which means component templates are outside this release's reach. The issue page lists the rest.

## Known limits

* **No cross-template following for analysis.** Each template is compiled and analyzed as if it stood alone; `@include`, `@extends`, and component recursion are resolved only for the reference and read-set graphs the two opt-in rules above use, never to type a template's body.
* **Component tags can fail to compile.** A component tag (`<x-...>`) needs the full application container to resolve, which the standalone compile pass does not always provide. A template that fails this way is skipped and reported once in the degradation warning; other templates are unaffected.
* **Undeclared variables are silently `mixed`.** There is no way, yet, to catch a typo'd variable name this way, and the `Mixed*` issues that fallback produces are suppressed by default (see [`reportMixedIssues`](config.md#reportmixedissues)), so they cannot be used to spot one either unless you opt back in.
* **No HTML context awareness.** The plugin does not distinguish an attribute position from a script position from ordinary markup; taint detection is about escaped versus unescaped output, not where in the HTML that output lands.
* **Dynamic view names are not resolved.** `view($name)` with a non-literal `$name` is not connected back to a template file.
* **Contract annotations do not type the template body.** `{{-- @var \App\Models\User $user --}}` and `@props([...])` are read, but only to check the `view()` call sites that render the template (see [`validateViewData`](config.md#validateviewdata)). Inside the compiled template every variable they would type still falls back to `mixed`, because typing the body would change every shadow's content and its cache fingerprint — and the resulting `Mixed*` issues are suppressed by default regardless (see [`reportMixedIssues`](config.md#reportmixedissues)).
* **`Mixed*` suppression can orphan a template's own `@psalm-suppress`.** A `{{-- @psalm-suppress MixedArgument --}}` comment in a template is carried into the compiled shadow, but with `reportMixedIssues` at its default (off) the issue it would have silenced is already dropped before that comment is ever checked against it. Under `--find-unused-psalm-suppress` this reports `UnusedPsalmSuppress` on the template line. Either drop the now-redundant comment, or run with `reportMixedIssues="true"` when checking for unused suppressions.
