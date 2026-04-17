---
title: "Blade-aware taint analysis: exploration for #581"
parent: Issues
nav_exclude: true
---

# Blade-aware taint analysis

Exploration document for [issue #581](https://github.com/psalm/psalm-plugin-laravel/issues/581).

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
- Depends on Laravel's `BladeCompiler` being available at analysis time, which the plugin already has (Testbench booted), but surface area is wide.

This is the long term target. C is a staging point towards D.

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

C delivers meaningful XSS coverage in weeks and preserves low FP behaviour on the common case. Its `template to unsafe keys` map is the same primitive D needs (for per call sink dispatch), so the work is not throwaway. The scanner can be incrementally upgraded:

- **v1**: top level variables in `{!! !!}` and `@php`. Ignore includes.
- **v2**: follow `@include('child', [...])` data flows. Propagate unsafe keys from child to parent when the parent passes a variable through. Optionally read compiled output from `view:cache` to get `@include` / `@extends` expansion for free.
- **v3**: `@extends`, `@section`, components. At this point, compilation (D) is probably simpler than pattern extension.

D without C means shipping nothing for months. C without D means a permanent accuracy ceiling. Both together means a 2 to 4 week delivery with a credible path forward.

## Prototype

`src/Blade/` contains a working spike of the C approach scanner:

- `BladeEchoKind`: enum for `ESCAPED`, `RAW`, `PHP_BLOCK`.
- `BladeVariableUsage`: `(name, line, kind)` record.
- `BladeTemplateScanner::scan($source): list<BladeVariableUsage>`: extracts all variable references with their echo classification.
- `BladeTemplateScanner::unsafeVariables($source): list<string>`: reduces to the set of unescaped top-level variables, excluding scope-introduced names (for example, `$item` in `@foreach (... as $item)`).
- `BladeSafetyMap`: `array<string, list<string>>` keyed by Blade's dotted view name. Built via `BladeSafetyMap::build(array $viewPaths): self`, which walks view roots and applies first-match-wins like `FileViewFinder::findInPaths()`.

Covered by `tests/Unit/Blade/BladeTemplateScannerTest.php` and `tests/Unit/Blade/BladeSafetyMapTest.php` (36 tests total). The scanner correctly handles `{{-- ... --}}` comments, `@verbatim` (including unclosed), `@{{ ... }}` and `@{!! ... !!}` escaped braces, legacy `{{{ ... }}}`, line number tracking across multi-line blanked regions, multi-line echoes, raw-echo literals inside `@php` strings, `@foreach` and `@forelse` loop aliases (key-value and single), simple inline assignments in `@if` / `@elseif` / `@while` conditions, and property or array access on top level variables. `BladeSafetyMap` tolerates missing view roots, filters non-blade siblings (including `.bak` and `~` editor files), and applies first-match-wins across multiple view paths.

Known scanner limitations (each pinned by a `test_known_limitation_*` test so intentional future improvements land with a visible test change):

- PHP string interpolation inside a raw echo (`{!! "hi {$x}" !!}`) extracts `x` as unsafe. Harmless at the sink layer (the outer expression is already unescaped), but worth knowing.
- The inline `@php($foo = expr)` shorthand is not recognised as a scope-introducing directive.
- `@if (call($x) && $y = expr)` style conditions with a preceding function call do not register `$y` as scope-local; the handler layer will need an explicit safe-list if that pattern matters.

### What the prototype deliberately does not include

- **Plugin integration.** Wiring the map into a handler requires adding a taint sink via `Codebase::$taint_flow_graph`. The API surface exists (see "Psalm API notes" below) but the integration belongs in the actual PR, not the spike.
- **Cross file data flow.** `@include('child', ['x' => $y])` is not propagated. A safe default is to treat a template with any unresolved `@include` call as "all keys unsafe", reverting to the conservative whole array sink for that template only.
- **Components.** `<x-foo :bar="$data" />` is out of scope for v1.

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
