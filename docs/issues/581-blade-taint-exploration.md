---
title: "Blade-aware taint analysis: exploration for #581"
parent: Issues
nav_exclude: true
---

# Blade-aware taint analysis

Exploration document for [issue #581](https://github.com/psalm/psalm-plugin-laravel/issues/581).

## Delivery and merge strategy

The work lands in incremental sub-PRs that all target the umbrella branch
[`worktree-581-blade-taint-exploration`](https://github.com/psalm/psalm-plugin-laravel/pull/872),
not `master`. Merging the scanner epic piecewise into `master` would expose
half-implementations (e.g. a scanner that classifies templates but no handler
that consumes the classification), so each sub-PR stacks on the umbrella
branch and is reviewed against it. Once every sub-PR (PR-1 through PR-5)
is merged into the umbrella branch, the umbrella PR ships into `master` as
a single reviewable unit.

Status of the epic at the time of writing:

| PR | Scope | Status |
|----|-------|--------|
| PR-1 [#920](https://github.com/psalm/psalm-plugin-laravel/pull/920) | Tri-state scanner via `BladeCompiler` + php-parser. `BladeViewSafetyKind`, `BladeUncertaintyReason`, `BladeSafetyMap`. | merged into umbrella |
| PR-2 (TBD) | First-pass scanner precision: scope frame push/pop, source-mapped line numbers. (Literal `@include` resolution shipped in PR-4.) | not started |
| PR-3 [#926](https://github.com/psalm/psalm-plugin-laravel/pull/926) | `BladeAwareViewTaintHandler`. Wires `BladeSafetyMap` into Psalm's taint flow for `view()` / `Factory::make()` / facade `View::make()`. Per-key sinks for `UNSAFE_KEYS`, whole-data fallback for `UNKNOWN` and dynamic view names. | merged into umbrella |
| PR-4 (this PR) | Extended dispatch to `Factory::first()` (multi-template union), `Factory::renderEach()` (per-iterator key shape), `Routing\ResponseFactory::view()` (concrete + contract), `View::with()` / `View::nest()` (receiver-resolved view name), and the Mailable / MailMessage / Content view-binding methods. Scanner records literal `@include('child', [...])` edges; `BladeSafetyMap::build()` folds child unsafe keys into the parent via a fixed-point pass that accounts for Laravel's `compileInclude()` mergeData pass-through. Cycles in the include graph bail to `UNKNOWN(IncludeCycle)`. | shipping |
| PR-5 (TBD) | Component data flow (`<x-foo :bar="$data" />`), section graph, scanner result caching across analysis runs, variable-bound view-builder chains (`$v = view('home'); $v->with(...)`). | not started |

Neither PR-3 nor PR-4 includes integration tests under `tests/Application/`.
The Application test harness (`tests/Application/laravel-test.sh`) runs Psalm
without `--taint-analysis`, so a taint-validating fixture would require either
a new harness flow or a refactor of the existing one. The handler is covered
end-to-end by `tests/Type/tests/Blade/` (PHPT) and unit tests; the Application
test layer still exercises Plugin.php's boot path through `initBladeAwareViewTaintHandler`,
so a boot crash would still surface there.

## Current state (baseline)

After the regression in [#684](https://github.com/psalm/psalm-plugin-laravel/issues/684), all `html` taint sinks on view data parameters were removed:

| Stub | Prior state (PR #580) | Current state |
|------|-----------------------|---------------|
| `view()` helper `$data`, `$mergeData` | `@psalm-taint-sink html` | no sink |
| `Factory::make`, `first`, `renderWhen`, `renderUnless`, `renderEach`, `share` | `html` sinks | no sinks |
| `View::with($key, $value)` | `html` sinks | no sinks |
| `Factory::file($path)` | `file` and `include` sinks (predate PR #580) | unchanged |
| `ResponseFactory::view($data)` | `html` sink | no sink |

Net effect: **zero XSS detection through Blade views**. Path injection on `Factory::file()` is the only remaining view-layer security signal. The removal eliminated massive false positives (pixelfed: 10 of 30 findings) but left a blind spot that templates using `{!! $var !!}` still exploit.

The question is no longer "how do we reduce FPs". The sinks are gone. It is: "how do we restore coverage without bringing the FPs back?"

## Approaches assessed

Each approach is scored on four axes:

- **FP**: false positive rate on the common case (`{{ $var }}` rendering)
- **FN**: false negative rate on real XSS (`{!! user_input !!}`)
- **Effort**: engineering time
- **Scope**: what it misses entirely

### A. Status quo (do nothing)

| FP | FN | Effort | Scope |
|----|----|--------|-------|
| 0 | 100% | 0 | Misses all Blade XSS |

Documents the plugin as "does not detect Blade XSS". Users cannot rely on taint analysis for view layer flaws and must add their own SAST layer or rely on code review. Acceptable only as a starting point, not a destination.

### B. Scan templates, report every `{!! !!}` as a custom issue

| FP | FN | Effort | Scope |
|----|----|--------|-------|
| High | Low for raw echoes, 100% for echo inside @php | 1 to 2 weeks | Rule based, no data flow |

A handler walks `.blade.php` files and emits `UnsafeBladeRawOutput` for every `{!! $x !!}`. Triggers regardless of whether the rendered value is hardcoded or user controlled.

**Problem:** most `{!! !!}` usages are intentional (rendering pre-sanitised markdown, `@csrf`, framework-generated HTML helpers, `@svg`). The rule would be immediately baselined or disabled in every real project. Without a taint signal, severity cannot be graded.

Worthwhile only as an **opt-in strict mode** for security critical codebases.

### C. Template aware sink refinement (recommended)

| FP | FN | Effort | Scope |
|----|----|--------|-------|
| Low (only unsafe keys) | Bounded by scanner accuracy | 2 to 4 weeks | Misses include/extend chains, components |

At boot:

1. Scan every template, build a map from template name to list of unsafe keys (variables that reach at least one `{!! !!}` or `@php echo`).
2. Register handlers for `view()`, `Factory::make()`, `Factory::first()`, `Factory::renderWhen()`, `Factory::renderUnless()`, `ResponseFactory::view()`.

At each call:

3. Resolve the literal template name from argument 0.
4. Look up unsafe keys for that template.
5. For each key in the data array whose name matches, programmatically attach a taint sink to the value expression's `DataFlowNode`s.

This reuses Psalm's existing taint graph. False positives reduce to the subset of keys genuinely reaching raw output. The scanner misses cross file flows (for example, `@include('child', ['x' => $y])`), but that is an accuracy bound, not a correctness bug: when the scanner is uncertain, it can fall back to the conservative whole array sink for safety.

### D. Compile and analyse (Bladestan equivalent)

| FP | FN | Effort | Scope |
|----|----|--------|-------|
| Near zero | Near zero | 2 to 6 months | Full |

Invoke Laravel's `BladeCompiler::compileString()` for each template, write the output to a synthetic PHP file that wraps the data array via `extract()`, and let Psalm analyse the generated PHP the same way it analyses any other file, with taint flowing from the `view()` call site into `echo e($var)` (safe) or `echo $var` (sink). This is what Bladestan does for type checking. Extended to taint, it would give the cleanest results.

**Why not now:**

- Bladestan's `BladeToPHPCompiler` is roughly 1 kLOC and handles `@include` expansion, component instantiation (via reflection), view composers, and shared data. Porting to Psalm is non trivial. The compiled output must then be stitched into Psalm's scanning phase so taint nodes flow through.
- Error mapping back to `.blade.php` line numbers requires a line map, like Bladestan's.
- Plugin startup cost: compiling every template at boot is O(templates) filesystem and CPU work.

`BladeCompiler::compileString()` itself is in fact available cheaply (Testbench is already booted), and walking the compiled PHP in-process via `nikic/php-parser` (already vendored by Psalm 7) covers most of what approach C's scanner needs. That partial use is incorporated into approach C's v2 backend (see "Prototype" below) — distinct from D's full Bladestan-style approach because it skips call-site bridging, synthetic stub generation, and feeding compiled PHP back to Psalm as an analyzed file.

D in its full form (handler-level call-site bridging with stitched taint nodes) remains the long-term target; the C scanner is the staging point towards D.

### E. Standalone CLI tool

| FP | FN | Effort | Scope |
|----|----|--------|-------|
| Same as B | Same as B | 1 to 2 weeks | Template only |

Separate binary that scans `.blade.php` files. Not recommended: taint context is what makes this plugin valuable, so splitting it off forfeits the main advantage.

### F. Pre-compile via `php artisan view:cache`, run Psalm on the output

A tempting variant that surfaces every few discussions: run Laravel's own compiler up front, then analyse the generated PHP.

```
"php artisan view:cache",
"@php -d memory_limit=-1 vendor/bin/psalm -c psalm-views.xml --no-suggestions",
"php artisan view:clear"
```

The appeal is real: compiled templates contain `echo e($var)` (safe) versus `echo $var` (raw), so Psalm's built-in `echo` sink does the safe / unsafe classification for free. No regex scanner, no approach-C machinery. This is essentially how Bladestan handles Blade for type checking.

**Why it doesn't work for taint analysis as shown:**

1. **No call-site to template link.** Compiled views look like `extract($__data); echo $var;`. Psalm sees disjoint files. There is no flow from `view('foo', ['x' => $tainted])` in a controller to the compiled counterpart of `resources/views/foo.blade.php`. Without a bridging layer, taint does not propagate across the call boundary. Bladestan solves this by generating wrapper files that tie each `view()` call's data array to the compiled template via `@var` pins. Adding that layer turns approach F into approach D under a different name, not a shortcut to it.
2. **Error locations are useless.** Issues land at `storage/framework/views/{hash}.php:42`. Mapping back to `resources/views/user/show.blade.php:18` needs a line map (Bladestan maintains one, and Blade's own `# line` comments are inconsistent across versions).
3. **Shared data and composers invisible.** `View::share()` and view composers inject variables at runtime that are not in the `$data` array at the call site. Static analysis needs explicit stubs for them.
4. **Undefined-variable flood.** Compiled files reference `$__env`, `$__data`, `$__vars`, `$component`, component slot locals, and so on. Without stubs, Psalm emits thousands of `UndefinedVariable` and `MixedArgument` issues that users have to baseline before anything useful surfaces.

**UX costs of the three-command wrapper:**

- Requires a bootable Artisan (writable `storage/`, a configured app). Library projects that don't boot a full Laravel cannot use it.
- `view:clear` at the end wipes the developer's local compiled-view cache on every run, disrupting the normal dev loop.
- Psalm's own cache is invalidated on every run because the compiled-view filenames rotate (they are hashes over source paths and mtime).

**Where the idea becomes useful:**

As an input to approach C's scanner, not a replacement for it. Walking the compiled PHP via `nikic/php-parser` and looking for `echo` versus `echo e(...)` handles `@include`, `@extends`, and components for free (they are already expanded in the compiled output). This eliminates approach C's "cross-file flow is out of scope" limitation without doing full wrapper generation. The bootable-Artisan dependency and the error-mapping problem remain, though, so it's a v2 upgrade of the scanner rather than a v1 strategy.

**Verdict:** approach F is approach D with the hard part (call-site bridging, line map, stubs for runtime-injected data) still to be built. The appealing three-line Composer script hides that work instead of removing it.

### Conservative fallback

Any template whose scanner analysis is uncertain (unresolved `@include` targets, unrecognised directives, parse errors) should be treated as "all keys unsafe" and revert to the whole-array sink for that template only. This preserves the correctness guarantee of the original (FP-heavy) annotation for templates the scanner can't reason about, while still reducing FPs for the majority of safe templates. The fallback is an explicit code path, not a silent one, and must be tested.

## Recommendation

**C, then D.** Approach F (pre-compile via `view:cache`) is not a shortcut; if we ever pursue it, it should be as a v2 input to C's scanner (walk compiled PHP instead of raw `.blade.php`), not as a replacement for the call-site bridging work D requires.

C delivers meaningful XSS coverage in weeks and preserves low FP behaviour on the common case. Its `template to unsafe keys` map is the same primitive D needs (for per call sink dispatch), so the work is not throwaway. The scanner is delivered in two stages:

- **v1 backend (shipped in #920)**: `BladeCompiler::compileString()` + `nikic/php-parser` AST walking, in-process. Both deps are already vendored (Laravel pulls the compiler; Psalm 7 pulls the parser). A small subclass of `BladeCompiler` makes component-tag resolution non-fatal at analysis time and patches an upstream raw-block restoration quirk (see `PsalmBladeCompiler`). Cross-template constructs (`@extends`, `@yield`, `@stack`, `@include`, `<x-foo>` / `@component`) still classify UNKNOWN because the v1 scanner does not follow child templates. Inline `@php(...)`, mid-condition assignments, scope-local destructuring, `@inject` locals, `e()`/`htmlspecialchars`/`htmlentities`/`Js::from` safe-wrapper detection, and a conservative fallback for unrecognized echo shapes (`{!! request()->input(...) !!}`, variable variables, closure invocations) all work out of the box.
- **v2 (long-term)**: full approach D with call-site bridging, line maps, and stubs for runtime-injected data (`View::share`, composers). The v1 scanner produces the same per-template safety map D needs, so it is not throwaway.

D without C means shipping nothing for months. C without D means a permanent accuracy ceiling. Both together means a 2 to 4 week delivery with a credible path forward.

## Prototype

`src/Blade/` contains the C approach scanner:

- `BladeEchoKind`: enum for `ESCAPED`, `RAW`, `PHP_BLOCK` (the third case is reserved; the AST walker collapses `@php`-block echoes onto `RAW` because the compiled output is indistinguishable from `{!! ... !!}`).
- `BladeVariableUsage`: `(name, line, kind)` record. Line numbers reflect the compiled PHP, not the original Blade source.
- `BladeViewSafetyKind`: tri-state enum `SAFE` / `UNSAFE_KEYS` / `UNKNOWN`.
- `BladeUncertaintyReason`: first-class enum naming the construct that forced UNKNOWN (`LAYOUT_SECTION_FLOW`, `INCLUDE_DIRECTIVE`, `COMPONENT_TAG`, `FILE_UNREADABLE`, `UNKNOWN_LOCAL_DEPENDENCY`, `UNPARSABLE_PHP_BLOCK`, plus reasons reserved for later PRs).
- `BladeTemplateAnalysis`: `(kind, unsafeKeys, uncertainties)` value object with a private constructor and three named factories (`safe()`, `unsafeKeys()`, `unknown()`) that enforce kind-to-payload invariants. `unsafeKeys([])` collapses to `safe()`; `unknown()` requires a non-empty list of reasons.
- `PsalmBladeCompiler`: subclass of Laravel's `BladeCompiler`. Overrides `compileComponentTags` to detect (not resolve) `<x-foo>` and `@component` / `@slot` tags, so an unregistered user component cannot throw at analysis time. Overrides `restoreRawContent` and preprocesses the source to work around an upstream raw-block placeholder collision when `@endphp` is immediately followed by `{{`/`{!!`. Owns its own `Filesystem` and temp cache path; no Laravel container access required.
- `BladeTemplateScanner::analyze($source): BladeTemplateAnalysis`: compiles via `PsalmBladeCompiler::compileBladeSource()` then walks the resulting PHP AST with `nikic/php-parser`. The visitor records raw-echoed top-level variables, scope-locals from assignments and foreach value/key vars (including nested destructuring), `$__env` method calls indicating layout/section/stack/include/component/fragment/translation flow, and emits `UNKNOWN_LOCAL_DEPENDENCY` as a conservative fallback when a raw echo's expression yields no top-level variables and is not a pure literal (catches `request()->input(...)`, variable variables, closure invocations, and other shapes the extractor does not model).
- `BladeTemplateScanner::scan($source): list<BladeVariableUsage>`: lower-level per-usage records for diagnostics.
- `BladeViewSafety`: `(viewName, path, analysis)` map entry.
- `BladeSafetyMap`: `array<string, BladeViewSafety>` keyed by dotted view name. Built via `BladeSafetyMap::build(array $viewPaths, ?BladeTemplateScanner $scanner = null): self`. One scanner instance is amortised across the whole build. First-match-wins applies to ALL kinds, including SAFE — a later UNSAFE shadow cannot displace a first-root SAFE view that Laravel would actually render. `file_get_contents` failures convert to `UNKNOWN(FILE_UNREADABLE)` instead of silent skip.

Covered by `tests/Unit/Blade/Blade*Test.php`. The scanner handles `{{-- ... --}}` comments and `@verbatim` (both stripped at compile time), `@{{ ... }}` / `@{!! ... !!}` escaped braces, legacy `{{{ ... }}}`, multi-line echoes, raw-echo literals inside `@php` strings, `@foreach` / `@forelse` aliases including list-destructuring `@foreach ($pairs as [$k, $v])`, inline assignments in `@if` / `@elseif` / `@while` conditions (any position, not just leading), `@inject` scope locals, `@php($foo = expr)` inline shorthand, property / array / method access top-level extraction, casts and unary operators (`(string) $x`, `!$x`, `-$x`), array literals and `match` expressions inside raw echoes, and chained `Js::from($data)->toHtml()`. `e($x, false)` and other multi-argument safe-wrapper calls correctly fall through to RAW. Cross-template directives (`@extends`, `@extendsFirst`, `@section`, `@yield`, `@show`, `@parent`, `@stack`, `@push`, `@prepend`, `@hasSection`, `@hasStack`, the `@include*` family, `@each`, `<x-...>` / `<x:...>` component tags, `@component`, `@slot`, `@fragment`, `@lang { ... }`) mark the template UNKNOWN with the appropriate `BladeUncertaintyReason`. `BladeSafetyMap` tolerates missing view roots, filters non-blade siblings (including `.bak` and `~` editor files), and applies first-match-wins across multiple view paths regardless of kind.

Known v1 limitations:

- The visitor's `scopeLocals` set is flat across the whole template. Assignments inside inner closures, arrow functions, function declarations, or unreachable `@if` branches still register the LHS as a scope-local for the outer raw-echo classification. Practical impact: a Blade `@php` block whose closure body shadows a view-data key with a literal silently filters that key out of the unsafe-key list. Push/pop scope frames is the fix; deferred.
- Line numbers in `BladeVariableUsage` reflect the compiled PHP, not the Blade source. BladeCompiler does not preserve a source map, so per-occurrence line numbers are a hint, not a contract.
- The catch-all `extractTopLevelVariables` branch for unknown calls (`FuncCall`, `StaticCall`, `New_`) recurses into arguments but does not surface the callable itself. `{!! random_fn($x) !!}` surfaces `x` (correct, since `$x` is the data flowing through), but `{!! $fn($x) !!}` does not surface `fn`. Acceptable: `$fn` is the callable identity, not the rendered data.

### What the prototype deliberately does not include

- **Plugin integration.** ~~Wiring the map into a handler requires adding a taint sink via `Codebase::$taint_flow_graph`. The API surface exists (see "Psalm API notes" below) but the integration belongs in the actual PR, not the spike.~~ Delivered in PR-3 (this PR) as `BladeAwareViewTaintHandler`. Reads the map at every `view()` / `Factory::make()` / facade `View::make()` call, dispatches per safety kind: SAFE no-op, UNSAFE_KEYS per-key html sink, UNKNOWN whole-data sink, dynamic view name whole-data sink. Boots once per analysis run in `Plugin::initBladeAwareViewTaintHandler()`; gated on `taint_flow_graph !== null` so non-taint runs pay no scan cost.
- **Cross file data flow.** `@include('child', ['x' => $y])` is not propagated. A safe default is to treat a template with any unresolved `@include` call as "all keys unsafe", reverting to the conservative whole array sink for that template only. PR-3 implements this fallback by classifying the parent as UNKNOWN(IncludeDirective); PR-4 will resolve literal include targets and propagate the child's unsafe-keys into the parent.
- **Components.** `<x-foo :bar="$data" />` is out of scope for v1. PR-5 territory.

## Psalm API notes (for the integration PR)

Psalm 7 exposes the machinery required for per call sink attachment:

- `Psalm\Codebase::$taint_flow_graph: ?\Psalm\Internal\Codebase\TaintFlowGraph`: public property, null when `--taint-analysis` is off. Plugins must null check before use.
- `\Psalm\Internal\Codebase\TaintFlowGraph::addSink(DataFlowNode $node)`: registers a node as a sink.
- `\Psalm\Internal\Codebase\DataFlowGraph::addPath($from, $to, $path_type, $added_taints, $removed_taints)`: adds a directed edge, needed to connect the argument's existing parent node(s) to the newly created sink.
- `\Psalm\Type\TaintKind::INPUT_HTML`: the taint kind to apply. `TaintKind` is public and part of Psalm's supported API.
- `\Psalm\Internal\DataFlow\DataFlowNode::getForMethodArgument($method_id, $cased_method_id, $argument_offset, ?$arg_location, ?$code_location, int $taints)`: canonical factory for argument-position sinks, matches how `ArgumentsAnalyzer` constructs sinks for stubs with `@psalm-taint-sink`. When a `CodeLocation` is passed as the 5th parameter (`$code_location`, NOT `$arg_location`), it auto-derives a `specialization_key` from the file name and raw file offset, which makes the resulting node ID unique per call site with no extra work.

**Stability warning:** `TaintFlowGraph` and `DataFlowGraph` live in `Psalm\Internal\Codebase\`. `DataFlowNode` lives in `Psalm\Internal\DataFlow\`. All three are internal, and `DataFlowNode` carries an `@internal` class docblock. The `Codebase::$taint_flow_graph` property is public, but its value's type is an internal class, so this API has no BC guarantee across Psalm 7.x minors. The integration must budget for upstream churn.

Registration hook: `AfterFunctionCallAnalysisInterface` for `view()`, `AfterMethodCallAnalysisInterface` for the `Factory`, `View`, and `ResponseFactory` methods. Both events carry `Codebase` and `StatementsSource`, so the plugin has everything needed to:

1. Get the `FuncCall` or `MethodCall` AST via `$event->getExpr()`.
2. Resolve the template name from the first argument (literal string only, dynamic names must fall through).
3. Look up unsafe keys.
4. Walk the data array `ArrayItem` nodes. For each `string` key matching an unsafe entry, fetch the value expression's `Union` via `getStatementsSource()->getNodeTypeProvider()->getType()`.
5. Create a sink `DataFlowNode` via `getForMethodArgument(..., $codeLocation)`, call `$codebase->taint_flow_graph->addSink($sink)`, and `addPath($parent, $sink, ...)` for each `$parent_node` on the value's `Union`.

Gotchas:

- Plugin event fires **after** `ArgumentsAnalyzer` has already wired up the declared sinks. The new sink is additive.
- Pass the call's `CodeLocation` as the 5th argument (`$code_location`) to `getForMethodArgument()` so Psalm builds a per-call-site `specialization_key` automatically. Passing it only as `$arg_location` (the 4th argument) does not trigger specialization. Hand-rolling IDs is unnecessary and risks collisions.
- Dotted keys in the data array (for example, `['user.name' => $x]`) are not valid PHP identifiers and are not extracted into local variables by `extract()`. Treat only top level, literal string keys that are valid identifiers.
- Facade and alias calls must be covered by registering on both the concrete class and its facades. `AfterMethodCallAnalysisInterface` fires per resolved method ID, so registering against `Factory::class` plus `FacadeMapProvider::getFacadeClasses(Factory::class)` catches all forms. This is the pattern `MissingViewHandler` already uses (see `src/Handlers/Views/MissingViewHandler.php:107`). Note: the stub-level `__callStatic` limitation described for `DB::unprepared()` in CLAUDE.md applies to declarative `@psalm-taint-sink` annotations on alias stubs, which is a different path from this programmatic sink.

## Known edge cases

- **Dynamic view names.** `view($viewName, $data)` where `$viewName` is a variable. Skip. Same convention as `MissingViewHandler`.
- **Component templates.** Anonymous components receive data via attributes, not the `view()` data array. Out of scope for v1.
- **Mail and notification views.** `MailMessage::view()`, `Mail\Mailable::view()`. Same pattern. Worth covering in the same PR for completeness.
- **Custom view engines.** Non Blade templates (`.php`, Twig, etc.) should be skipped. The scanner only claims to understand Blade.
- **Shared data.** `Factory::share('key', $value)` sets a global view variable. The safety map cannot know which templates will use it. Best to leave `share()` without a sink and rely on the per template analysis at render time.

## Effort estimate for the full integration PR

Assuming the scanner lands first as a separate PR:

| Task | Estimate |
|------|----------|
| Scanner and unit tests (this spike) | 1 to 2 days (done) |
| `BladeSafetyMap` and boot time wiring in `Plugin.php` | 1 day |
| `BladeAwareViewHandler` (hooks `view()`, `Factory::make/first/renderWhen/renderUnless/renderEach`, and `ResponseFactory::view`) | 2 to 3 days |
| Test coverage (type tests for FP suppression and TP detection) | 2 days |
| Cache invalidation strategy (scanner output keyed on template mtime, parallel to `MigrationCache`) | 0.5 day |
| Baseline and application test against pixelfed or monica | 1 day |
| Docs (`docs/issues/UnsafeBladeData.md`), CLAUDE.md entry, release notes, migration guide for existing baselines | 1 day |
| `@psalm-taint-escape` escape-hatch documentation (for markdown renderers and other intentional raw HTML) | 0.5 day |
| **Total** | **9 to 11 days** (about 2 weeks) |

This puts C firmly in the "next medium task" bucket and sets up D as a future quarter's work once C proves its value on real apps.
