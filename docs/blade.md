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

Only markers Blade actually compiles count. One inside a `{{-- --}}` comment or an `@verbatim` body, or written as an escaped `@@props`/`@@aware`, is ignored: Blade emits it as text or drops it, so it produces no `$attributes` initialisation and is no evidence the template is a component at all.

| Variable      | Declared as                              | Why                                                                                                                                          |
|---------------|-------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------|
| `$attributes` | `?Illuminate\View\ComponentAttributeBag` when the template writes `@props(...)`, otherwise non-nullable when it writes `@aware(...)` or mentions `$attributes` without `@props` | `@props` compiles to `$attributes ??= new ComponentAttributeBag;` — only that branch can genuinely see the bag as absent. `@aware` assigns nothing, and `Component::data()` / `AnonymousComponent::data()` always supply the key otherwise, so both are typed non-nullable. Put `@props` first in the template: reading `$attributes->` before it sees the nullable type. A component view that itself renders a nested `<x-...>` tag gets a further re-assert of the SAME non-null type right after that tag's compiled save/restore bookkeeping (#1543): both blocks reconcile `$attributes` to a spuriously nullable type otherwise, regardless of which of the three forms declared the view a component. |
| `$slot`       | `Illuminate\View\ComponentSlot`, whenever the template writes `@props(...)`/`@aware(...)` or mentions `$slot`            | Both render paths — a `<x-*>` tag and the classic `@component('view', [...])` directive — hand the rendered view the same `ComponentSlot` (`ManagesComponents::componentData()`), so no union or path-specific typing is needed, and a template recognised by a directive gets it whether or not it names `$slot` itself. A bare `$attributes` mention does not pull `$slot` in: it is a guess over a name the template merely happens to use, too weak to declare a second name it never writes. |

`$component` is **never** declared. Laravel does not pass it as view data at all — `ManagesComponents::componentData()` merges only the slot and the component's own data — it exists purely as a local in the *calling* template's compiled output, and an `isset($component)` guard there falls back to `mixed` like any other undeclared name.

