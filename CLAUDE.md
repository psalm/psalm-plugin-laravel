# psalm-plugin-laravel

Psalm plugin for Laravel. It boots a real Laravel application (the analyzed project's own `bootstrap/app.php`, with Orchestra Testbench as the package fallback), then hooks Psalm's event system and registers stubs to type Laravel's magic. It also ships taint sources, sinks, and escapes for security analysis.

Active majors:
- `master` is 4.x (PHP 8.2+, Laravel `^12.14 || ^13.3`, Psalm 7 beta)
- `3.x` is the Psalm 6 line (Laravel `^11.35+`), backports only

Taint: Psalm 6 reports it only in a separate `--taint-analysis` run (type issues suppressed there); Psalm 7 emits both in one run when enabled (`runTaintAnalysis="true"` or `--taint-analysis`, default off, including self-analysis here) on a rewritten engine with different internals.

## Read before re-deriving

These documents are maintained and source-verified. Consult them before exploring source; prefer them over re-deriving from code.

| Topic | Where |
|---|---|
| How to add a stub or handler; boot flow, hook phases, stub layout/merging, version dirs, SKIPIF gating, experimental issue lifecycle | `docs/contributing/README.md` |
| Design rationale: handler loading, producer narrowing, suppression, version policy, taint philosophy, performance budget | `docs/contributing/decisions.md` |
| How Laravel `__call` / `__callStatic` / macros / forwarding resolve, and the handlers modeling them | `docs/contributing/laravel-magic-call-patterns.md` |
| Taint annotation authoring | `docs/contributing/taint-analysis.md` |
| Psalm annotation syntax reference | `docs/contributing/types.md` |
| Keeping self-analysis at 100% type coverage | `docs/contributing/type-coverage.md` |
| Handler, stub, and test authoring patterns (mined from this codebase, with evidence) | `docs/contributing/code-patterns.md` |
| Type test (.phpt) format and assertions | `tests/Type/README.md` |
| Application fixture and archetype models (reuse before adding) | `tests/Application/README.md` |
| Custom issue documentation, one page per `src/Issues/` class | `docs/issues/<Name>.md` + `docs/issues/index.md` |
| Plugin XML config flags (opt-in issues, `columnFallback`, ...) | `docs/config.md` |
| Step-debugging a handler | `docs/contributing/xdebug.md` |

Ground truth for stub signatures is Laravel source in `vendor/laravel/framework/`, never Laravel's own PHPDoc and never another tool's stubs.

## Commands

Use these forms by default (verbose output only when debugging):

```bash
composer test:unit -- --no-progress --colors=never --display-errors --display-warnings  # PHPUnit unit tests
composer test:type -- --no-progress                                    # type tests (psalm-tester)
composer test:app                                                      # plugin on a fresh Laravel app
composer psalm -- --no-progress --no-suggestions --output-format=compact  # self-analysis
composer cs                                                            # auto-fix code style (quiet flags baked in)
composer rector -- --no-progress-bar --no-ansi                         # rector refactoring

# single test file, cheaper than the suite
./vendor/bin/phpunit tests/Unit/PluginConfigTest.php
./vendor/bin/phpunit --filter=AuthTest tests/Type/
```

Gotchas:

- The final pre-commit psalm gate must DROP `--no-suggestions` to match CI, which runs bare `psalm` (`.github/workflows/psalm.yml`) and reports what the flag hides locally. Keep the flag for iteration only.
- Full local `composer test:type` can flake with mass class-not-found fatals (parallel batch runner). An isolated `--filter` run passing is the local signal; CI validates the full suite.
- `composer test:type` runs a phpt convention check BEFORE PHPUnit and aborts the whole suite on any `--EXPECT--` section or hardcoded `on line N` under `tests/Type/tests`. Use `--EXPECTF--` with `on line %d`.

## Git and PRs

- Base PRs on `master`. Psalm-6-only bugs base on `3.x`.
- The `style: auto-fix` workflow commits style fixes back to pushed branches. Run `composer rector` and `composer cs` locally BEFORE pushing, and `git pull --ff-only` before any further local edits after a push.
- Worktrees: the symlinked `vendor/` PSR-4 autoloader points at the primary checkout's `src/` and `stubs/`. Edits in a worktree are invisible to Psalm until `composer install` runs inside that worktree. Write and Edit tools must target worktree-absolute paths, never the primary root.
- Commits follow Conventional Commits. The subject describes the change, not the issue it closes; issue ref is required in PR body and optional in commit message body.

## Hard rules

Each rule exists because violating it shipped a bug. The pointer holds the full story.

1. Every handler in `Plugin::registerHandlers()` keeps its paired `require_once`. Rationale: decisions.md, "Class Loading and Discovery".
2. A stub that re-declares a class must copy the `extends` / `implements` / `use` header verbatim from Laravel source. Psalm wipes the reflected interface list on re-declaration. See "Stub merging" in the contributing README.
3. In template positions of relation stubs (the declaring-model argument of `HasRelationships` factory returns, the chainable branch of conditional returns), write `static`, never `$this`: Psalm does not late-static-substitute `$this` there. Plain fluent `@return $this` on non-generic methods is fine. Keep `TDeclaringModel` covariant (`@template-covariant`); do not make it invariant to match other tools. Full rationale: docblock of `stubs/common/Database/Eloquent/Relations/Relation.phpstub` and psalm/psalm-plugin-laravel#913.
4. Across stub files, type annotations replace (last loaded wins) while taint annotations accumulate. Keep both kinds for one method in the SAME file.
5. A regression test must fail before the fix. For trivial stub syncs a write-then-delete throwaway test suffices; commit a type test for the fragile cases (conditional returns, template tricks, version-gated behavior). New narrowing handlers need positive AND negative coverage (handler declines, fallback stays intact).
6. Plugin self-analysis has no baseline and must never gain one. The only baseline is the Application-fixture snapshot `tests/Application/laravel-test-psalm-baseline.xml` (refresh via `tests/Application/laravel-test.sh -u`). Never `@psalm-suppress` before trying hard to fix the issue at its source.
7. Grep the diff for `CLAUDE` before committing. Comments and docs state facts directly and never cite agent instruction files.

## Writing code

Pattern catalog with evidence: `docs/contributing/code-patterns.md`. Non-negotiables:

- Pick the mechanism by failure shape, stubs first: docblock-expressible semantics = stub; newer-Laravel-only behavior = `stubs/<version>/`; call-site or flow context = handler; whole-codebase per-model facts = `ModelMetadataRegistry`. Record probed dead ends in decisions.md.
- Handlers decline with `null`, never a guessed type: a non-null answer permanently overrides Psalm's own inference and issues.
- Method providers register per CLASS: gate on the method name first, order bails cheapest first, touch `$codebase` last. Taint handlers bail when `$source->taint_flow_graph` is absent.
- Narrow only a receiver resolved to exactly one known class; unions and userland subclasses turn narrowing into false positives. Taint strips and sinks gate on the receiver, not the argument shape.
- A facade `@method` pseudo-method answer pairs the return-type AND params providers on one `methodExists()` lookup, or Psalm fatals in `getMethodParams()`.
- Static state carrying booted-app or per-run facts gets `reset()` registered in `Plugin::resetInvocationState()`; storage lookups that can fire pre-population sit in `catch (InvalidArgumentException|UnpopulatedClasslikeException)` treated as "not proven"; custom issues always pass the suppressed-issues list to `IssueBuffer::accepts()`.
- After fixing one family member, sweep the siblings (Builder, Collection, relations; facade and static forms) by reusing the same resolver, not a copy.

## Layout

- `src/Plugin.php`: entry point (parse config, boot app, build migration schema, register handlers, register stubs).
- `src/Handlers/<Feature>/`: Psalm hook handlers, one directory per feature area.
- `src/Handlers/Eloquent/Metadata/`: `ModelMetadataRegistry`, the per-model metadata store; completeness-gating semantics are in code-patterns.md.
- `src/Bootstrap/`: Laravel app boot. `ApplicationProvider::doGetApp()` holds the resolution logic.
- `src/Stubs/` providers plus `stubs/` files. Layout and merging rules: contributing README.
- `src/Cli/`: the `psalm-laravel` binary (init, add GitHub, diagnose, analyze). `<ignoreFiles>` skips analysis, not scanning: a dir listed in `<projectFiles>` under an ignored parent gets scanned but never analyzed and silently reports zero issues.
- `src/Issues/`: custom issue classes, each paired with `docs/issues/<Name>.md` via `DOCUMENTATION_URL`.
- `tests/Type/` phpt type tests, `tests/Unit/` PHPUnit, `tests/Application/` integration against a real app.

## Tests: where a regression goes

| Bug kind | Home |
|---|---|
| Wrong inferred type, stub or handler regression | `tests/Type/tests/*.phpt` |
| Internal logic (config parsing, schema aggregation, registries) | `tests/Unit/` |
| CLI command behavior and output | `tests/Unit/Cli/` |
| Boot behavior, fresh-app integration, whole-project scans | `tests/Application/` |

Prefer Type over Unit when both fit (suite overview: `tests/README.md`). A test asserting a `stubs/<version>/` override needs a `--SKIPIF--` section with `LaravelVersion::skipBelow()` (worked example in the contributing README).

Regression phpts use minimal abstracted fixtures (house style), but reduce toward them FROM the reported shape while the test stays red: simplifying before the first honest failure can delete the trigger (a trait, a docblock, a magic `__get` is often load-bearing).

- Taint and opt-in-rule phpts need an `--ARGS--` section (`--taint-analysis` or an alternate `tests/Type/psalm-*.xml` config), or they pass vacuously under plain defaults.
- Unit tests gate version-dependent behavior on capability probes (`class_exists` + `markTestSkipped`), never version numbers; phpts use `--SKIPIF--`.
- A deliberately accepted soundness gap gets a `*KnownLimitation.phpt` test pointing at the source caveat docblock.

## Debugging

- `/** @psalm-trace $var */` above a line dumps the inferred type of `$var` during analysis.
- Runtime-debugging a handler: plant `var_dump()` and run `vendor/bin/psalm --threads=1 --no-cache` on a small fixture (forked workers swallow output).

## Policies

- Upstream first: when plugin work reveals a bug in vimeo/psalm, laravel/framework, or another dependency, say so and name the correct layer BEFORE attempting a plugin-level workaround.
- Doc pairing: any change to `Plugin::registerHandlers()`, `Plugin::registerStubs()`, or the stub directory layout updates `docs/contributing/README.md` in the same PR.
- Every new class in `src/Issues/` ships its `docs/issues/<Name>.md` page plus an entry in `docs/issues/index.md` in the same PR. Opt-in issues also document their `PluginConfig` flag in `docs/config.md`; severity defaults go through the experimental lifecycle (contributing README).
- Taint source/sink/escape changes update the detection table in `docs/security.md` in the same PR.
- Code style is delegated to tooling: `composer rector` + `composer cs`.
  - When Rector strips a `@var` needed for type coverage, use `@psalm-var` (Rector ignores `@psalm-` prefixed annotations).
  - Unit test method names are exempt from camelCase.
- Comments state the non-obvious WHY that a reader cannot get from the code, keep them concise (written for senior engineers).
- PR bodies and docs use short engineering bullets over prose.
- "I do not understand why X is needed" from a reviewer or user is a request to investigate whether X is still needed, not to defend it.
