---
title: Blade Template Analysis
nav_order: 8
---

# Blade Template Analysis

Opt-in static analysis of `*.blade.php` templates. Enable it, and Psalm reports type issues, and with `--taint-analysis`, security findings, at the exact template file and line, not just in your PHP.

## What is analyzed

* Every `*.blade.php` file under the booted application's view paths (`config('view.paths')`), plus every namespace hint a service provider registered with `loadViewsFrom()` (`view('pkg::widget')`) — outside your Composer vendor directory. A namespace's own internal templates (Laravel's `notifications`/`pagination`/`laravel-exceptions` namespaces, or any other vendored package's) are never discovered: they ship with component tags and dynamic includes the reference collector cannot resolve, and unioning them in would disable UnusedView project-wide the moment Blade analysis is enabled.
* Each template is compiled through the application's own Blade compiler into a standalone PHP file (a "shadow"), and the shadow is what Psalm actually scans. The template itself is never handed to Psalm as PHP.
* Each template is analyzed on its own. `@include`, `@extends`, and component tags are not followed into the files they reference.
* Issues found in the shadow are relocated onto the `.blade.php` path and the matching template line before they are reported. Nothing in the output points at the compiled shadow.
* If discovery finds zero templates while Blade analysis is enabled, the plugin emits one warning — invisible under `--no-progress`, since Psalm's own `VoidProgress` drops every warning in that mode.

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

A cached shadow is invalidated (recompiled) when either its template's own source changes, or the application's Blade compiler environment changes: a custom directive (`Blade::directive()`), an `@if` condition (`Blade::if()`), an extension, a precompiler, a string-preparation callback, an echo handler or format, the JSON-encoding options `@json` uses, a component alias or namespace (`Blade::component()`, `Blade::anonymousComponentPath()`), `Blade::withoutComponentTags()`, or a compiler subclass swap. Editing one of these invalidates every cached shadow once, even though no template file changed.

What is NOT invalidated, because it is runtime state read from inside a directive's or condition's own body rather than from the compiler's registration surface: `config()`, a global, or a class static property a directive callback reads while compiling. Changing the value these read does not change anything this cache fingerprints, so delete `cacheDir` by hand after a change like that. The same is true of component metadata Laravel resolves live during compilation rather than storing on the compiler: `ComponentTagCompiler` reflects a class component's CONSTRUCTOR PARAMETERS to decide whether an attribute becomes a constructor argument or stays an HTML attribute, and it resolves `<x-foo>` against the live view `Factory` DURING compilation, not at render time. Adding a constructor parameter to an existing component class, or adding a new anonymous-component template file, changes what an unrelated, already-compiled component tag COMPILES TO the next time its parent is recompiled, even though the parent template's own source and the compiler environment above are both unchanged. Its shadow stays fresh and keeps the old compiled bytes. None of these gaps are closed by this cache.

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

These template variables are typed automatically, in every compiled view, without any annotation:

| Variable  | Type                                                                                                                                    |
|-----------|------------------------------------------------------------------------------------------------------------------------------------------|
| `$__env`  | `Illuminate\View\Factory`                                                                                                              |
| `$errors` | `Illuminate\Support\ViewErrorBag`                                                                                                       |
| `$loop`   | `object{index: int, iteration: int, remaining: int\|null, count: int\|null, first: bool, last: bool\|null, odd: bool, even: bool, depth: int, parent: object\|null}` |

Two more are typed, but only inside a template the plugin recognises as a **component view**: one that itself writes `@props(...)`, `@aware(...)`, or mentions `$attributes` or `$slot`. A `<x-*>` tag's *caller* never writes any of those, so the check is exact for that side; a component view the plugin does not recognise this way (none of the four markers present) falls back to `mixed` for both names, silently, same as any other undeclared variable.

