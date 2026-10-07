---
title: Contributing
nav_order: 7
has_children: true
---

# Contributing

## How the plugin works

The plugin boots a Laravel application, then hooks into Psalm's event system to override type inference for Laravel APIs.

The app is needed at boot time to read config values (e.g. `auth.php` guards), resolve facade aliases via `AliasLoader`, and load service providers.
When run inside a Laravel project, the plugin loads the project's own `bootstrap/app.php` — so it sees the real config, routes, and providers.
When no `bootstrap/app.php` is found (e.g. analyzing a Laravel package, or running the plugin's own tests), it falls back to [Orchestra Testbench](https://github.com/orchestral/testbench) which provides a minimal Laravel app skeleton.

See `ApplicationProvider::doGetApp()` for the resolution logic.

```mermaid
flowchart TD
    A["Plugin::__invoke"] --> B["Parse PluginConfig from psalm.xml"]
    B --> C["Boot Laravel app\n(ApplicationProvider::bootApp)"]
    C --> D["Build migration schema\n(only if columnFallback=migrations)"]
    D --> E["Init facade→service map\n(FacadeMapProvider)"]
    E --> F["Init translation / view / env handlers\n(from booted app state)"]
    F --> BL["Compile Blade templates into shadow files\n(only if &lt;blade /&gt;)"]
    BL --> G["Register handlers\n(Plugin::registerHandlers)"]
    G --> H["Register stubs\n(Plugin::registerStubs)"]

    H --- stubs["
        stubs/common/ (types + taint annotations)
        versioned dirs, ascending (e.g. stubs/12.42.0/, stubs/13.5.0/, stubs/13.8.0/)
        stubs/integrations/carbon/ (gated on installed nesbot/carbon version)
        aliases.phpstub (generated here from AliasLoader)
    "]

    I["Psalm scans all project files"] -.->|afterCodebasePopulated| J["ModelRegistrationHandler"]
    I -.->|afterCodebasePopulated| K["Eloquent Builder subclass fix-ups:\nBuilderSubclassQueryMixinHandler (restores dropped Query Builder @mixin)\nBuilderNativeStaticReturnTypeHandler (native ': static' return becomes docblock 'static')"]
    I -.->|afterCodebasePopulated| FMB["FactoryModelBindingHandler (injects @extends Factory&lt;TModel&gt; on bare factory subclasses, #780)"]
    I -.->|afterCodebasePopulated| FSP["FacadeStubPrecedenceHandler (drops conflicting facade @method pseudos when the plugin ships a real stubbed static method)"]
    I -.->|afterCodebasePopulated| FTF["FacadeTaintForwardingHandler (copies taint sinks from a facade's forwarding target onto its @method pseudo-methods)"]
    J --- models["
        Discover Model subclasses
        Register per-model property/method closures:
        relationship > factory > accessor > column
    "]
```

The whole `__invoke` body is wrapped in a try/catch: on any internal error the plugin reports a warning and disables itself for the run (or rethrows when `failOnInternalError` is set). See `src/Internal/InternalErrorReporter.php`.

Bootstrap failures are a special case: `ApplicationProvider` swallows a `bootstrap()` throw to keep the run alive (one bad `config/*.php` must not disable the plugin), so they never reach the try/catch above. `Plugin::__invoke` checks `ApplicationProvider::getBootstrapError()` right after boot and routes it through `InternalErrorReporter::reportDegradedBoot()`: a "degraded mode" warning by default, or escalation to the regular internal-error path when `failOnInternalError` is set (issue #1096). Note that Psalm's `--no-progress` flag installs a `VoidProgress`, which silences all `Progress::warning()` output, including these.

### Blade shadow files

Behind [`<blade />`](../config.md#blade), `Plugin::initBladeAnalysis()` compiles every `*.blade.php` file under the booted app's view paths into a PHP shadow file (`src/Blade/`) and registers the result with the run. `Blade\ContractParser` reads `@var`/`@props` declarations from the template source using `MarkerPrePass` masking and nikic/php-parser. `BladeBootstrapper::run()` degrades the feature with one warning when the compiler or view finder cannot be resolved, never runs with contracts silently empty. It is synchronous inside `__invoke` on purpose: a file can only still join the analysis while `Config::initializePlugins()` is on the stack, which Psalm calls after queueing the project files and before scanning them.

The registrations, deliberately asymmetric (`Blade\PsalmShadowRegistrar`):

- the **shadow** is added via `Codebase::addFilesToAnalyze()`, which both deep-scans and analyzes it. It must stay out of `ProjectAnalyzer`'s project-file list: `TaintFlowGraph` drops a flow whose source sits in a reportable file that suppresses `TaintedInput`, and Psalm's own `addFilesToShowResults()` is redundant here because `Analyzer::addFilesToAnalyze()` already writes the same map.
- the **template** is written into `ProjectAnalyzer::$project_files` by reflection (`Blade\ProjectFileInjector`), because that private list is built from psalm.xml before plugins initialize and is all `canReportIssues()` reads. Without the write, nothing found in a template can ever be reported. The template is never queued for analysis: it is not PHP.
- the **ambient prelude classes** (`\Illuminate\View\Factory`, `ComponentAttributeBag`, `ComponentSlot`, `Component`, `Support\ViewErrorBag`) are queued via `Codebase::queueClassLikeForScanning()`. Every shadow declares them as stacked one-line `@var` docblocks, and PhpParser attaches every stacked comment to one node's comment list, so `Node::getDocComment()` — all Psalm's scanner reads to queue docblock classes — returns only the last one. The last declared entry is `$loop`, an object shape naming no class, so left unqueued, all five ambient FQCNs report `UndefinedDocblockClass` the first time nothing else in the project names them in code position (#1494).
- **string-literal class candidates** (`Blade\ClassLiteralCollector`) are queued via `ShadowRegistrar::queueResolvableClassLikesForScanning()`. A vendor directive can compile a class name into a PHP string argument (`app('Vendor\Package\Class')::method()`) instead of code position or a docblock, which neither Psalm's scanner nor the ambient queue above ever sees. `BladeBootstrapper` reads every shadow off disk (not the fresh `ShadowResult`, which does not exist on a warm-manifest run) after both other registrations, tokenizes it (`T_CONSTANT_ENCAPSED_STRING` only, so docblocks and interpolated strings are excluded), and hands the candidates to the Psalm-side adapter, which gates each one on `Config::getComposerFilePathForClassLike()` or an already-declared `class_exists($n, false)`-family check before queueing it with `store_failure=false` — most string literals name no class at all, so nothing is queued unconditionally here, unlike the ambient list (#1505).
- **runtime helper files** are queued whole via `ShadowRegistrar::queueFilesForScanning()` → `Codebase\Scanner::addFileToDeepScan()`, from the list `ApplicationProvider::runtimeDeclaredFunctionFiles()` captured around the boot. A package-style monorepo `include`s its helper file from a provider's `register()`, so nothing in the project references it and Psalm would give it no `FileStorage` at all. This is one half of the #1551 fix; the other is `Blade\RuntimeHelperVisibility` below, and neither works alone.

Both halves degrade rather than throw. Every cause (no `blade.compiler` binding, no view finder, an unwritable cache directory, a Psalm internal that moved) turns the feature off for that run with one warning; per-template compile failures are collected into a single warning with `--debug` detail. `BladeBootstrapper` holds no static state, so it needs no entry in `resetInvocationState()`.

Degradation is all-or-nothing, which decides both the ordering inside `run()` and the handler gates (#1518). The compile pass BUFFERS its template facts on the bootstrapper instead of writing them to the registries as it goes, and `publishTemplateFacts()` replays them only once the run has activated; the fallible registrar calls (`markTemplatesReportable()`, both scanner queues) all precede `registerShadowsForAnalysis()`, the one irreversible step, and `ShadowRegistry` is filled in a `finally` around it, because remap entries only RELOCATE issues while `ContractRegistry` validates call sites and `ViewReferenceRegistry` drives annotation. `boot()` returns whether shadows actually joined the analysis, `initBladeAnalysis()` passes that back to `__invoke()`, and every Blade handler registers on that flag — NOT on config alone.

One consequence worth knowing when touching the template registry: a view name is always an array KEY, and PHP casts a numeric-string key to an int, so `123.blade.php` reaches a `string`-typed consumer as `int(123)` under `strict_types`. `AnnotationWriter` casts its `ViewReferenceRegistry::templates()` key before looking up the contract.

The `ProjectFileInjector` guards (`property_exists`, `is_array`, `catch (Throwable)`, and no `setAccessible()`, which is a no-op since PHP 8.1 and whose deprecation Psalm's error handler promotes to an exception) are what keep a Psalm rename from crashing a run. Re-probe them on each Psalm release, not just each major.

#### Giving a shadow the booted app's function table

Psalm keeps no global function table for ordinary project code: `Functions::functionExists()` answers from the ROOT file's `FileStorage::$declaring_function_ids`, which a file gains entries in only by `require`ing the declaring file, transitively (`Populator`'s `required_file_paths` walk). A scanned `function` becomes globally visible only under `allFunctionsGlobal`, `register_stub_files`, or `register_autoload_files`. A shadow requires nothing and usually names no class, so it reached nothing (#1551).

`Blade\RuntimeHelperVisibility` (`AfterCodebasePopulatedInterface`, registered from `registerHandlers()` on the same `$bladeActive` gate as the remap; `init()` runs in `initBladeAnalysis()`, where the capture is in scope) merges those files' `declaring_function_ids` and `declaring_constants` into every path `ShadowRegistry::shadowPaths()` knows. Constraints worth keeping:

- `+=`, never a replace. A shadow's own entries must win, and `Functions::getStorage()` throws `UnexpectedValueException` rather than declining when a declaring path does not carry the id — so a bad overwrite is a crash, not a false positive. For the same reason an id is only merged when the helper's own `FileStorage::$functions` holds it, and a constant only when `$constants` does.
- The intersection of the boot's declared NAMES (`ApplicationProvider::runtimeDeclaredFunctionIds()`, `runtimeDeclaredConstants()`) with the file's storage, never the whole file. A declaration behind a disabled feature flag or version gate, or nested in an uncalled function, is in that storage without the runtime ever declaring it; merging it would make an unreachable symbol resolvable in every template.
- Shadows only. Merging into an ordinary project `FileStorage` would suppress genuine `UndefinedFunction` across the codebase.
- Anything `Functions::hasStubbedFunction()` already answers is skipped, so a user helper can never outrank a stub's types, and the capture excludes `vendor/` outright.
- `AfterCodebasePopulated`, not earlier: `FileStorageCacheProvider::writeToCache()` runs during scanning, so a merge before that point would persist into the on-disk file-storage cache and leak into runs with Blade off. It runs in the parent before `analyzeFiles()` forks, so workers inherit the mutation by copy-on-write.
- A boot that degrades before providers register captures nothing, so the whole thing no-ops. A boot that throws mid-bootstrap keeps whatever helpers had already been declared: those functions genuinely exist in the process, and gating the capture on a fully clean boot would lose them in exactly the one-bad-config-file scenario the bootstrap tolerance exists for (`ApplicationProvider::runtimeDeclaredFunctionFiles()` documents the same contract).

#### Remapping a shadow issue onto its template

Neither registration makes a finding visible by itself: an issue Psalm raises in a shadow is located in a file that is deliberately unreportable, so it dies in the reportability gate. `Blade\BladeIssueRemapHandler` (`BeforeAddIssueInterface`, registered from `registerHandlers()` only when the compile step activated) is the only channel from shadow analysis to user-visible output.

- `Blade\ShadowRegistry` maps shadow path to template path, line map and per-template-line suppressions. `BladeBootstrapper` fills it for every shadow it registers, including templates fresh enough to skip recompiling (those facts come off the manifest). It is static because Psalm instantiates event handlers itself, so it is reset in `Plugin::resetInvocationState()`.
- On a registry hit the handler rebuilds the issue on the `.blade.php` path (`Blade\ShadowIssueRelocator`), re-emits it through `IssueBuffer::accepts()`, and returns `false` to kill the shadow-path original. `BeforeAddIssue` is the only hook that can do this: Psalm dispatches it before both the reportability gate and `IssueBuffer::isSuppressed()`.
- The rebuild walks the constructor reflectively. 97 of Psalm 7's 320 concrete issue classes declare a required third parameter, so `new $class($message, $location)` throws for them, and inside an event handler that is a finding lost without a trace. A parameter that cannot be resolved declines with `null` (Psalm keeps the shadow-path original, invisible but not swallowed) rather than dropping the issue.
- `accepts()` is handed the suppressions recorded for the mapped template line, which is what makes `{{-- @psalm-suppress X --}}` work: the event carries the issue but not the suppressed-issue list Psalm was about to check it against. An `<issueHandlers>` suppression scoped to the view directory needs nothing extra, because `accepts()` consults `Config::reportIssueInFile()` on the new path itself.
- A shadow line that maps to no template line (the prelude) is re-emitted on line 1 with an ` (unmapped)` suffix. `Mixed*` issues are dropped instead, because the prelude types every unresolved template variable as `mixed` and those findings say nothing about the template.
- A taint flow graph is not a reason to decline: Psalm 7 runs taint by default and emits type and taint issues from the same run, so both kinds have to be relocated. (The `3.x` pipeline does decline there, because Psalm 6 runs taint exclusively and `IssueBuffer::add()` discards non-`Tainted*` issues anyway.)
- A `TaintedInput` carries two further constructor arguments describing how the taint travelled, and both are remapped by `Blade\JourneyRemapper` before the rebuild. The journey ARRAY holds the source chain, each step repositioned onto the template its shadow came from; the journey TEXT also spells out the sink-side hops the array stops short of, so it is rewritten by substituting `file:line:column` descriptors rather than regenerated. A journey crosses files, so the remap resolves a shadow per step (and for the issue's own path) instead of reusing one; steps in ordinary application code are left untouched. A shadow step whose template has no such line declines the whole issue with `null` — a half-remapped journey is worse than none.

#### Validating a call site against a template contract

Behind [`<blade validateViewData="true" />`](../config.md#validateviewdata), `Handlers\Views\ViewContractHandler` (`AfterStatementAnalysisInterface`) reports a declared template variable the call site never passes (`MissingViewVariable`) or one whose value does not satisfy the declared type (`InvalidViewVariableType`). The class is registered from `registerHandlers()` under `bladeActive && (bladeValidateViewData || bladeReportUnusedViewData)` and carries one independent bool per rule, so the two opt-ins share a single walk of the statement; `init()` sets both and `afterStatementAnalysis()` bails only when neither is on.

- `Blade\ContractParser::parseDeclarations()` reads the `{{-- @var --}}` and `@props([...])` declarations from the template source. It is a SIDE CHANNEL: the declarations are deliberately NOT fed into `ShadowCompiler::compile()`, because contract types in the prelude change every shadow's content and its fingerprint. Typing the template body is a separate decision.
- `Blade\ContractRegistry` maps view NAME to contract. `BladeBootstrapper::compileAll()` derives it from the template path relative to its view root (first root winning as `FileViewFinder` does) but only BUFFERS it; the registry is filled by `publishTemplateFacts()` after activation, in insertion order, so the first-root precedence still lives in `register()`. Static for the same reason as `ShadowRegistry`, so it is reset in `Plugin::resetInvocationState()`.
- The contract is persisted as the manifest's sixth slot, so a template fresh enough to skip recompiling still has one. `ShadowManifest::normalizeEntries()` drops any entry that is not seven slots wide, and `normalizeContract()` drops any contract that is not six wide, which self-evicts everything written by an earlier shape at the cost of one recompile.
- `Handlers\Views\ViewCallChain` walks the rendering expression from the OUTERMOST call inwards, collecting the view name and every data contribution in one pass. Outermost-first is the whole point: expression analysis is post-order, so a hook on the inner `view('greeting')` of a `view('greeting')->with('name', $n)` chain would report every declared variable as missing. It refuses on any link it does not model, and `MissingViewVariable` additionally needs the supplied key set to be provably closed. `with()` is read the way Laravel dispatches it, on `is_array($key)` rather than on the argument count, and the whole chain's `with()` calls are deferred until the terminal receiver's role is known, because `SimpleMessage::with($line)` on a `MailMessage` chain binds no template data at all.
- `Handlers\Views\ComponentRenderData` adds what `Illuminate\View\Component::data()` merges in when the rendering expression sits inside a `Component` subclass — public non-static properties with their declared types, public methods (minus `ignoredMethods()`) as `mixed`, and the inherited `$attributes`. They land in `ViewCallChain::$frameworkData`, not `$data`: `supplied()` unions both for the declared-variable checks, `$frameworkData` winning a shared key because `renderComponent()` merges it last, while `UnusedViewData` keeps iterating `$data` alone, or every component render would report one finding per public method. `$except` and an overridden `ignoredMethods()` only REMOVE names, so ignoring them leaves the set a superset — the safe direction; an overridden `data()` can ADD them, so it opens the set instead.


#### Reporting an unread data key

Behind [`<blade reportUnusedViewData="true" />`](../config.md#reportunusedviewdata), the same `ViewContractHandler` reports a data key ([UnusedViewData](../issues/UnusedViewData.md)) the resolved template neither reads nor declares.

- The read set comes from `ContractParser::parseDataContract()`, which is `parseDeclarations()` plus a walk of the COMPILED output. It is the same side channel: the contract is built after `ShadowCompiler::compile()` returns and never reaches it. One shared `walkReads()` feeds both consumers — `TemplateContract`'s set drops locally bound names, this one keeps them, because the question here is "does the template use this name at all". `ContractParser::rawDeclaredNames()` adds the raw `<?php` docblock spelling from the SOURCE as `ViewDataContract::$rawDeclaredVariables`, consumed-only: `ReadSetResolver` and the writer count it as declared, but it never becomes a `ContractVar`, because in a template that spelling is as often a local type hint after an assignment as a stated interface.
- `readsUnknown` is load-bearing. A parse failure, a `$$name`, an `extract()`, or a `compact()` with a non-literal argument makes the set a lower bound, and a lower bound cannot prove a key unused. `get_defined_vars()` is deliberately NOT in that list: Blade compiles it into every `@include`, so treating it as unknown would disable the rule almost everywhere. `@props` and `@aware` compile to `$$name` writes, which is why component templates decline.
- `ViewReferenceCollector::collectDataIncludes()` collects the manifest's seventh slot: the subset of a template's references that inherit its whole scope. Membership is decided by the DATA argument, not the directive — every scope-passing directive compiles it to `array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1])`, whose position shifts with the directive's own optional data array, so the whole argument list is scanned for that shape. `@includeIsolated` and `@each` compile to the same `$__env->` methods but pass no parent scope and are excluded by that gate alone.
- `Blade\ReadSetResolver` closes each template's reads UNION its declared contract vars over that graph, recursively, with a by-reference visited set (a revisit contributes nothing, which is correct for a union and terminates on a cycle). It declines on the first template in the chain whose reads are unknown, whose data-includes hold a dynamic name, or that `ContractRegistry` never claimed.
- `ShadowManifest::isFresh()` takes an `int-mask-of<ShadowManifest::SLOT_*>`: a slot whose collection pass was off when the entry was written is a null, and requiring it forces one recompile on flag flip rather than leaving every template permanently "fresh with nothing collected".
- Emission re-checks `PreludeBuilder::BLADE_OWNED_NAMES` on the PASSED side, because the read-set extraction strips those names as Blade's own — deliberately the full six-name set (`__env`, `errors`, `loop`, `attributes`, `slot`, `component`), not `AMBIENT_TYPES` (the three names the prelude ALWAYS types): a call site passing `'slot'` is never wrong about the template, even for a template where `attributes`/`slot` are typed conditionally or `component` is never typed at all (#1525). It is deliberately not gated on `ViewCallChain::$complete`: an open key set means there may be more keys, not that the ones in hand were not passed.

Everything in the pipeline that reads a Psalm internal whose shape differs between Psalm 6 and Psalm 7 goes through `Blade\PsalmBridge` (`TaintedInput` detection, journey step shape, journey-text descriptor format), each method carrying the `file:line` it was read off in the Psalm this branch requires. Porting the Blade pipeline to the other line is a rewrite of that one file: the `3.x` copy additionally carries a taint-run probe, which Psalm 7 has no use for.

#### Annotating a template from its call sites

`psalm-laravel blade:annotate` ([user docs](../blade.md#annotating-templates)) is the one path on which this plugin writes to the analysed source tree. It is a two-half pass inside an ordinary analysis, registered from `Plugin::registerHandlers()`.

- The gate is `Blade\Annotate\AnnotateRequest::fromEnvironment()`: a control file the CLI put in the child process's environment, carrying the `psalm-laravel-annotate` marker key, on a regular on-disk file (a `data://` URL would otherwise satisfy the marker with no file at all). The marker is what makes the variable safe to leak — a stale shell or a `.envrc` pointing it at any other readable JSON (a `composer.json`, say) would otherwise arm the codemod AND have `publish()` overwrite that file. It is re-checked inside `publish()` AND in `AnnotationWriter::run()` before the first template is written, because the path is named by an environment variable and can be repointed mid-run. No config flag turns the write on, so a plain `vendor/bin/psalm` run cannot reach it even with Blade fully configured; conversely a valid request force-enables Blade for a project that has it off, which is the command's explicit purpose. Annotate mode also forces the `collectDataIncludes` half of the compile pass on, because the read set is what it annotates from.
- `Cli\AnnotateCommand` runs `vendor/bin/psalm` through `proc_open` (the `AnalyzeCommand` shape) and forces `--threads=1`, stripping any `--threads` the caller passed rather than appending a duplicate, which PHP's `getopt` would hand Psalm as an array. Single-threaded is not a preference: statics collected in a forked analysis worker never reach the parent, and the parent is where `AfterAnalysis` runs. Measured on a scratch project, `--threads=4` reaches the writer with **zero** collected call sites. The plugin half does not trust the command for this: `AnnotationCollector::sawAnalysis()` is false in the parent of a forked run, and `AnnotationWriter::run()` then publishes an error instead of writing `mixed` over the whole view tree.
- `Blade\Annotate\AnnotationCollector` (`AfterStatementAnalysis`) records producer types through the same `ViewCallChain::from()` walk `ViewContractHandler` checks call sites with. It answers a type only when every producer of that view agreed on `Union::getId(false)` AND no producer left the data set open — an open set can carry the same key with another type invisibly — and only when the id survives a `Type::parseString()` round trip, carries no template parameter at any depth (a `TypeVisitor` walk — `Union::hasTemplate()` is false for `Collection<int, T>`), and cannot close the Blade comment it is written into.
- `Blade\Annotate\AnnotationWriter` (`AfterAnalysis`) joins `ViewReferenceRegistry::templates()` (paths, published unconditionally) with `ContractRegistry::contractFor()` (declarations and read set), plans per template FILE rather than per view name (a published override is claimed under two names and would otherwise be reported twice), and splices with `Blade\Annotate\TemplateAnnotator`. Insertion only: idempotency comes from re-reading the declarations out of the source, not from a marker, and `TemplateAnnotator` reads them with `ContractParser::VAR_PATTERN` plus `ContractParser::rawDeclaredNames()` — the same two readers the contract itself uses, so neither side can bind a name the other misses. A pattern of its own that bound a different name would append a duplicate declaration on every run: `\w` binds `$men` out of `$menü`.
- `ViewDataContract::$localVariables` exists for this pass alone: every name the compiled body binds for itself — a `@foreach` alias, an assignment target, a closure or arrow-function parameter, a `catch` variable, a `static`/`global` declaration. The read set deliberately KEEPS them (UnusedViewData asks whether a name is used at all), but declaring one reports `MissingViewVariable` at every correct call site, so the writer subtracts them, along with `$rawDeclaredVariables` (already declared, in the other spelling). A `use ($x)` clause is not in the set: it reads the enclosing `$x`. Both ride the contract slot of the shadow manifest; an entry written with a narrower shape is dropped by `normalizeContract()` and recompiled.

#### Debugging a shadow

Shadows live under the `cacheDir` resolved by `PluginConfig::resolveBladeCacheDir()` (default: `blade/` inside the [plugin cache directory](../config.md#cache-directory)). Each source generation gets a file named `sha1($templatePath) . '-' . $fingerprint . '.php'` (`ShadowManifest::shadowPath()`), alongside a single `manifest.php` that records, per shadow, the template path, line map, extends line, source fingerprint, inline suppressions, and the template contract. Delete the whole `cacheDir` to force every template to recompile. `--clear-cache` only removes `$config->getCacheDirectory()`, so it does the same for the default location, which nests under it, but not for a custom `cacheDir` set outside Psalm's own cache directory.

Pruning drops superseded manifest entries but retains their generation files because another invocation may still be reading them. Clear the cache directory when no analyses are running to reclaim those files. Deleted-template pruning retains its existing unlink behavior.

The source fingerprint hashes the template text, the marker pass version, the Laravel framework version, the plugin version, and (see `Blade\CompilerEnvironment::describe()`) the booted `BladeCompiler`'s own registration surface: custom directives, `if()` conditions, extensions, precompilers, echo handling, component maps, and a compiler subclass swap. Changing one of these invalidates every cached shadow once. What it does NOT reach: runtime state read from inside a directive's own body (`config()`, a global, a class static), and component metadata Laravel resolves live during compilation (a class component's constructor signature, an anonymous-component template that did not exist yet). Delete the `cacheDir` by hand to pick up a change like that.

To inspect a shadow, run once so the cache is warm, then find the file by hashing the template's real (absolute) path, not its project-relative path: `findTemplates()` registers each template by `SplFileInfo::getRealPath()`, and `ShadowManifest::shadowPath()` hashes that same string. For example:

```bash
php -r "echo sha1(realpath('resources/views/profile.blade.php')), \"\n\";"
```

Open the `<hash>-<fingerprint>.php` referenced by `manifest.php` in the `cacheDir` directly: it is plain PHP, with the `PreludeBuilder` output as a leading docblock block followed by the compiled Blade output.

Two flags matter when working on this pipeline:

* `--debug` on the Psalm invocation prints the individual compile failure for every skipped template; without it, `BladeBootstrapper` only aggregates them into one warning naming up to three paths.
* `--threads=1 --no-cache` when stepping through `BladeIssueRemapHandler` or `JourneyRemapper` with `var_dump()`: forked worker processes swallow output, and a warm shadow cache skips the compile path you are trying to observe.

## Getting started

```bash
git clone git@github.com:psalm/psalm-plugin-laravel.git
cd psalm-plugin-laravel
composer install
composer test        # lint + psalm + unit + type tests
```

## Running tests

```bash
composer test          # full suite (lint + psalm + unit + type)
composer test:unit     # PHPUnit unit tests only
composer test:type     # type tests only (psalm-tester)
composer psalm         # self-analysis of plugin source
composer test:app      # creates a fresh Laravel project, scaffolds common class types (`make:xxx`), installs the plugin, and runs Psalm on the result
LARAVEL_INSTALLER_VERSION=12.12.2 composer test:app # run over a specific Laravel version

# single test file
./vendor/bin/phpunit tests/Unit/PluginConfigTest.php
./vendor/bin/phpunit --filter=AuthTest tests/Type/
```

### Measuring a PR on real-world apps (`/psalm-delta`)

Maintainers can comment `/psalm-delta` on a PR to run the plugin's base and head on the apps in [`bin/ci/test-apps.yml`](../../bin/ci/test-apps.yml) and get a sticky comment with the per-app issue delta. It is informational and never fails the PR.

- `/psalm-delta` runs the `default` group.
- `/psalm-delta octane vito` adds group tags and app names to `default` (spaces or commas). Pick the groups that exercise your change, e.g. `ai` for `laravel/ai` stubs, `blade` for view resolution or view taint, or `filament` for Filament-heavy code.
- `/psalm-delta all` runs every app; `/psalm-delta help` replies with the groups and their apps.
- A token starting with `--` is a flag for `psalm-laravel analyze` on both sides of every selected app, e.g. `/psalm-delta blade --blade` runs `default` plus the `blade` group with Blade template analysis on. Only flags declared under `flags:` in `bin/ci/test-apps.yml` are accepted; add one there to allow it. A flag the base plugin predates crashes the base side.

To reproduce locally (needs `yq`), run `bash bin/ci/delta.sh --apps "octane vito" <pr-branch>` (flags go in the same string: `--apps "blade --blade"`).

## Code style

- PER Coding Style 3.0 (powered by php-cs-fixer: run `composer cs` to apply fixes)
- Explain decisions and ideas in comments

```bash
composer cs     # auto-fix style issues
composer rector # run rector refactoring
```

## How to add a stub

Stubs override Laravel's type signatures. Place them in:

- `stubs/common/` — shared across Laravel versions (includes both type stubs and taint annotations)
- `stubs/<version>/` — version-specific overrides, loaded when the installed Laravel is `>=` the dir name (`version_compare`). Both major-only (`stubs/13/`) and patch-level (`stubs/13.8.0/`) names work
- `stubs/integrations/<package>/` — optional stubs for third-party packages, gated on the package being installed. Carbon uses the `shared/` + `pre-3.12/` conditional-directory pattern in `src/Stubs/CarbonStubProvider.php`; `laravel-ai/` is one flat directory, see [the gate](#the-laravelai-integration-gate).

Rules:
- Verify signatures against actual Laravel code (not against Laravel PHPDoc or method signatures)
- Add a type test in `tests/Type/tests/` to prevent regression
- For taint annotations, see [Taint Analysis Stubs](taint-analysis.md)
- A closure parameter whose callback runs bound to another object (`Artisan::command('x', function () { $this->comment(); })`) is typed with `@param-closure-this \Foo $callback` on the stubbed method, not a handler. A facade needs a real static method in its stub for this: `@method static` cannot carry the tag, and `FacadeStubPrecedenceHandler` drops the generated pseudo-method when the stub declares a real one. A `static` closure stays `InvalidScope`, which matches runtime.

### The `laravel/ai` integration gate

`LaravelAiIntegration::isEnabled()` (laravel/ai `>=1.0.0 <2.0.0`) is the only version check. `stubs/integrations/laravel-ai/` is one flat tree of namespace subdirectories mirroring `Laravel\Ai\` (`Contracts/`, `Responses/`, ...): a new stub goes at the path of the class it redeclares. Four things load behind `Plugin::laravelAiIntegrationEnabled()` and must stay in lockstep, because a partial set is a silent half-integration:

1. The stubs, via `Plugin::optionalIntegrationStubs()` / `StubFileFinder::integrationStubs()`.
2. `Handlers\Ai\LlmOutputTaintHandler` (`Plugin::registerHandlers()`): property-read sources that docblocks cannot express ([why](taint-analysis.md#property-source-pattern-response-text-handler-required)).
3. `Handlers\Ai\PromptGuardTaintHandler` (`Plugin::registerHandlers()`): exempts a `prompt()` / `stream()` call site whose agent middleware `handle()` carries `@psalm-taint-escape llm_prompt` ([mechanics](taint-analysis.md#how-the-prompt-guard-exemption-reads-an-escape-annotation)). Emission-time and stateless, so it needs no `resetInvocationState()` entry.
4. `Internal\PromptInjectionIssuePolicy` (`Plugin::__invoke()`): keeps Psalm's `TaintedLlmPrompt` error by default and suppresses it only for `<findPromptInjection value="false" />`. Without the stubs it could suppress a project's own `llm_prompt` annotations.

A new gated site calls `laravelAiIntegrationEnabled()` instead of copying the version check. A new integration follows the same shape: one shared `isInstalled()` + `satisfies()` gate used at every call site, stubs under a new `stubs/integrations/<package>/`. `LaravelAiIntegration::diagnostic()` reports the state, e.g. `enabled (laravel/ai 1.0.0; requires >=1.0.0 <2.0.0)`.

### Stub merging: how Psalm combines annotations

When **multiple stub files declare the same method on the same class**, Psalm reuses a single MethodStorage object and re-applies docblock parsing. The merging rules differ by annotation kind:

- **Type annotations** (`@return`, `@param`): last-loaded file wins (direct assignment `=`).
- **Parameter-level taint annotations** (`@psalm-taint-sink`, `@psalm-assert-untainted`): last-loaded file wins. Each re-declaration rebuilds the parameter storages (`FunctionLikeNodeScanner` calls `setParams([])`), so sinks from an earlier file are dropped, not OR-ed. Verified on Psalm 7.0.0-rc1: an `html` sink in one stub plus an `sql` sink on the same parameter in a later stub reports only `TaintedSql`.
- **Method-level taint annotations** (`@psalm-taint-source`, `@psalm-taint-escape`, `@psalm-taint-unescape`): all files accumulate (bitwise OR `|=` on the reused MethodStorage).

Splitting annotations for the same method across two stub files is therefore fragile: which type and which sinks survive depends on load order. Always put all of them in the same file, and have an override restate every `@psalm-taint-sink` of the declaration it replaces.

When a **class stub and a trait stub** both declare the same method, Psalm creates **separate** MethodStorage objects -- one per class/trait. There is no cross-merging: if `Connection.phpstub` overrides a method defined in `ManagesTransactions.phpstub`, the trait's annotations (including taints) are ignored for that method. To keep both type and taint annotations, put them on the class stub.

Registration order (`Plugin::registerStubs()`): all `common` files, then version dirs ascending (`array_merge`). Since type annotations are last-loaded-wins, this order (not alphabetical path) decides overrides.

A stub that re-declares a class merges into the class's vendor file only when Psalm scans the vendor file first. Psalm records a scanned stub as the class's file and then never queues the vendor file. A class that nothing names before stubs load therefore ends up with only its stubbed members (#1616, upstream vimeo/psalm#12075). `Plugin::registerStubs()` queues every class a plugin stub declares (`StubFileFinder::declaredClassLikes()`) during plugin init, which runs before Psalm's main scan, so a partial stub can rely on its unstubbed vendor members resolving.

#### `laravel/ai` parity checker, `@since` and `@stub-waive`

Psalm cannot see stub-versus-vendor drift ([why](taint-analysis.md#optional-third-party-integrations-stubsintegrationspackage)), so `bin/ci/check-laravel-ai-stub-parity.php` diffs the stubs against the installed package (no argument scans all of `stubs/integrations/laravel-ai/`). `tests/Unit/Ci/LaravelAiStubParityCheckerTest.php` pins the script's own checks.

- It compares native parameter/return types and each class's `implements` list (minus what the real parent supplies). Docblock narrowing beyond native types is expected, not drift.
- A real member the stub omits is reported as a taint-review tripwire, not a correctness failure: Psalm merges it in from the vendor class, but with no taint annotations.
- Properties need no gate: a stub property the installed class lacks is never reported, and a trait-provided property is covered by mirroring the class's `use` clause (see `Tools/SimilaritySearch.phpstub`).
- A real member the stub omits fails the run unless the class docblock waives it with `@stub-waive` (below); nothing is allow-listed in the script.

**`@since X.Y.Z`** tags anything a 1.x minor added after the `1.0.0` floor, so the checker skips it while the installed release is older. Method: tag its docblock. The tag also gates a method the older release already has but whose signature a later minor changed (e.g. an appended parameter): the checker skips that method's whole signature diff, and does not count it as compared, while installed < tag. `implements` / interface `extends` entry: one `@since X.Y.Z implements \Fully\Qualified\Interface` line per interface in the class docblock. Whole class: a standalone `@since X.Y.Z` line in its class docblock (reported as version-gated, not compared). A name or class the installed release lacks, or a class with no tag, is still reported.

**`@stub-waive`** lets a stub leave out a member that carries no taint or type value instead of restating it. In the class docblock, one line per member: `@stub-waive withMaxTokens() <reason>`, `@stub-waive $runtimeTools <reason>` or `@stub-waive implements \Fully\Qualified\Interface <reason>`. A trailing `*` waives a family by name prefix: `@stub-waive assert*() <reason>` (or `$prefix*` for properties). The `*` is a prefix marker only at the very end of a name (`*Timeout()` and `get*Timeout()` are not targets), and a bare `*` is an error, because a blanket waiver would delete the tripwire this tag exists to keep. Wildcards belong to the class docblock; in a docblock of a member the stub does declare, a bare `@stub-waive <reason>` instead waives drift of that member's own signature. The reason is mandatory (a tag without one fails the run), every waived member is still printed on its own line with its reason under "Waived by @stub-waive" (also when a wildcard covered it), and a waiver or wildcard that matches nothing (the member reappeared in the stub or left the vendor class) is reported as a `::warning::` so it gets deleted.

**Waive sparingly: the stubs are deliberately NOT partial.** The completeness rule is what found the real gaps in this integration's security review (`Promptable::withMessages()`, the classification question criteria and `AssistantMessage::__construct()` were each missing, and each was caught because a member was absent or unstubbed). A new member of a prompt-facing or response class is where a new sink or source lands, so those classes must keep failing CI until someone has reviewed the member. Waive a member only when its parameters cannot carry text toward a model and its return cannot carry text from one. Test doubles qualify (`fake()`, `isFaked()`, `assert*()` on `Audio`, `Image`, `Embeddings`, `Reranking`, `Classification` and `Promptable`), and so do builder setters that take only scalars or enums (`timeout()`, `limit()`, `withMaxTokens()`). Anything touching prompts, messages, tools, documents or responses does not qualify, even when it carries no annotation today: restate it. Do not widen a wildcard to cover a family you have not read member by member, and check each matched member against `vendor/laravel/ai/src` first, since a prefix also matches methods added later.

### Version-specific overrides (conditional stub loading)

A file in a version dir (`stubs/13.16.0/...`, loaded when installed Laravel `>=` that version) overrides the same-named `common` file **per method**. Multiple version dirs cascade ascending: per method, the highest dir `<=` the installed version wins; a method it doesn't redeclare falls through to lower dirs, then `common`. (Verified: with `common` + `12.6.0` + `12.8.0` all declaring `MessageBag::has`, `12.8.0` won; `missing()` declared only in `12.6.0` survived; `isEmpty()` came from `common`.)

Authoring an override:

- Declare only the changed methods; the rest merge from `common`.
- Repeat the `implements` clause (interface stubs: the `extends` list) verbatim, because a re-declaration resets Psalm's `class_implements` / `parent_interfaces` and silently strips contracts. Class `extends` and trait `use` survive, but copy the full header anyway to stay diffable against Laravel source.
- Types and parameter sinks replace, method-level taints accumulate (see "Stub merging"), so restate every `@psalm-taint-sink` of the method you override.

**Common vs version dir.** Return narrowing that holds across all versions (Laravel only improved its annotation) goes in `common`. A parameter widened by behavior present only in a newer Laravel (e.g. `firstOrNew`'s `values` taking `\Closure|array` only on 13) must go in the version dir: widening `common` would tell Psalm a call is valid that fatals at runtime on older versions (silent false negative).

### Testing version-specific stubs

A type test that asserts a `stubs/<version>/` override would fail on the lower cells of the CI matrix (`.github/workflows/tests.yml` runs `test:type` over Laravel `^13.3` and `^12.20`), because the override does not load on the older Laravel. Gate such a test with a `--SKIPIF--` section so it runs only where the stub applies:

```
--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.42.0');
--FILE--
... assertion of the version-specific behavior ...
```

`LaravelVersion::skipBelow($version)` skips when the installed Laravel is older than the stub dir (`skipFrom($version)` does the reverse for behavior only on older lines). The `--SKIPIF--` script runs in a bare process from the project root, so it requires the autoloader via `getcwd()`. See `tests/Type/tests/Http/PendingRequestTest.phpt` for a worked example (the async HTTP client types are 12.42+).

## How to add a handler

Handlers implement Psalm event interfaces to override type inference.
Create the handler class in the appropriate `src/Handlers/` subdirectory, then register it in `Plugin::registerHandlers()`.
`CollectionGroupByKeyByHandler` specializes literal model attributes for collection `groupBy()` and `keyBy()` calls; unsupported forms defer to Laravel's stubs.
`ConditionableWhenHandler` narrows the return type of `Conditionable::when()`/`unless()`. `ConditionableCallbackParamsHandler` types their closure-literal callback params from the receiver and the `$value` narrowed to truthy/falsy. It is a params provider registered per host class, because params providers dispatch on the called class, not on the declaring trait.
Most taint handlers live under the Laravel feature directory whose API they cover (e.g. `Handlers/Eloquent/WhereColumnTaintHandler`); a stop-gap for an upstream Psalm bug that applies to every call site regardless of Laravel domain goes in `Handlers/Taint/` instead (e.g. `NamedArgumentTaintHandler`, variadic named arguments, vimeo/psalm#12251 and #12252; see decisions.md).

### Experimental issue lifecycle

Experimental status changes an issue's default severity, never whether its handler or type inference runs. Keep the experimental policy list in `ExperimentalIssuePolicy` as the single source of truth; `PromptInjectionIssuePolicy` is a separate, integration-gated opt-out for the stable `TaintedLlmPrompt` issue. Both policies delegate to `Internal\DefaultIssueLevels`, which implements the safe setter Psalm lacks: it applies a default only when the project has no handler for that issue, and recognizes its own earlier default by object identity so a later invocation can refresh it when the flag flips. Any explicit issue handler owns the complete reporting policy, including its base level and scoped filters.

1. Introduce an experimental issue: register its handler normally, add its issue type to that internal list, and let the policy default it to `info` (or `error` when `<experimental value="true" />` is configured).
2. Graduate an issue: remove it from the internal list; it becomes a normal stable error.
3. Withdraw an issue: remove its handler and issue class.

Do not add user-facing feature names or handler-registration gates. Do not overwrite or merge explicit issue handlers.

### Psalm hooks used by the plugin

Psalm processes code in phases. Each hook fires at a specific phase and has different data available.
Analysis hooks are hot paths — they fire on every matching expression. Scanning hooks fire once per class or once total.
Source of truth for which handler implements which hook: `Plugin::registerHandlers()` plus each handler's `implements` clause.

```mermaid
flowchart TD
    subgraph P1["Phase 1 — Scanning (per class/trait/interface)"]
        A1["AfterClassLikeVisitInterface"]
    end

    subgraph P2["Phase 2 — Codebase populated (fires once)"]
        B1["AfterCodebasePopulatedInterface"]
    end

    subgraph P3["Phase 3 — Analysis (hot path, per file)"]
        direction TB
        C1["BeforeFileAnalysisInterface"] --> C2

        subgraph LOOP["repeats per statement / expression"]
            direction TB
            C2["BeforeStatementAnalysisInterface"] --> C3["BeforeExpressionAnalysisInterface"]
            C3 --> C4["Type/taint providers on the matched expression:
            FunctionReturnTypeProviderInterface
            MethodReturnTypeProviderInterface
            MethodParamsProviderInterface
            MethodExistenceProviderInterface
            MethodVisibilityProviderInterface
            property existence/type/visibility providers
            AddTaintsInterface / RemoveTaintsInterface"]
            C4 --> C5["AfterExpressionAnalysisInterface"]
            C5 --> C6["AfterMethodCallAnalysisInterface"]
        end

        C6 --> C7["AfterFunctionLikeAnalysisInterface"]
        C7 --> C8["AfterFileAnalysisInterface"]
    end

    subgraph P3B["Phase 3b — Taint graph resolved (main process, after the workers exit)"]
        E1["BeforeAddIssueInterface
        fires here for every taint issue,
        and inside the workers above for every type issue"]
    end

    subgraph P4["Phase 4 — Run complete (fires once)"]
        D1["AfterAnalysisInterface"]
    end

    P1 --> P2 --> P3 --> P3B --> P4
```

### Registering handlers

There are two ways to register:

1. **Class-level** (most handlers): implement the interface, register via `$registration->registerHooksFromClass(MyHandler::class)` in `Plugin::registerHandlers()`
2. **Closure-level** (model property handlers): register via `$providers->property_type_provider->registerClosure(...)` — used by `ModelRegistrationHandler` to bind property handlers per-model after codebase is populated

Every class registration keeps the matching `require_once` beside
`registerHooksFromClass()`.

`ModelAggregateLoadHandler` (`AfterExpressionAnalysisInterface`) is the reference for flow facts: after a literal `$m->loadCount('x')` or `$m = M::withCount('x')->firstOrFail()` it writes `$context->vars_in_scope['$m->x_count']`, which Psalm's property fetch reads before any property provider, and it overrides the node type of a directly fetched chain (`M::withCount('x')->firstOrFail()->x_count`). Its only state is the set of aliases it recorded per variable id, so `$m->refresh()` can drop those facts without touching user narrowings; `reset()` clears it from `Plugin::resetInvocationState()`. It gates on the node class and method name before touching the codebase and declines (`null`) on anything not proven. See [Architecture Decisions](decisions.md), "Aggregate accessor proof".

#### Exempting one call site from a stub's taint sink

Narrow the finding at emission time, not in the taint graph.
`ResponseFactoryTaintHandler` is the reference shape: it implements only
`BeforeAddIssueInterface`, matches the issue's journey tail against the sink's
labels, re-checks the call site, and returns `false` to drop the finding. Graph
level removal (`RemoveTaintsInterface`) cannot express "this call site only" and
leaks onto shared flow edges; [Architecture Decisions](decisions.md) records why,
along with the alternatives already ruled out.

Such a handler has to be stateless, because the analysis hooks run in the worker
processes and taint issues are emitted only after those exit. Re-derive every fact
from the issue plus `Codebase::getStatementsForFile()`, which reads the parser
cache. The hook fires for every issue in the run, so bail on the issue class first
and only then do anything expensive. Every uncertain path returns `null` and keeps
the finding.

See [Architecture Decisions](decisions.md) for design rationale, [Laravel Magic Call Patterns](laravel-magic-call-patterns.md) for how Laravel's __call/__callStatic chains work, [Psalm Type Syntax](types.md), [Docblock Annotations](annotations.md) and [Purity and Capabilities](purity.md) for Psalm references, and [Debugging with Xdebug](xdebug.md) for stepping through handler code.

## External resources

- [Authoring Psalm Plugins](https://psalm.dev/docs/running_psalm/plugins/authoring_plugins/)
