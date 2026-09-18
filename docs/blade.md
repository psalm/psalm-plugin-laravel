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
| `$loop`        | `object{index: int, iteration: int, remaining: int|null, count: int|null, first: bool, last: bool|null, odd: bool, even: bool, depth: int, parent: object|null}` |

Every other variable a template uses without a type the plugin can prove gets `mixed`, silently. No `UndefinedGlobalVariable` is raised for it, and no error tells you the variable went untyped, so a typo in a variable name will not be caught this way.

## What gets reported

* **Type analysis** (a plain `psalm` run): the same issue types Psalm reports anywhere else, at the template's file and line.
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

Blade analysis never fails a run. If the compiler or view finder cannot be resolved from the booted application, the cache directory cannot be written, or a Psalm internal the plugin depends on has changed shape, the feature turns itself off for that run and prints one warning naming the cause.

A template that fails to compile (see [Known limits](#known-limits)) is skipped rather than aborting the run. Up to three failing template paths are named directly in the warning; beyond that, run with `--debug` for every individual cause.

## Known limits

* **No cross-template following.** `@include`, `@extends`, and component recursion are not resolved. Each template is compiled and analyzed as if it stood alone.
* **Component tags can fail to compile.** A component tag (`<x-...>`) needs the full application container to resolve, which the standalone compile pass does not always provide. A template that fails this way is skipped and reported once in the degradation warning; other templates are unaffected.
* **Undeclared variables are silently `mixed`.** There is no way, yet, to catch a typo'd variable name this way.
* **No HTML context awareness.** The plugin does not distinguish an attribute position from a script position from ordinary markup; taint detection is about escaped versus unescaped output, not where in the HTML that output lands.
* **Dynamic view names are not resolved.** `view($name)` with a non-literal `$name` is not connected back to a template file.
* **Contract annotations are reserved for later.** `{{-- @var \App\Models\User $user --}}` and `@props([...])` based typing are not read yet; every variable they would type still falls back to `mixed`.
