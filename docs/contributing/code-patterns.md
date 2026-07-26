---
title: Code Patterns
parent: Contributing
nav_order: 7
---

# Code patterns

Recurring practices mined from this codebase (handlers, stubs, tests) in 2026-07, each with the evidence that makes it a practice rather than an anecdote. Evidence cites files and symbols, not line numbers, so grep for the named method when a reference feels stale. Read this before writing a handler, stub, or test. The rationale behind many of these lives in [Architecture Decisions](decisions.md).

## Choosing the mechanism

Pick by failure shape, not by escalation. Stubs are the default: prefer one wherever a docblock can express the semantics, since a stub carries zero lifecycle surface (see the principle in decisions.md):

| Failure shape | Mechanism |
|---|---|
| Static semantics, expressible in a docblock | stub in `stubs/common/` |
| Behavior valid only on newer Laravel | stub in `stubs/<version>/` (widening `common` is a silent false negative on the older floor, see the `SortDirection` note in `stubs/13.8.0/Database/Query/Builder.phpstub`) |
| Needs call-site context, argument flow, or config | handler |
| Whole-codebase per-model facts (schema, casts, relations) | `ModelMetadataRegistry` via `AfterCodebasePopulated` |

When every stub shape you can write is blocked by an upstream Psalm limitation, stop and record the probed alternatives plus the chosen trade-off in `decisions.md`, and pin a regression test to that decision. Without the record, later contributors re-probe the same dead ends or "fix" a working workaround.

## Handlers