Every other variable a template uses without a type the plugin can prove gets `mixed`, silently. No `UndefinedGlobalVariable` is raised for it, and no error tells you the variable went untyped by default (see [`reportMixedIssues`](config.md#reportmixedissues)), so a typo in a variable name will not be caught this way.

## What gets reported

* **Type analysis** (a plain `psalm` run): the same issue types Psalm reports anywhere else, at the template's file and line — except Psalm's `MixedIssue` family (`MixedArgument`, `MixedAssignment`, and the rest), which is suppressed by default because an undeclared template variable typing as `mixed` produces it constantly. Opt back in with [`reportMixedIssues`](config.md#reportmixedissues).
* **Taint analysis** (`psalm --taint-analysis`): `TaintedHtml` on unescaped `{!! !!}` output that traces back to request input, with the whole trace shown against template lines, never against the compiled shadow. Escaped `{{ }}` output of the same tainted value is not flagged.

```blade
{{ request()->input('q') }}   {{-- escaped: not flagged --}}
{!! request()->input('q') !!} {{-- unescaped: TaintedHtml --}}
```

* **`e()` accepts `\Stringable` by design.** Laravel's `e()` has no type declaration on its `$value` parameter; `htmlspecialchars()` coerces it to string at runtime regardless of caller strictness, so passing a `\Stringable` is safe everywhere, not only at echo positions. `{{ $stringable }}` and an explicit `e($stringable)` call anywhere, including inside `@php`, no longer report `ImplicitToStringCast`. Other `ImplicitToStringCast` sites are unchanged: a `\Stringable` passed to a plain function such as `strlen()`, or used in a concatenation under `strict_binary_operands`, still reports.
* **`old()` accepts any `$default`.** Its runtime special-cases only `Model` defaults (resolved via `getAttribute()`) and otherwise forwards the value unconstrained to `Arr::get($input, $key, $default)`, so `{{ old('qty', 0) }}` and `{{ old('active', false) }}` are honest code even though Laravel's own `@param` lists only `Model|string|array|null`. The return type is left at `string|array|null`; see the echo-position gate below for what that union does in `{{ }}`.

### Compiler-generated code

A registered Blade precompiler (Livewire's component tags are the common case, `<livewire:x />`) can rewrite a template into PHP the author never wrote and has no position in the source to annotate. Several issue families are dropped at shadow emission rather than relocated to the template:

* `MissingClosureParamType` and `MissingClosureReturnType`, unconditionally. Precompiler-injected closures dominate this family inside a shadow, and an author has no way to annotate one. This is a deliberate trade-off, not a free win: a closure written by hand inside `@php` or a raw PHP block can carry native parameter types and a docblock, so its missing-type warning is silenced along with the generated noise. Accepted because it is a signature warning on code nothing outside the template ever calls.
* `TooManyArguments`, only when the **call expression** the issue points at does not occur anywhere in the raw template source. Blade rewrites the lines it compiles (`{{ $x->f(1) }}` becomes an `echo e(...)` statement), so the comparison is the call itself, cut out of the compiled line at the issue's own position, rather than the compiled line. An author's over-arity call therefore survives inside `{{ }}`, `{!! !!}`, `@php` and a raw PHP block alike, because Blade wraps around a call expression rather than rewriting its interior. The one thing Blade does change inside a call is `{{-- --}}`: comments are removed before anything else compiles, so they are removed from the template side of the comparison too.
* `UnusedVariable`, unconditionally, under `findUnusedVariablesAndParams`/`--find-unused-code`. A `<x-...>` tag compiles to a `$__componentOriginal*`/`$__attributesOriginal*` save-and-restore pair around the render, and `@foreach`/`@forelse` reassign `$loop = $__env->getLastLoop();` at the end of the loop; none of that bookkeeping is ever read back, so it reports `UnusedVariable` on every template that uses either construct. `UnusedForeachValue` is not part of this drop: it fires on the author-named foreach variable, not compiler bookkeeping, and is real signal. Trade-off: an author's own dead store inside `@php` and an unused foreach *key* also report as `UnusedVariable` and are silenced along with the compiler noise.
* `UnevaluatedCode`, but only the message Psalm uses for code following `return`/`throw`/`continue`: a `@switch` arm's `@break` leaves Psalm treating the following `@case`/`@default` line as unreachable, and that specific message is dropped. `UnevaluatedCode` is not one shape, though — the same class also reports `'gettype cannot return this value'` for a `gettype()` comparison Psalm can prove impossible, which is a genuine author bug and is never gated by `findUnusedVariablesAndParams`, so it is matched on message rather than dropped as a whole class and keeps reporting. The message identifies the Psalm code path that emitted it, not who wrote the unreachable code: an author's own unreachable statement after a `return`/`throw`/`continue` inside `@php` or raw PHP in the template gets the identical message and is silenced along with the compiler's `@break` shape. The relocator sees only the issue's message and location, not the AST, so it cannot tell the two apart; the loss is accepted as a documented limitation rather than chased with snippet heuristics.
* `RedundantCondition`, `RedundantConditionGivenDocblockType`, `DocblockTypeContradiction`, `TypeDoesNotContainNull`, and `TypeDoesNotContainType`, when the message names `$attributes`, `$component`, or `$slot`. A `<x-...>` tag's compiled bookkeeping (`isset()`, `??=`, `instanceof`) checks exactly what those names' ambient typing now declares as guaranteed, most visibly when a `<x-...>` tag renders another `<x-...>` tag nested inside it. `@props(...)` REPLACES the prelude's docblock type with an inferred one, so a nested tag inside a `@props` component view reports the inferred-branch pair (`TypeDoesNotContainNull`/`TypeDoesNotContainType`) rather than the docblock-branch trio, and both are dropped. Gated on the message rather than the class, so an author's own redundant condition against their own docblock (`@if (isset($range))` under a `{{-- @var --}}` or a raw `<?php /** @var */`) keeps reporting even though it shares a class with the dropped shape.

  `$attributes` and `$slot` also require a component view (see [Ambient variables](#ambient-variables)): outside one, neither name is ever declared by the prelude, so a local variable an author happens to name `$attributes`/`$slot` in a plain, non-component template is untouched. `$component` drops with no such requirement (#1532): `componentTypesFor()` never gives it a type in any template, component view or not, so its narrowing comes entirely from a PRECEDING `<x-...>` tag's own compiled `resolve()` call rather than from anything the prelude declares, and that shape fires just as much inside a plain page with a nested `<x-...>` tag as inside a component view.

  The trade-off this gate does NOT avoid: it cannot tell compiler-generated bookkeeping apart from an author's OWN `@if (isset($attributes))`/`instanceof`/`@php $component = ...` check written inside their own template; that guard is silenced too, with no trace, because it names one of the same three ambient variables.

  Dropping the guard's own issue does not undo what it leaves behind: the impossible negative arm still merges a reconciled `null` into `$attributes`'s type at the guard's `endif`, which used to surface downstream as a false `PossiblyNullReference` on the next `$attributes->` read. `$attributes` is now re-asserted non-null right after both compiled `endif`s that can leave it in that state — the nested tag's own inner strip and the enclosing tag's restore — so the message gate above no longer needs to cover `PossiblyNullReference` too. See the known-limits entry below for the one window this does not close.

* `PossiblyInvalidArgument` and `PossiblyFalseArgument`, only on **argument 1 of an `e()` or `echo` call the compiler synthesized**. `{{ $x }}` compiles to `echo e($x)` and `{!! $x !!}` to a bare `echo $x`, so a value whose type is only partly echoable (`string|array|null` from `old()`, `string|false` from `parse_url()`) reports against a callee the author never wrote, and their only fix is a cast on every optional field. Told apart the same way as the arity gate: the enclosing call is cut out of the compiled line — backwards from the argument this time, since that is what the issue points at — and kept only if it occurs in the raw template. That is the whole discriminator, because `@php echo e(old('k')); @endphp` and `{{ old('k') }}` compile to byte-identical lines.

  `InvalidArgument` is not included: a definite `array` in an echo position is a guaranteed `htmlspecialchars()` fatal, not a possibility, and keeps reporting. Neither is any other argument position — an author's own `e($x, $flag)` or `@php echo $a, $b; @endphp` reports as argument 2 and is untouched.

  A missing call only says something about the callee when the **argument** itself survived compilation unchanged, so that is checked first and a rewritten argument keeps its issue. Blade does rewrite raw text inside an author's own expression: `@@foo` is unescaped to `@foo` before echos are compiled, the `##BEGIN-COMPONENT-CLASS##` markers are stripped from the finished output after `@php` blocks are restored into it, and a registered precompiler (Livewire) may rewrite anything at all. Without that check, `{{ e(old('@@foo')) }}` reaching the analyzer as `echo e(e(old('@foo')))` would let the author's own inner `e()` be read as the compiler's and its issue dropped. The cost is noise on the mirror-image shape: when Blade rewrote the argument of a genuinely generated echo (`{{ old('@@foo') }}`), the callee can no longer be proven and the issue is kept.

  Two further shapes the gate does not cover, both leaving the finding visible rather than losing it: a custom `Blade::setEchoFormat('myEsc(%s)')` changes the callee name, and a registered `Blade::stringable()` handler wraps the value in `$__bladeCompiler->applyEchoHandler(...)` so the reported callee is that method. As with the arity gate, a template that contains an author-written `e(`/`echo` of the same expression **anywhere** in the file keeps its issues everywhere in that file.

The arity gate applies to `TooManyArguments` alone. A compiled `@include` or `@extends` chain also expands into calls (`$__env->make()`), and gating the whole family on the same rule would silently hide a real `MissingView`. The echo-position gate above reuses the same "does this piece of the shadow occur in the template?" mechanism but is anchored on two specific callees at one argument position, so it cannot reach any other call. Anything either gate cannot read, or cannot recognise as the call shape it expects, keeps its issue: the failure direction is extra noise rather than a lost finding.

### Two runs on Psalm 6

Psalm 6 runs taint analysis exclusively: a plain run reports type issues only, and `--taint-analysis` reports taint issues only. Blade templates follow the same split, so covering both needs two runs, same as the rest of the plugin:

```bash
./vendor/bin/psalm                  # type issues, including Blade templates
./vendor/bin/psalm --taint-analysis # taint issues, including Blade templates
```

## Threads

Nothing in the pipeline needs a single process, and that is checked rather than assumed: a test compares a forked run against a single-process run of the same fixture, and the same comparison across a corpus of real applications, plain and `--taint-analysis`, cold and warm cache, reported identical issue lists. Shadows are compiled, and the registries the issue remap reads are filled, while Psalm initialises its plugins, which happens before either the scanner or the analyzer forks; every worker therefore inherits a complete copy, and nothing writes to the shadow cache once analysis starts. A template issue found in a worker is re-emitted there against the `.blade.php` path and travels home in the worker pool's result payload like any other issue. A taint finding is emitted later still, in the parent process, after the workers' flow graphs have been merged, so its journey is rewritten where the registries live.

`psalm-laravel blade:annotate` is the one exception, and forces `--threads=1` on the Psalm run it drives (see [Annotating templates](#annotating-templates)). That restriction is about the producer types the command collects, not about analysis.

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
* **A component view that writes a `<x-...>` tag BEFORE its own `@props`/`@aware`/`$attributes` marker, and reads `$attributes->` between the two, can get a non-null assertion it has not earned (#1543).** A nested tag's compiled bookkeeping re-asserts `$attributes` as non-null right after its own save/restore `endif`s (see [Ambient variables](#ambient-variables)), unconditionally — it cannot tell whether a read that follows comes before or after the marker that makes `$attributes` genuinely non-null (`@props`'s own `??=` guard, in particular). Verified narrow: a read placed after the marker, the normal case, is unaffected either way, and a read after the tag or inside its own body is correctly typed regardless of which of `@props`, `@aware`, or a bare mention declared the view a component. The one case this re-assert skips outright is a template that itself assigns to (or `unset()`s) `$attributes` anywhere in its own source: the assertion cannot prove Laravel's `isset($attributes)`-gated save/restore ran again after that write, so the re-assert is withheld for the whole template and the original nullable/mixed typing (and its pre-#1543 false positives) applies instead.
* **Contract annotations do not type the template body.** `{{-- @var \App\Models\User $user --}}` and `@props([...])` are read, but only to check the `view()` call sites that render the template (see [`validateViewData`](config.md#validateviewdata)). Inside the compiled template every variable they would type still falls back to `mixed`, because typing the body would change every shadow's content and its cache fingerprint — and the resulting `Mixed*` issues are suppressed by default regardless (see [`reportMixedIssues`](config.md#reportmixedissues)).
* **`Mixed*` suppression can orphan a template's own `@psalm-suppress`.** A `{{-- @psalm-suppress MixedArgument --}}` comment in a template is carried into the compiled shadow, but with `reportMixedIssues` at its default (off) the issue it would have silenced is already dropped before that comment is ever checked against it. Under `--find-unused-psalm-suppress` this reports `UnusedPsalmSuppress` on the template line. Either drop the now-redundant comment, or run with `reportMixedIssues="true"` when checking for unused suppressions.
* **Compiler-generated code suppression has the same `UnusedPsalmSuppress` caveat.** A `{{-- @psalm-suppress MissingClosureParamType --}}`, `{{-- @psalm-suppress TooManyArguments --}}`, `{{-- @psalm-suppress UnusedVariable --}}`, `{{-- @psalm-suppress UnevaluatedCode --}}`, `{{-- @psalm-suppress RedundantCondition --}}`, `{{-- @psalm-suppress RedundantConditionGivenDocblockType --}}`, `{{-- @psalm-suppress DocblockTypeContradiction --}}`, `{{-- @psalm-suppress TypeDoesNotContainNull --}}`, or `{{-- @psalm-suppress TypeDoesNotContainType --}}` comment aimed at a precompiler-generated construct is redundant once that construct is dropped at shadow emission (see [Compiler-generated code](#compiler-generated-code)), and reports `UnusedPsalmSuppress` under `--find-unused-psalm-suppress` for the same reason. There is no flag to opt back in: unlike `reportMixedIssues`, this suppression is unconditional.