| Variable      | Declared as                              | Why                                                                                                                                          |
|---------------|-------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------|
| `$attributes` | `?Illuminate\View\ComponentAttributeBag` when the template writes `@props(...)`, otherwise non-nullable when it writes `@aware(...)` or mentions `$attributes` without `@props` | `@props` compiles to `$attributes ??= new ComponentAttributeBag;` — only that branch can genuinely see the bag as absent. `@aware` assigns nothing, and `Component::data()` / `AnonymousComponent::data()` always supply the key otherwise, so both are typed non-nullable. Put `@props` first in the template: reading `$attributes->` before it sees the nullable type. |
| `$slot`       | `Illuminate\View\ComponentSlot`, whenever the template mentions `$slot`            | Both render paths — a `<x-*>` tag and the classic `@component('view', [...])` directive — hand the rendered view the same `ComponentSlot` (`ManagesComponents::componentData()`), so no union or path-specific typing is needed. |

`$component` is **never** declared. Laravel does not pass it as view data at all — `ManagesComponents::componentData()` merges only the slot and the component's own data — it exists purely as a local in the *calling* template's compiled output, and an `isset($component)` guard there falls back to `mixed` like any other undeclared name.

Every other variable a template uses without a type the plugin can prove gets `mixed`, silently. No `UndefinedGlobalVariable` is raised for it, and no error tells you the variable went untyped by default (see [`reportMixedIssues`](config.md#reportmixedissues)), so a typo in a variable name will not be caught this way.

## What gets reported

* **Type analysis** (a plain `psalm` run): the same issue types Psalm reports anywhere else, at the template's file and line — except Psalm's `MixedIssue` family (`MixedArgument`, `MixedAssignment`, and the rest), which is suppressed by default because an undeclared template variable typing as `mixed` produces it constantly. Opt back in with [`reportMixedIssues`](config.md#reportmixedissues).
* **Taint analysis** (`psalm --taint-analysis`): `TaintedHtml` on unescaped `{!! !!}` output that traces back to request input, with the whole trace shown against template lines, never against the compiled shadow. Escaped `{{ }}` output of the same tainted value is not flagged.

```blade
{{ request()->input('q') }}   {{-- escaped: not flagged --}}
{!! request()->input('q') !!} {{-- unescaped: TaintedHtml --}}
```

### Compiler-generated code

A registered Blade precompiler (Livewire's component tags are the common case, `<livewire:x />`) can rewrite a template into PHP the author never wrote and has no position in the source to annotate. Several issue families are dropped at shadow emission rather than relocated to the template:

* `MissingClosureParamType` and `MissingClosureReturnType`, unconditionally. Precompiler-injected closures dominate this family inside a shadow, and an author has no way to annotate one. This is a deliberate trade-off, not a free win: a closure written by hand inside `@php` or a raw PHP block can carry native parameter types and a docblock, so its missing-type warning is silenced along with the generated noise. Accepted because it is a signature warning on code nothing outside the template ever calls.
* `TooManyArguments`, only when the **call expression** the issue points at does not occur anywhere in the raw template source. Blade rewrites the lines it compiles (`{{ $x->f(1) }}` becomes an `echo e(...)` statement), so the comparison is the call itself, cut out of the compiled line at the issue's own position, rather than the compiled line. An author's over-arity call therefore survives inside `{{ }}`, `{!! !!}`, `@php` and a raw PHP block alike, because Blade wraps around a call expression rather than rewriting its interior. The one thing Blade does change inside a call is `{{-- --}}`: comments are removed before anything else compiles, so they are removed from the template side of the comparison too.
* `UnusedVariable`, unconditionally, under `findUnusedVariablesAndParams`/`--find-unused-code`. A `<x-...>` tag compiles to a `$__componentOriginal*`/`$__attributesOriginal*` save-and-restore pair around the render, and `@foreach`/`@forelse` reassign `$loop = $__env->getLastLoop();` at the end of the loop; none of that bookkeeping is ever read back, so it reports `UnusedVariable` on every template that uses either construct. `UnusedForeachValue` is not part of this drop: it fires on the author-named foreach variable, not compiler bookkeeping, and is real signal. Trade-off: an author's own dead store inside `@php` and an unused foreach *key* also report as `UnusedVariable` and are silenced along with the compiler noise.
* `UnevaluatedCode`, but only the message Psalm uses for code following `return`/`throw`/`continue`: a `@switch` arm's `@break` leaves Psalm treating the following `@case`/`@default` line as unreachable, and that specific message is dropped. `UnevaluatedCode` is not one shape, though — the same class also reports `'gettype cannot return this value'` for a `gettype()` comparison Psalm can prove impossible, which is a genuine author bug and is never gated by `findUnusedVariablesAndParams`, so it is matched on message rather than dropped as a whole class and keeps reporting. The message identifies the Psalm code path that emitted it, not who wrote the unreachable code: an author's own unreachable statement after a `return`/`throw`/`continue` inside `@php` or raw PHP in the template gets the identical message and is silenced along with the compiler's `@break` shape. The relocator sees only the issue's message and location, not the AST, so it cannot tell the two apart; the loss is accepted as a documented limitation rather than chased with snippet heuristics.
* `RedundantCondition`, `RedundantConditionGivenDocblockType`, `DocblockTypeContradiction`, `TypeDoesNotContainNull`, and `TypeDoesNotContainType`, but only inside a component view (see [Ambient variables](#ambient-variables)) and only when the message names `$attributes`, `$component`, or `$slot`. A `<x-...>` tag's compiled bookkeeping — `isset()`, `??=`, `instanceof` — checks exactly what those three names' ambient typing now declares as guaranteed, most visibly when a component view itself renders a NESTED `<x-...>` tag; `@props(...)` REPLACES the prelude's docblock type with an inferred one, so the nested tag's own guard reports the inferred-branch pair (`TypeDoesNotContainNull`/`TypeDoesNotContainType`) rather than the docblock-branch trio, and both are dropped. Gated on the message rather than the class, so an author's own redundant condition against their own docblock (`@if (isset($range))` under a `{{-- @var --}}` or a raw `<?php /** @var */`) keeps reporting even though it shares a class with the dropped shape; and gated on the component-view check too, so a local variable an author happens to name `$attributes`/`$slot` in a plain, non-component template is untouched — the prelude never declares those names there. The trade-off this gate does NOT avoid: it cannot tell compiler-generated bookkeeping apart from an author's OWN `@if (isset($attributes))`/`instanceof` check written inside their own `@props`/`@aware` component view — that guard is silenced too, with no trace, because it names one of the same three ambient variables.

The arity gate applies to `TooManyArguments` alone. A compiled `@include` or `@extends` chain also expands into calls (`$__env->make()`), and gating the whole family on the same rule would silently hide a real `MissingView`. Anything the gate cannot read or cannot recognise as a plain call keeps its issue, so the failure direction is extra noise rather than a lost finding.

### Two runs on Psalm 6

Psalm 6 runs taint analysis exclusively: a plain run reports type issues only, and `--taint-analysis` reports taint issues only. Blade templates follow the same split, so covering both needs two runs, same as the rest of the plugin:

```bash
./vendor/bin/psalm                  # type issues, including Blade templates
./vendor/bin/psalm --taint-analysis # taint issues, including Blade templates
```

## Degradation

Blade analysis never fails a run. If the compiler or view finder cannot be resolved from the booted application, the cache directory cannot be written, or a Psalm internal the plugin depends on has changed shape, the feature turns itself off for that run and prints one warning naming the cause. Psalm's `--no-progress` installs a progress implementation that discards warnings, so a degradation is invisible under that flag.

Degradation is all-or-nothing: the template facts the compile pass collects (contracts, reference sets) are published only once the shadows have actually joined the analysis, so a run that turns the feature off reports nothing from it, and the [`validateViewData`](config.md#validateviewdata), [`reportUnusedViews`](config.md#reportunusedviews), and [`reportUnusedViewData`](config.md#reportunusedviewdata) checks stay silent for that run.

A template that fails to compile (see [Known limits](#known-limits)) is skipped rather than aborting the run. Up to three failing template paths are named directly in the warning; beyond that, run with `--debug` for every individual cause.

## Unused templates

With [`reportUnusedViews`](config.md#reportunusedviews) enabled, the plugin also reports a template that no statically-provable reference ever names ([UnusedView](issues/UnusedView.md)). References are gathered from two places: the `view()` helper and `View::make()` in plain project files, and `@include` / `@extends` inside every compiled template. One reference this plugin cannot resolve statically — a dynamic `view($name)` or `@include($name)` anywhere in the project — turns the check off for the whole run.

## Unused view data

With [`reportUnusedViewData`](config.md#reportunusedviewdata) enabled, the plugin reports a data key a `view()` call site passes that the rendered template neither reads nor declares ([UnusedViewData](issues/UnusedViewData.md)). What each template reads (from its compiled output) and what it declares (`{{-- @var --}}`, `@props`) are taken together, then closed over its `@include` / `@extends` chain, since those directives inherit the including template's whole scope; `@includeIsolated` and `@each` do not, so they never launder a key into it.

The check declines for a call site instead of guessing, and the gate that matters most in practice is that a template whose compiled body hides which names it reads is never checked. `@props` and `@aware` both compile to `$$name` writes, which means component templates are outside this release's reach. The issue page lists the rest.

## Annotating templates

`psalm-laravel blade:annotate` writes the `{{-- @var --}}` declarations a template is missing, taking each type from the `view()` call sites that render it:

```bash
vendor/bin/psalm-laravel blade:annotate            # write them
vendor/bin/psalm-laravel blade:annotate --dry-run  # print a unified diff instead
```

It runs Psalm as a child process, because the producer types only exist during an analysis; flags you pass (`-c psalm.xml`) are forwarded to it, and `--threads=1` is forced, since plugin state collected in a forked worker never reaches the process that does the writing. It refuses to run against a subset of the project (`-f`, or a bare path), because agreement between call sites is a claim about all of them: a call site in a file the run skipped cannot disagree. A run that somehow ends up forked anyway refuses to write and says so, rather than declaring everything `mixed`. Blade analysis is switched on for the run whether or not your config enables it; if it cannot boot, the command reports that the pass was never reached.

The command is the only way in. An ordinary `vendor/bin/psalm` run never writes to a template, whatever the configuration says, and the control file the command passes to its child carries a marker, so a `PSALM_LARAVEL_BLADE_ANNOTATE` left behind in a shell or a CI environment cannot arm the codemod or damage the file it names.

What it declares, per variable the template reads:

* the type itself when at least one call site was resolved, every resolved call site agreed on it, and none of them left the view's data set open (a spread, a dynamic key, a `$mergeData`) or was a rendering shape the plugin could not read at all (`$view = view(...)`). Literal precision is dropped, so two call sites passing `'draft'` and `'published'` declare `string` rather than fighting over which literal wins.
* `mixed` rather than a type that would not mean the same thing once written: one that carries a generic's template parameter, or whose printed form would close the Blade comment early.
* `mixed` otherwise, which still records that the template wants the variable.

A rendering shape the plugin could not read is any expression the call-chain walk declines, not just `$view = view(...)`. `view(...)->render()`, a `view(...)` passed as an argument to something else, and a chain carrying a method the plugin does not model all qualify, because the walk starts at the outermost expression and refuses anything it does not fully understand. Every literal view name inside such an expression is marked unreadable for the whole run, so each template it names gets `mixed` for every variable, including at the call sites that did resolve cleanly.

What it leaves alone:

* a variable the template already declares, in either `{{-- @var --}}` or raw `<?php /** @var */ ?>` form. Existing declarations are never narrowed or rewritten, so re-running the command over an annotated template is a no-op.
* a variable the template binds itself: a `@foreach ($items as $item)` alias is the template's own, not something the call site passes, so `$items` is declared and `$item` is not. List destructuring (`as [$id, $name]`) binds both names the same way.
* a variable some call site provably renders the template without. That call site proves the template works without it (it is read guarded, `{{ $flag ?? false }}`), and declaring it would report [MissingViewVariable](issues/MissingViewVariable.md) there.
* a template whose compiled body hides which names it reads. `@props` and `@aware` compile to `$$name`, so component templates are skipped whole rather than annotated in part.

Everything outside the inserted lines comes out byte for byte identical, including the file's line endings and any BOM. New declarations join an existing contract block if there is one, otherwise they open one at the top of the file.

Array shapes are written as inferred, so a single call site passing `['a', 'b']` declares `list{string, string}` and a later call site passing three elements reports a type error on correct code. Widen such a declaration by hand, or delete it and re-run once both call sites exist.

Annotating changes what the other checks see, which is the point: the declarations it writes are what [`validateViewData`](config.md#validateviewdata) checks call sites against, and what [`reportUnusedViewData`](config.md#reportunusedviewdata) counts as wanted. Expect new findings on the next run, including [MissingViewVariable](issues/MissingViewVariable.md) for a variable a template reads conditionally (`{{ $flag ?? false }}`) that some call site does not pass; drop that declaration, or make the call site pass it. Annotations do **not** type the template body (see [Known limits](#known-limits)).

## Known limits

* **No cross-template following for analysis.** Each template is compiled and analyzed as if it stood alone; `@include`, `@extends`, and component recursion are resolved only for the reference and read-set graphs the two opt-in rules above use, never to type a template's body.
* **Component tags can fail to compile.** A component tag (`<x-...>`) needs the full application container to resolve, which the standalone compile pass does not always provide. A template that fails this way is skipped and reported once in the degradation warning; other templates are unaffected.
* **Undeclared variables are silently `mixed`.** There is no way, yet, to catch a typo'd variable name this way, and the `Mixed*` issues that fallback produces are suppressed by default (see [`reportMixedIssues`](config.md#reportmixedissues)), so they cannot be used to spot one either unless you opt back in.
* **No HTML context awareness.** The plugin does not distinguish an attribute position from a script position from ordinary markup; taint detection is about escaped versus unescaped output, not where in the HTML that output lands.
* **Dynamic view names are not resolved.** `view($name)` with a non-literal `$name` is not connected back to a template file.
* **A `@props` component view that itself renders a nested `<x-...>` tag can still report `PossiblyNullReference` on a later `$attributes->` read.** `@props(...)` compiles to `$attributes ??= new ComponentAttributeBag; ... $attributes = new ComponentAttributeBag($__newAttributes);`, replacing the prelude's nullable docblock type with an inferred one; the nested tag's own `isset($attributes) && $attributes instanceof ...` guard then has a provably-impossible negative arm (dropped, see [Compiler-generated code](#compiler-generated-code)), but Psalm still keeps the reconciled `null` in that never-taken arm and re-unions it into `$attributes`'s type at the guard's `endif`. Left visible deliberately: gating `PossiblyNullReference` by receiver name would also hide an author's genuine `$attributes->` read before their own `@props` line. Pre-existing behaviour, unrelated to this plugin's ambient typing (the same pair reports against a non-nullable `$attributes` declaration too).
* **Contract annotations do not type the template body.** `{{-- @var \App\Models\User $user --}}` and `@props([...])` are read, but only to check the `view()` call sites that render the template (see [`validateViewData`](config.md#validateviewdata)). Inside the compiled template every variable they would type still falls back to `mixed`, because typing the body would change every shadow's content and its cache fingerprint — and the resulting `Mixed*` issues are suppressed by default regardless (see [`reportMixedIssues`](config.md#reportmixedissues)).
* **`Mixed*` suppression can orphan a template's own `@psalm-suppress`.** A `{{-- @psalm-suppress MixedArgument --}}` comment in a template is carried into the compiled shadow, but with `reportMixedIssues` at its default (off) the issue it would have silenced is already dropped before that comment is ever checked against it. Under `--find-unused-psalm-suppress` this reports `UnusedPsalmSuppress` on the template line. Either drop the now-redundant comment, or run with `reportMixedIssues="true"` when checking for unused suppressions.
* **Compiler-generated code suppression has the same `UnusedPsalmSuppress` caveat.** A `{{-- @psalm-suppress MissingClosureParamType --}}`, `{{-- @psalm-suppress TooManyArguments --}}`, `{{-- @psalm-suppress UnusedVariable --}}`, `{{-- @psalm-suppress UnevaluatedCode --}}`, `{{-- @psalm-suppress RedundantCondition --}}`, `{{-- @psalm-suppress RedundantConditionGivenDocblockType --}}`, `{{-- @psalm-suppress DocblockTypeContradiction --}}`, `{{-- @psalm-suppress TypeDoesNotContainNull --}}`, or `{{-- @psalm-suppress TypeDoesNotContainType --}}` comment aimed at a precompiler-generated construct is redundant once that construct is dropped at shadow emission (see [Compiler-generated code](#compiler-generated-code)), and reports `UnusedPsalmSuppress` under `--find-unused-psalm-suppress` for the same reason. There is no flag to opt back in: unlike `reportMixedIssues`, this suppression is unconditional.