- **Decline with `null`; never emit a guessed type.** A non-null answer permanently overrides Psalm's own inference and its own issues; declining is the only way a typo still reaches `UndefinedMagicMethod` and a stub still wins. (`CacheManagerReturnTypeHandler::getMethodReturnType()`; 35 declines in `ValidatedTypeHandler` alone.)
- **Gate a per-class method provider on the method name before anything else.** Registration is per class, so Psalm offers the provider every method on that receiver; un-gated providers rewrite unrelated methods (the #1075 `ContainerHandler` regression).
- **Order per-expression bails cheapest first**: AST `instanceof`, then `Identifier` name, then a `strtolower` compare against a const map, and only then any `$codebase` lookup. Taint handlers additionally bail when `$source->taint_flow_graph` is absent so non-taint runs pay nothing. (`OctaneIncompatibleBindingHandler`, `ImplicitQueryBuilderCallHandler`.)
- **Resolve the receiver to exactly one known class or bail.** Union receivers, mixed atomics, and userland subclasses turn a helpful narrowing into a false positive on correct code, the plugin's dominant failure mode. Taint strips and sinks gate on the actual receiver, never just the argument shape (#1306). (`ImplicitQueryBuilderCallHandler`, `WhereColumnTaintHandler`.)
- **Answering for a facade `@method` pseudo-method requires implementing BOTH the return-type and params providers, gated on the same `methodExists()` lookup** (`with_pseudo: false` discriminates real from pseudo). A return without params drives Psalm into `getMethodParams()` and fatals. (`CacheManagerReturnTypeHandler` and the other dual-interface handlers.)
- **Static state that carries booted-app or per-run facts gets `public static function reset(): void`, registered in `Plugin::resetInvocationState()`**, which runs before boot on every invocation. The plugin can be re-invoked in one process (language server, tests); without the reset a previous app's aliases and config leak into the next run. Process-invariant caches need no reset, and per-file scratch state may instead clear inside its own hook. (39 `reset()` definitions repo-wide.)
- **Storage lookups that can fire before a class is scanned or populated (`classlike_storage_provider->get()`, `classExtends()`, `getDeclaringMethodId()`) sit in `catch (\InvalidArgumentException|UnpopulatedClasslikeException)`** with the failure treated as "not proven". Storage can be missing or unpopulated depending on scan order, and an uncaught throw aborts the whole Psalm run; a lookup guaranteed to run post-population may skip the catch. (38 files catch the first, 10 also the second.)
- **Register facade receivers three ways**: the service class, the hardcoded canonical facade, and `FacadeMapProvider::getFacadeClasses()` for the app's configured root aliases. Psalm dispatches providers by exact FQCN, and apps that trim `Facade::defaultAliases()` would otherwise lose the handler. (`CacheManagerReturnTypeHandler::getClassLikeNames()`; 8 handlers.)
- **Every `IssueBuffer::accepts()` passes the suppressed-issues list** (`$source->getSuppressedIssues()` in statement hooks, `$storage->suppressed_issues` in class-visit hooks), or the custom issue is unsuppressable by `@psalm-suppress`. (13 of 13 call sites.)
- **Gate definitive negative judgments on `ModelMetadata::isComplete(SECTION_*)`.** Emitting an issue ("this attribute does not exist") requires the relevant section to be complete; positive enrichment may consume partial metadata. A set section bit is authoritative even when the section is empty, and issue-emitting consumers additionally bail on an empty schema map because dynamic migrations can hide columns. (`UnknownModelAttributeHandler` gates; `BuilderScopeHandler` reads partial data.)
- **Precompute lookup tables as `array<lowercase-string, true>` class constants and memoize per-class results in a `reset()`-cleared static.** Hooks fire once per expression across the project. Mark pure helpers `@psalm-pure` / `@psalm-external-mutation-free` or raw CI psalm fails on `MissingPureAnnotation`. (`TimingUnsafeComparisonHandler`.)
- **The class docblock answers "why is this a handler and not a stub"**, cites the issue number, and cross-references hand-duplicated twin code with a "keep in sync" note. It is the most-repeated review question. (45 handler files cite an issue.)

## Stubs

- **Declare deliberately weak types rather than inventing precision.** `Query\Builder` leaves `min()`/`max()` unstubbed (reflected `mixed`) and `Relation.phpstub` mirrors that with an explicit `@return mixed` so `BuilderAggregateHandler` can narrow known columns later; the carbon stubs leave methods entirely undeclared so reflection of the real class fills them. A confident-but-wrong docblock is worse than `mixed`.
- **Fluent accumulators get fresh template parameters plus `@psalm-this-out`**, not the class's own invariant templates: `collect()->push($x)` starts at `Collection<never, never>` and must widen per call. (`Collection.phpstub` `merge`/`push`/`put`.)
- **Before generalizing a template return, instrument a dump of what Psalm's Populator actually stores** (`template_extended_params` on a real fixture) instead of assuming the ancestry shape; plausible-looking code silently no-ops on the wrong storage keys. (Verified pattern in the #1287/#1295 fix chain.)
- **After fixing one member of a family, sweep the siblings by reusing the same resolver method**, never by duplicating logic: the #1287 Builder gap repeated in Collections (#1295) and relations (#1294), each fixed by pointing at the shared resolver.

## Tests

- **A phpt exercising taint or an opt-in rule needs an `--ARGS--` section before `--FILE--`** (`--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis`, or one of the alternate configs `psalm-with-optin-custom-issues.xml`, `psalm-experimental-issues.xml`, `psalm-sealed.xml`, `psalm-no-dynamic-where.xml`). Without it the test runs under plain defaults and a "Safe*" negative test passes vacuously.
- **Write one comprehensive "must stay silent" phpt per rule** enumerating every syntax shape that should defer, each line commented with the reason. A regression then names the exact shape that broke. (`tests/Type/tests/Relation/UndefinedModelRelationNoFalsePositivesTest.phpt`.)
- **Name taint tests by outcome** (`Safe*`, `Tainted*`) and suffix a deliberately accepted soundness gap with `KnownLimitation`, its docblock pointing at the source class's caveats section. Distinguishes reviewed trade-offs from untested holes. (`tests/Type/tests/TaintAnalysis/SafeInlineValidateReassignmentKnownLimitation.phpt`.)
- **Unit tests gate version-dependent behavior on a capability probe** (`class_exists(...)` or a behavioral check, then `markTestSkipped()`), never a hardcoded version number; phpt files use `--SKIPIF--` with `LaravelVersion` instead. (13+ gates in `ModelMetadataRegistryTest` alone.)
- **Anything unobservable in-process (issue emission, STDERR writes, whole-project rules) forks a real `vendor/bin/psalm` via Symfony Process** against a self-contained fixture: `run()` not `mustRun()` (Psalm exits non-zero on findings), `--output-format=json --threads=1 --no-cache`, and the fixture ships a private-namespace `spl_autoload_register`, never the package's own autoloader. Embed the captured output in assertion failure messages so CI logs are self-sufficient. (`UnknownModelAttributeEmissionTest`, `tests/Unit/Handlers/Fixtures/*/autoload.php`.)
- **Reset handler statics via `ReflectionProperty` in `setUp()` / `tearDown()`, all interdependent statics together**, or tests become order-dependent flakes. (`CustomBuilderDetectionTest`.)
- **Pair every positive branch assertion with a negative one proving the other branch's output is absent**; a regressed detector can satisfy a lone "contains" via its fallback path. (`InitCommandTest`.)
- **Coverage is organized by testing layer, not by mirroring `src/`**: pure type-inference handlers (Cache, Collections, Config, ...) have no `tests/Unit/Handlers/<Dir>` and are covered by feature-named phpt files instead; PHPUnit dirs exist only for handlers with stateful internal logic.
