---
title: Architecture Decisions
parent: Contributing
nav_order: 1
---

# Architecture Decisions

Decisions made during development of the plugin. Contributors should follow these to keep the codebase consistent.

---

## Principles

1. Silence over false positives — never report an issue the plugin isn't certain about
2. Cover the 80% — Laravel offers many ways to do the same thing; support the common patterns, not every edge case
3. Complexity is fine when it's well isolated
4. Stubs vs. handlers: prefer stubs when they cover 95% of cases (incl. using [conditional types](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/type_syntax/conditional_types.md))
5. DX > micro-optimisations

## Static inference over runtime reflection

**Decision:** Prefer deriving types from Psalm's `ClassLikeStorage` and source code analysis. Use runtime reflection (booting the Laravel app via Testbench) only when the needed information is unavailable statically.

**Currently runtime:** Model table names (`getTable()`), model casts (`getCasts()`), container bindings, facade alias resolution, macro discovery (`Macroable::$macros` reflection).

**Currently static:** Relationships, accessors, migration schema parsing, stub overrides.

**Why:** Runtime reflection requires booting a real Laravel app, which adds startup cost, can fail in misconfigured projects, and couples the plugin to the user's environment. Static inference is faster, more predictable, and works in CI without a running app. But some Laravel conventions (dynamic table names, programmatic casts, container bindings) are only knowable at runtime.

## Plugin invocation state

**Decision:** Mutable static state derived from the Laravel application, plugin XML, aliases, filesystem, Psalm Codebase, or files under analysis must have an explicit idempotent `reset()` and be reset by `Plugin::resetInvocationState()` before boot or any optional initialization branch.

**Why:** Psalm can reuse a PHP process (notably in the language server). An `init()` call is not a substitute: an optional feature may be disabled or a service may be absent on the next invocation, leaving a previous application's state live. App-independent immutable values may survive only when the owning class documents why their meaning cannot vary between applications or Codebases. Event-handler scratch state may instead use a reliable file/function lifecycle hook, with that lifecycle documented beside the cache.

## Eloquent Model

### `@property` PHPDoc takes priority over plugin inference

**Decision:** When a user declares `@property` on their model class, plugin handlers must defer to it by returning `null`.

**Applies to:** All three model property handlers — `ModelPropertyHandler`, `ModelRelationshipPropertyHandler`, `ModelPropertyAccessorHandler`. Check `pseudo_property_get_types['$' . $propertyName]` before doing any inference.

**Why:** Users who write `@property` annotations are explicitly declaring the type they want.
The plugin should respect that consistently across all handlers rather than overriding it with inferred types.

### Property writes use pseudo_property_set_types, not doesPropertyExist()

**Decision:** Migration-inferred columns are registered as `pseudo_property_set_types` on the model's `ClassLikeStorage` during `afterCodebasePopulated`. The property handlers (`doesPropertyExist`, `isPropertyVisible`, `getPropertyType`) remain read-only. The write type is `mixed` (permissive).

**Why:** Psalm's internal `InstancePropertyAssignmentAnalyzer` assumes that any property claimed as existing by a plugin has a `PropertyStorage` entry. Returning `true` from `doesPropertyExist()` for writes causes crashes because plugin-provided properties don't have backing storage. Using `pseudo_property_set_types` is Psalm's intended mechanism — it's how `@property` annotations work natively. The write type is `mixed` rather than the column type because the actual accepted type depends on casts (e.g., a `datetime`-cast column accepts `Carbon`, not just `string`), and casts from the `casts()` method are not fully resolvable during `afterCodebasePopulated`.

**See:** [#446](https://github.com/psalm/psalm-plugin-laravel/issues/446)

### Write-type registration for accessors and relationships is unconditional

**Decision:** `registerWriteTypesForMethods` (which registers `pseudo_property_set_types` for relationship properties, legacy mutators, and new-style `Attribute` accessors) runs for all models regardless of the `modelProperties` config. Only `registerWriteTypesForColumns` (migration-inferred columns) is gated behind `useMigrations`.

**Why:** Accessor and relationship properties are discovered from the model's own method signatures — they don't depend on migration files. A user with `columnFallback="none"` still expects `$user->roles = $collection` to work when Psalm's own `sealAllProperties` option is enabled. This is consistent with the read-side handlers, which are also unconditional (see below).

**See:** [#446](https://github.com/psalm/psalm-plugin-laravel/issues/446)

### Model property handlers always run, no per-handler config toggles

**Decision:** `ModelRelationshipPropertyHandler` and `ModelPropertyAccessorHandler` are always registered. Only `ModelPropertyHandler` (migration-based column inference) is gated by the `modelProperties` config.

**Why:** The relationship and accessor handlers use Psalm's own type inference with no external data source.
They produce no false positives, and there's no real-world scenario where a user would want one but not the other. Exposing per-handler toggles adds config complexity without value. The `@property` precedence rule (above) is the escape hatch for users who want to override specific properties.

### Aggregate accessor proof (`{relation}_count`, `{relation}_exists`)

**Decision:** An aggregate accessor is `int|null` / `bool|null` unless the code proves it was loaded; then it is `int<0, max>` / `bool`. min/max/sum/avg stay nullable even when proven (SQL NULL on an empty relation). Proof sources, all literal-only and validated against the FINAL model class with `ModelAggregatePropertyHandler::isRelationMethod()`:

1. Model `$withCount` defaults (optimistic, like migration columns; gated on `SECTION_RUNTIME_CONFIGURATION`). `rel as alias` entries make the alias exist.
2. `$m->loadCount(...)` & co. on a variable: `ModelAggregateLoadHandler` sets `vars_in_scope['$m->x_count']`, which Psalm reads before any property provider (so aliases work).
3. `$m = M::withCount('x')->...->firstOrFail()`: same facts for the assigned variable.
4. `M::withCount('x')->firstOrFail()->x_count` / `$m->loadCount('x')->x_count`: the PropertyFetch node type is overridden (conventional names only; an alias name fails the existence check first).

Chains are walked from the terminal call inward: past a retrieval method (`first`, `firstOrFail`, `sole`, `find`, `findOrFail`, `firstWhere`; never `firstOrNew`/`firstOrCreate`, whose new instances lack the attribute) only Builder/Relation-typed calls keep the query, and `select()`/`setQuery()` end the walk because they replace the aggregate columns (`selectRaw()`/`selectSub()`/`addSelect()` append and keep the proof). `$m->refresh()` drops only the `$m->…` facts this handler recorded; user narrowings on other `$m->…` entries stay. A user `@property` always wins.

**Precedence:** a real model attribute named like an aggregate shadows it: `@property`, then schema column, cast key, accessor, then aggregate (each source counts only from a complete registry section). `votes_count` as a migration column next to a `votes()` relation stays the column's type; after `loadCount('votes')` the proof fact still applies, as the alias column wins in the SELECT result.

**Dead end:** carrying the fact in the type (`Shop&object{x_count: int}`). The intersection flows through `Builder` (its `TModel` is covariant), but `Collection<int, Shop&object{…}>` is not assignable to `Collection<int, Shop>`, so `->get()` results then fail every `Collection<int, Shop>` parameter. Flow facts avoid changing any model type.

**Known limitations (reads stay nullable):** `->get()->first()` Collection hops, variable-held builders, closures, `foreach` over models, `getAttribute('x_count')`.

**Known imprecision (same class as Psalm keeping property facts after impure calls):**
- `refresh()` unsets the recorded `$m->…` facts, but a branch merge ignores a key missing from one side, so a `refresh()` inside only one branch leaves the pre-branch proof in place (`AggregateAccessorRefreshInBranchKnownLimitationTest`).
- One alias produced by different aggregate functions in a single chain records no fact (`withExists` casts the alias to bool for good). Across in-place loads, separate or chained, the latest write wins, so `loadExists('a as t')` then `loadCount('b as t')` reads `int<0, max>`.
- `refresh()` invalidates only receivers typed as exactly one Model; other objects keep their property facts.

**Column-aware min/max/sum/avg:** Laravel casts only the `exists` alias, so the attribute holds the raw PDO value. The type comes from the RELATED model's migration schema ONLY (not casts, not `@property`: `withMax('orders', 'created_at')` is a string, never Carbon). The schema maps `decimal` to float while PDO returns DECIMAL as a string (and MySQL `SUM`/`AVG` over exact values is DECIMAL), so float columns also admit `numeric-string`; `SUM(int)` is int on SQLite/PostgreSQL-bigint but a DECIMAL string on MySQL, `AVG(int)` a float (SQLite) or numeric string (MySQL, PostgreSQL). Cells and the unresolvable-column fallback: `ModelAggregatePropertyHandler` class docblock.

## Config

### Naming: describe what is configured, not how it works internally

**Decision:** Config elements should be named from the user's perspective.

**Example:** `<modelProperties columnFallback="migrations" />` instead of `<modelDiscovery source="static" />`.

**Why:**
- `modelProperties` says what is being configured (properties on models), not an internal concept (discovery)
- `migrations` is concrete — a Laravel dev immediately knows what it means
- `static` was ambiguous in a static analysis tool context (static analysis? unchanging? parsed from code?)
- Config names should not collide with related concepts — "Model directories" config (which *is* about discovery) sits right below

## Class Loading and Discovery

### Handler files are loaded with explicit `require_once`, not via Composer autoload

**Decision:** `Plugin::registerHandlers()` `require_once`s every handler file by absolute path (`__DIR__ . '/Handlers/...'`) immediately before calling `$registration->registerHooksFromClass($handler)`. Do not replace this with `class_exists($fqcn, true)` or rely on PSR-4 autoload alone.

**Why:** `Psalm\PluginRegistrationSocket::registerHooksFromClass()` calls `class_exists($handler, false)` and throws unless the handler is already loaded. The registration API therefore deliberately refuses to invoke Composer autoloading. The paired `require_once` is the direct, deterministic way to establish that precondition; do not replace it with a bare registration call.

Initialization has a different boundary. `PluginConfig::fromXml()` is called before any init helper, so this plugin already requires its own namespace to be autoloadable at invocation time. Direct static calls in an `init*Handler()` method can then use ordinary Composer autoloading. `loadInitializationHandlers()` is consequently a source-order convention: it makes the explicit loads happen before every optional init path, but it is not an autoloader bootstrap.

**Sister plugins follow the same pattern:** `psalm-plugin-symfony` keeps `require_once` for every handler. `psalm-plugin-phpunit` and `Lctrs/psalm-psr-container-plugin` use `class_exists($fqcn, true)` instead, but each has only one handler — the failure mode is harder to miss.

**Why PHAR CI does not remove this constraint:** [#895](https://github.com/psalm/psalm-plugin-laravel/issues/895) was closed as not planned. Installing this plugin pulls Psalm into `vendor/`, so running `psalm.phar` creates a dual-Psalm collision in the natural setup. The workaround still registers the project's autoloader, and would not change Psalm's current non-autoloading handler-registration API.

**Contributor rule:** every new handler added to `Plugin::registerHandlers()` MUST keep its paired `require_once` line. If `Plugin::__invoke()` or an `init*Handler()` method makes a static call before registration, add that file to `loadInitializationHandlers()` as well—before its first static touch, on every configuration branch. The latter maintains source order; the former satisfies Psalm's registration precondition.

### Event-driven model discovery via `AfterCodebasePopulated`

**Decision:** Models are discovered from Psalm's own codebase after it finishes scanning project files, using the `AfterCodebasePopulatedInterface` event.

**How it works:**
1. Psalm scans all `<projectFiles>` and populates `ClassLikeStorage` for every class (including full parent hierarchy)
2. `ModelRegistrationHandler::afterCodebasePopulated()` iterates all known classes
3. For each concrete `Model` subclass (checked via `$storage->parent_classes`), property handler closures are registered directly via `registerClosure()`
4. `class_exists($name, true)` is called to force-load the class for runtime reflection (needed by `getTable()`, `getCasts()`)

**Why not directory scanning + config (`model_locations`)?**
- Directory scanning required users to configure a list of directories
- Modular Laravel apps (e.g. `app/Modules/Foo/Models/`) were especially prone to this
- The plugin duplicated work Psalm already does (finding PHP classes in project files)

**Why `AfterCodebasePopulated` instead of `AfterClassLikeVisit`?**
- `AfterClassLikeVisit` fires during scanning — at that point, `parent_classes` only contains the **direct** parent, not the full ancestor chain
- A model extending `BaseModel extends Model` would be missed because `Model` isn't in `parent_classes` yet
- `AfterCodebasePopulated` fires after the populator resolves the full inheritance hierarchy

**Why not `get_declared_classes()` without scanning?**
- `get_declared_classes()` only returns classes already loaded into the PHP process
- Model classes are typically NOT loaded during Laravel bootstrap — they're autoloaded on demand
- Would require directory scanning anyway to force-load classes, defeating the purpose

**Trade-off:** Vendor Model subclasses (e.g. `Laravel\Sanctum\PersonalAccessToken`) will also be discovered if they appear in Psalm's scanned files.
This is acceptable — the handlers gracefully handle any Model subclass.

**Handler registration:** Property handlers (`ModelRelationshipPropertyHandler`, `ModelPropertyAccessorHandler`, etc.) no longer implement Psalm's `PropertyExistenceProviderInterface` etc.
Instead, `ModelRegistrationHandler` registers their static methods as closures via `registerClosure()`.
Registration order is preserved (relationship > factory > accessor > column).

### Why container-bound classes are queued eagerly at plugin init

**Decision:** `Plugin::__invoke()` calls `ContainerResolver::queueBoundClassesForScanning()` once, after `registerStubs()`: it `make()`s every binding and queues each resolved object class (`store_failure: false`) so `app('alias')` narrows to a class Psalm has storage for (#1797).

- **Why init:** plugins initialize before Psalm's scan and before stub registration, so the classes are scanned as ordinary vendor files, not as stubs (vimeo/psalm#12313). The former `AfterClassLikeVisit` hook on the Application/Container interfaces re-ran every `make()` on each visit (2-5 times per run).
- **Rejected, lazy only** (drop the queue, keep the resolver's storage guard): `app('encrypter')`, `app('cache')` and other string aliases become `mixed` whenever nothing else scans the concrete. The probe found 152 of 201 bound concretes that nothing else scans; `ContainerNarrowedMethodResolutionTest` fails.
- **Rejected, targeted pre-scan** (queue only classes whose literal abstracts the project names): the only scan-time hook, `AfterClassLikeVisit`, misses file-scope files and goes stale on cache replay after a binding changes. The workable form is an init-time AST pass over `ProjectAnalyzer::$project_files` (private, read by reflection) with a single-file/whole-run gate.
  - unopim (Laravel 13, 2331 files), single file: 4881 -> 4164-4319 scanned files, 8.8 -> 8.0-8.2 s cold; warm cache is slower (3.9 -> 4.4 s). Whole run: within noise.
  - Without also queuing alias-binding concretes, single-file results diverge, e.g. `Password::broker()` gives a false `InvalidReturnType`. Literals reached via variables, class constants or concatenation drop to `mixed`.
  - Cost: about 250 src and 350 test lines. Revisit only if PhpStorm warm-cache runs show a real cost (#1797 reports 574-668 extra classes per single-file run on a 19k-file app).
- **Rejected, non-instantiating discovery** (`getClosure()`'s captured `$concrete`, `$instances`): covers only 75 of 201 concretes. Core services (cache, encrypter, log) are bound with provider closures and need `make()`.

### `Request::user()` overrides: per-subclass registration, explicit guard only

**Decision:** `RequestHandler::afterCodebasePopulated()` registers the `Request::user()` return-type closure on every `Request` subclass whose `user()` resolves to an override (own, inherited from an overriding parent, or trait-imported), e.g. Laravel Nova's `NovaRequest`.

- **Why per subclass:** Psalm dispatches a return-type provider for the called class or the declaring class only (`MethodCallReturnTypeFetcher`), never an intermediate ancestor, so the `Request` registration never fires for an override and calls stay `mixed`. A trait override is dispatched under the trait, hence registration on the using class and its descendants rather than relying on the declaring-class fallback.
- **Forwarding bet:** an override is assumed to forward an explicit guard to `parent::user($guard)`. Only an explicit literal string or string-backed enum case guard narrows; dynamic, unknown, or unpacked guards decline.
- **No-arg and `null` decline on overrides:** the override may pick its own default (Nova reads `config('nova.guard')`), so the app's `auth.defaults.guard` is not provable there. Plain `Request` keeps the default-guard narrowing.
- **Declared return type wins, decided by Psalm itself:** registration takes every override; at call time, after the argument-shape bails, the handler asks Psalm for its own return type of the called class's `user()` and declines unless it is `mixed` (or unresolved). The call is `Methods::getMethodReturnType()` with the event's `StatementsAnalyzer`, the same call `MethodCallReturnTypeFetcher` makes, minus template lower bounds (an unresolved template param is never mixed, so this can only decline), so typed, docblock-typed, trait-aliased, and documented-by-a-typed-ancestor overrides all keep Psalm's answer and an untyped `{@inheritDoc}` override (documented by `Request::user()`'s `mixed`) narrows. Rejected: a registration-time gate mirroring Psalm's documenting/overridden-method resolution from storage, which diverged on trait aliases, child re-aliases, and a `: mixed` child under a docblock-typed parent. `Codebase::getMethodReturnType()` is not a substitute either: it passes no source analyzer, so `Methods::getMethodReturnType()` returns the documenting `mixed` over an override's own `?Admin`.

## Performance

### Performance budget for handlers

**Decision:** Handlers must avoid per-invocation overhead that scales with codebase size. Hot-path handlers (those registered via `registerClosure()` for every model or every method call) must be especially lean: no redundant `getStorage()` calls, no reflection when Psalm's `ClassLikeStorage` suffices, no unbounded loops over unrelated classes.

**Why:** Property and method handlers fire on every expression or statement involving their registered class. In a large project with 150+ models, a small inefficiency compounds across thousands of call sites. The plugin must add negligible overhead to Psalm's analysis time.

**How to evaluate:** Run the plugin benchmark (`/psalm-plugin-benchmark`) before and after significant handler changes. Time and memory should remain within ~5% of the without-plugin baseline.

## Upstream Workarounds

### Work around Psalm bugs only when there's no upstream fix path

**Decision:** Prefer fixing issues upstream in Psalm. Only add a workaround in the plugin when:
1. The Psalm bug is confirmed and unlikely to be fixed soon, AND
2. The workaround is isolated (not spread across multiple handlers)

Document every workaround with a comment linking to the upstream issue.

**Why:** Workarounds accumulate tech debt and can mask the root cause. They also break silently when the upstream behavior changes. But waiting indefinitely for upstream fixes blocks real users.

### `NamedArgumentTaintHandler` strips only named arguments bound to a variadic

**Decision:** Strip taint from a named-argument value only when Psalm binds it to the callee's variadic and the callee resolves exactly. Everything else is left to Psalm.

**Why:** vimeo/psalm#11923 is fixed in 7.0.0-rc1, so the old strip-everything handler only hid true positives. Two variadic bugs remain:
- vimeo/psalm#12252: an unpacked argument is mapped onto every parameter and string keys are ignored, so `run(...$args)` forwarding to `handle(...$args)` reports `run(page: $x)` against `handle()`'s first parameter (#1395).
- vimeo/psalm#12251: a variadic is keyed by its written offset, so `v(zzz: $x)` collides with the fixed parameter declared there.

Deleting the handler would bring the #1395 false positive back, so it shrank to this one rule. Retire it when both bugs are fixed.

**Accepted limitation:** the strip drops the whole flow, so a genuine sink in the variadic's body or behind the re-spread is lost versus plain Psalm (`TaintedNamedArgumentVariadicKnownLimitation`). `AddRemoveTaintsEvent` names neither the parameter nor the destination, so no narrower strip exists.

**Rejected (draft PR #1579):** strip only when the written offset collides with a fixed parameter. It keeps the genuine finding but reopens the reported #1395 false positive, which sits at the variadic's own offset.

### Closure-parameter typing in `Eloquent\Builder` where-family stubs

**Decision:** The `\Closure(self<TModel>): mixed` arm on `Builder::where`, `firstWhere`, `whereNot`, `orWhereNot` is intentionally non-`static`. Users subclassing `Builder` and writing `$this->where(static fn (self $q) => ...)` should type the closure parameter as base `\Illuminate\Database\Eloquent\Builder`, not `self`.

**Why:** Psalm 7 does not specialize `static` inside closure-parameter positions against the receiver's generic binding. Every alternative shape regresses some real call pattern.

| Stub form | `Customer::query()->where(fn ($q) => ...)` | `$this->where(fn (self $q) => ...)` in subclass | `$sub->where(fn (Builder $q) => ...)` from outside |
|---|---|---|---|
| `self<TModel>` (current) | works | rejected (#815) | works |
| `static<TModel>` | $q collapses to `mixed` (#776) | works | works |
| `self<TModel> \| static<TModel>` | `UndefinedClass` from union arms | partial | partial |
| `self<TModel> & static` | works | works | rejected on subclass receivers |

`self<TModel>` is the only stub shape that satisfies the canonical Laravel-docs idiom on subclass instances. The base-`Builder` typing in user code is also semantically honest. `Builder::where` calls `$this->model->newQueryWithoutRelationships()`, which returns base `Builder` unless the model overrides `newEloquentBuilder()`.

**See:** [#815](https://github.com/psalm/psalm-plugin-laravel/issues/815), [#776](https://github.com/psalm/psalm-plugin-laravel/issues/776), [PR #784](https://github.com/psalm/psalm-plugin-laravel/pull/784), `tests/Type/tests/Builder/WhereClosureSubclassCoercionTest.phpt`.

### Conditionable `when()`/`unless()` callback params via a params provider

**Decision:** `ConditionableCallbackParamsHandler` replaces the stub's `callback`/`default` params per call site with `callable(<receiver>, <truthy|falsy $value>): mixed|null` (swapped for `unless`). The stub keeps plain `callable|null`.

**Why, mechanism by mechanism:**
- **Params provider, not Laravel's `@template` docblock.** The template form brings back the `mixed` chain return (#704) and types `$value` as nullable inside the callback.
- **Per-host `registerClosure` in `AfterCodebasePopulated`.** Params providers dispatch on the called class (`Methods::getMethodParams()`) with no declaring-class fallback, so registering on the trait never fires (return-type providers do fall back). Hosts are non-trait classes whose `when`/`unless` resolve to `Conditionable`; classes declaring their own `when()` (Container, Enumerable, ...) are excluded automatically.
- **`BeforeExpressionAnalysis` stash.** The provider event carries no call node, and the class name alone loses generics (false `MixedArgumentTypeCoercion`). The hook stashes the `when`/`unless` `MethodCall` in a `WeakMap` keyed by its first `Arg`; the provider reads the receiver's node type from it.
- **Pre-analysis of `$value`, on a cloned `Context`.** Params are fetched before args are analyzed (`CallAnalyzer::checkMethodArgs()` calls `Methods::getMethodParams()` before `ArgumentsAnalyzer::analyze()`), so the handler analyzes the arg itself. Psalm's own callmap path in `Methods::getMethodParams()` uses the live context, and that applies side effects twice (`++$i` leaves `$i === 2`, `$a[] = $x` yields `list{T, T}`), so the handler analyzes a clone. Closure values contribute their return type, with `void` read as `null` (what Laravel passes).
- **Truthy/falsy via `AssertionReconciler`** with `Truthy`/`Falsy` assertions, so narrowing matches Psalm's own `if ($x)` semantics (`?int` → `int` minus `0`, etc.).
- **Closure/arrow-fn literals only.** Only a slot whose argument is a `Closure`/`ArrowFunction` literal gets the typed callable. A passed-through callable that declares fewer params is rejected against a 2-param `callable` (param-count check in `UnionTypeComparator::isContainedBy()`, false `PossiblyInvalidArgument`/`MixedArgumentTypeCoercion`). A first-class callable has no declared-type escape for a subclass receiver. A literal whose first param is variadic (`function (...$args)`) is skipped too: Psalm fills a variadic param from the container's param 0 only, so every element would be typed as the receiver.
- **Declared-type preference.** If a closure literal declares a param type that contains the computed type, the declared type wins. Otherwise defensive code (`?int $x` then `if ($x === null)`) gets new `TypeDoesNotContainNull`/`RedundantCondition` noise. That containment rule applies to the value slot. Declared receiver types are trusted as written: runtime `$this` may be any subclass or implementer of the host (a custom builder Psalm cannot see via `#[UseEloquentBuilder]` or a docblock-only `newEloquentBuilder`, or an intersection with an interface), so a declared receiver param (native or docblock) keeps its declared type and only an untyped one gets the computed receiver. Five rounds of subclass/nullable/union/intersection/interface special cases each left another false positive, so the rule is unconditional. Accepted loss: a receiver declared as an unrelated class (`Query\Builder` on an Eloquent Builder) is no longer reported; Psalm never reported it against the stub either. Declared types are memoized in a `WeakMap` keyed by the literal node on first sight: `ArgumentsAnalyzer::handleClosureArg()` overwrites the storage param type with the inferred one, and a loop's second pass re-analyzes the same node.
- **Final hosts drop `&static`.** `$this` inside a trait is `Host&static`, and against a final host Psalm compares it with the plain host type (false `ArgumentTypeCoercion`).

**Declines (returns null = stock stub behavior):** union receivers (per-atomic closure re-analysis, last wins → FPs); receiver class ≠ dispatched class (relation `@mixin` forwarding to `Builder`); any `mixed` in `$value`; a `value` arg that is not the first arg (reordered named args: the pre-analysis would miss earlier args' side effects); a `$value` atomic that may be a Closure with an unknown return type (`callable`, `object`, template params, bare `Closure`); unpacked args; fewer than 2 args; no closure-literal slot. A slot keeps the stub callable when its literal declares a late-bound `self`/`static`/`parent` type at any depth (`list<self>`, `Collection<int, static>`) (closure storage keeps it unexpanded and Psalm never matches it against the host, false `InvalidArgument`) or its closure storage cannot be read, when an untyped literal param has a default (Psalm would infer it from the computed type alone and flag `if ($v === null)`), and when a branch reconciles to `never` (dead branch, avoids `NoValue`).

**Out of scope:** 0/1-arg `HigherOrderWhenProxy`, static `Model::when(...)`, `__call`-forwarded calls, narrowing `use`d variables, `Enumerable`-typed receivers.

**See:** [#1624](https://github.com/psalm/psalm-plugin-laravel/issues/1624), `ArgumentsAnalyzer::handleClosureArg()` (untyped closure params inferred from the provided callable).

## Taint Analysis

### Taint annotations: high confidence only

**Decision:** Only add taint annotations (`@psalm-taint-source`, `@psalm-taint-sink`, `@psalm-taint-escape`) when 98%+ confident they are correct. A missing annotation (false negative) is better than a wrong one (false positive that silently removes taint, or a noisy false positive that trains users to ignore results).

**Why:** A wrong `@psalm-taint-escape` can silently drop all taint kinds, making users believe their code is safe when it isn't. A wrong `@psalm-taint-source` generates noise that erodes trust. Taint annotations are security-critical and harder to validate than type annotations.

**See:** `docs/contributing/taint-analysis.md` for the full authoring guide.

### No taint-source on internal persistence reads

**Decision:** Do not mark reads from internal storage (cache, session, queue, filesystem reads of app-generated content) as `@psalm-taint-source input`.
Only mark reads from genuinely external/untrusted sources (HTTP request input, external HTTP responses, route parameters).

**Why:** Psalm tracks taint within a single analysis pass. It cannot follow data across requests (write in request A, read in request B). Marking `Cache::get()` or `Session::get()` as taint sources is a workaround for this limitation, but in practice 95%+ of cache/session reads contain trusted data (config, computed values, framework state).
The false positive rate is high enough to cause alert fatigue, which leads developers to either suppress taint issues globally or disable taint analysis — losing coverage on the real vulnerabilities.

**What to do instead:** Use `@psalm-flow` annotations on methods like `Cache::remember()` / `Session::put()` that pass data through callbacks or accept input.
This catches the most dangerous pattern (user input flowing through storage in the same analysis pass) without false positives.

**Applies to:** Cache\Repository, Session\Store, Queue job payloads, and similar internal persistence layers.
Does NOT apply to genuinely external data — `Http\Client\Response` (external API responses) and `Request::input()` (user input) remain legitimate taint sources.

### No taint-sink on low-severity internal writes

**Decision:** Do not mark internal write operations as taint sinks when the write itself is not the vulnerability.
Logging (`Log::info()`), broadcasting (`event->broadcast()`), and cache writes (`Cache::put()`) are internal operations — the vulnerability happens when tainted data eventually reaches a dangerous output (HTML, SQL, shell), not when it enters an internal store.

**Why:** Marking `Log::info($message)` as a taint sink (for log injection) or broadcast payloads as HTML sinks fires on extremely common patterns — every app logs request data for debugging/auditing.
The signal-to-noise ratio is too low for a general-purpose plugin.
Dedicated security scanners (Snyk, Semgrep) with configurable severity thresholds are better suited for these low-severity findings.

**Exception:** Sinks for high-severity, targeted operations remain valid — e.g., `Redis::eval($script)` (Lua injection) or `DB::unprepared($sql)` (SQL injection), because user input reaching this is almost always a real vulnerability.

### Call-site sink exemptions are applied at issue emission, not in the taint graph

**Decision:** When one call site has to be exempted from a stub's taint sink, implement `BeforeAddIssueInterface`, re-check the call site from the emitted issue, and return `false`. Do not mutate the taint graph through `RemoveTaintsInterface`.

**Why:** `AddRemoveTaintsEvent` identifies neither the method nor the argument offset, so a graph-level removal can only be keyed on the content AST node. Psalm dispatches that same event for the same node a second time while fetching the node's own callee return type. For a callee carrying `@psalm-flow` without `@psalm-taint-specialize` (`decrypt()`, and most helper stubs) the removal is then written onto that callee's single project-wide argument-to-return edge, which silences the removed taint kind on every unrelated flow through it. Emission-time suppression writes nothing, so the worst outcome of a wrong answer is a retained finding.

**Dead end (#1348, upstream vimeo/psalm#11924):** the shipped bridge recorded the content node in a `WeakMap` from `BeforeExpressionAnalysisInterface` and answered `RemoveTaintsInterface` on node identity. It needed a syntactic gate refusing any content that was itself a function or static call, which kept a known false positive, and the weak keying existed only because Psalm frees foreign ASTs mid-file (`ProjectAnalyzer::getMethodMutations()`, `ClassLikes::getTraitNode()`) without dispatching `BeforeFileAnalysisEvent`, so an `spl_object_id` key could be reissued to an unrelated node and strip taint off it.

**Rejected alternatives:** compensating with `AddTaintsInterface` after the fact joins the same last-write-wins race on the shared edge. Counting dispatches to tell the call-site event from the return-type-fetch event breaks across roughly seventeen dispatch sites, with the poisonous one firing first.

**Constraint:** the handler must be stateless. Taint findings are resolved in the main process after the worker pool exits (`Analyzer::analyzeFiles()`) while type issues are emitted inside workers, so nothing recorded by an analysis-phase hook is still there. Re-derive from the issue plus `Codebase::getStatementsForFile()`.

**Reference implementation:** `src/Handlers/Http/ResponseFactoryTaintHandler.php`.

**Widenings (#1416):** the all-literal gate cleared 1 of 20 real `response()->make()` sites, so four widenings landed, each still failing toward a retained finding. Mechanics live in the handler's docblocks; the decisions were:

- A headers variable resolves only on proof of one dominating assignment: exactly two occurrences of the name in the enclosing function-like, the straight-line `$headers = [...]` before the call and the call argument itself. Variable-variables, the `extract()` family, and top-level code cannot be proven and keep the sink.
- An interpolated or concatenated disposition proves `attachment` by its literal leading part alone; nothing after a literal parameter separator can retract the token.
- `new Illuminate\Http\Response(...)` is a second route to the same sink. Its journey tail (dumped empirically) matches `make()`'s shape, so the matcher trusts the label and needs no class gate.
- A safe `Content-Type` is proven by a denylist, not the whitelist the issue proposed: deny types containing `html`, `xml` (an XML document can carry an XHTML-namespaced script) or `script` (the WHATWG JavaScript group; `application/postscript` is accepted collateral), plus `multipart/*` and the sniffing escapes `unknown/unknown` and `application/unknown`. Every other well-formed literal type is exempt, so vendor download types need no maintenance list.

**Second application (#1435, `PromptGuardTaintHandler`):** the same mechanism exempts a `TaintedLlmPrompt` at a `laravel/ai` `prompt()` / `stream()` call site. Two facts made it cheaper than #1348:

- The journey tail label carries the RECEIVER class, not the declaring trait or base (`Internal/Codebase/Methods::getCasedMethodId()` returns the original fq class name unless it is all-lowercase; dumped on 7.0.0-beta19 with a child inheriting `middleware()` from an abstract base). The class is free at emission time, with no AST read and no receiver-narrowing gate, and an interface- or union-typed receiver declines for free.
- The proof target is declared TYPES, not a method body: the guard is a class in `middleware()`'s declared return type (object, `class-string<Guard>`, or `Guard::class` literal) whose dispatched method has `TaintKind::INPUT_LLM_PROMPT` in `FunctionLikeStorage::$removed_taints`, which is what `@psalm-taint-escape llm_prompt` already writes. Which method is dispatched: [taint-analysis.md](taint-analysis.md#how-the-prompt-guard-exemption-reads-an-escape-annotation).

**Trust the tag, do not prove the body.** The exemption is opt-in by the guard author and names no guard package. Rejected: an AST proof of `middleware()`'s body, which would hardcode one vendor's FQN and constructor parameters, could not tell a blocking guard from a logging one, and would exempt nothing for an app-local guard. The cost is an accepted caveat documented in `docs/security.md`: the annotation records that a mitigation is attached, not that a payload is neutralised.

### `json_encode()` HEX flags use `RemoveTaintsInterface`, with a type-unset gate

**Decision:** `JsonEncodeTaintHandler` removes taint from `json_encode()` calls whose flags provably contain literal HEX flags, through `RemoveTaintsInterface`: `JSON_HEX_TAG` removes html, `JSON_HEX_APOS` plus `JSON_HEX_QUOT` removes has_quotes (either alone leaves a raw `'` or a `\"` that HTML does not unescape). Accepted gap: the encoder's own `"` delimiters still close a double-quoted attribute or JS string. Such markup is visibly broken for every string, array or object value (ints, bools and null encode bare), so it rarely survives manual testing; keeping has_quotes instead would report every `@json` in a `<script>` block, where it is safe. Pinned by `TaintedJsonEncodeDoubleQuotedAttributeKnownLimitation.phpt`.

**Why this is not the graph mutation rejected above:** the hazard there is a removal landing on a callee's single project-wide argument-to-return edge. `json_encode()` is a Psalm-shipped stub, so Psalm specializes both of its nodes per call location, and the removal affects only that call's own edge. The handler checks that instead of assuming it: it declines unless the resolved storage is specialized (the test of `TaintFlowGraph::isCallSpecialized()`), so a project stub that re-declares `json_encode()` without per-call specialization (neither `@psalm-taint-specialize` nor `@psalm-pure`) keeps its findings. The handler is stateless and keys on the call expression, not on a recorded node. Emission-time suppression does not fit: the escape belongs to the transform, not to any one sink, so there is no sink call site to key on.

**Constraint:** `ReturnAnalyzer` accumulates the removal returned for a returned expression into the enclosing function's removed taints, which then apply to every return path of that function. A handler that answered on that dispatch would silence `return $tainted;` in the same function. The handler answers only while the call's type is still unset, which is true for the dispatch that writes the edge and false for the return and argument dispatches. This is an internal-ordering heuristic, not counting, and it fails toward a retained finding if Psalm reorders it. The same leak exists in core for `htmlspecialchars()`.

## Breaking Changes

### Breaking type changes require a major version bump or config opt-in

**Decision:** If a change causes new Psalm errors in existing user code (stricter return types, removed suppressions, new issue types), it must either:
1. Ship in a major version, OR
2. Be gated behind a config option that users opt into

Bug fixes (where the previous type was demonstrably wrong) are exempt.

**Why:** Users pin plugin versions and integrate Psalm into CI. A minor update that suddenly fails their build breaks trust and creates churn. The plugin should be a safe upgrade.

## Version Support

### Support current and previous Laravel major versions only

**Decision:** The plugin supports the two most recent Laravel major versions (currently 12 and 13). When a new Laravel major is released, the previous-previous version is dropped in the next plugin major release.

**Why:** Each supported Laravel version adds maintenance cost: version-specific stubs, conditional behavior, test matrices. Laravel's annual major release cycle means two versions covers the vast majority of active projects. Older versions receive security-only patches from Laravel and have a shrinking user base.

## Default Strictness

### New features default to permissive

**Decision:** When a new feature has a strictness spectrum (e.g. sealed properties, migration inference), the default should be the least disruptive option. Stricter modes are opt-in via config.

**Example:** `columnFallback="migrations"` (migration inference) is the default because it only adds property types; stricter behavior (such as Psalm's `sealAllProperties`) stays opt-in on the Psalm side.

**Why:** Users who install or upgrade the plugin should not be greeted with a wall of new errors. The plugin should improve analysis incrementally. Users who want stricter checking can enable it when they're ready.

## Suppression Strategy

### SuppressHandler: suppress known false positives from Laravel conventions

**Decision:** The plugin programmatically suppresses Psalm issues that are false positives caused by Laravel conventions (e.g. `PropertyNotSetInConstructor` for Command classes, `UnusedClass` for service providers). Suppressions are declared as data in `SuppressHandler` constants, keyed by parent class, trait, interface, or FQCN.

**Why:** Laravel conventions (constructor property promotion deferred to framework, class discovery via config) trigger Psalm issues that are technically correct but practically useless. Asking every Laravel user to suppress these manually would be noisy and repetitive. Centralizing them in the plugin keeps user code clean.

**Boundaries:**
- Only suppress issues that are *always* false positives for the given Laravel base class or trait
- Prefer parent-class/trait matching over FQCN matching (FQCN breaks for custom namespaces)
- Never suppress issues that *could* be legitimate bugs (e.g. don't suppress `InvalidReturnType` just because it's common)

### Dead code on Psalm 7: edges, not suppressions, for convention entry points

**Decision:** Methods Laravel calls by convention (invokable `__invoke`, pipe `handle`/`terminate`, queued/bus job `handle`/`failed`, `Dispatchable` constructors) and the hooks the queue reads off a job (`$tries`, `middleware()`, `uniqueId()`, ...) are rooted by class-conditional edges ("class is alive => this member is alive") in `IndirectMethodReferenceHandler::RULES`, not by `SuppressHandler` entries. The edge is sourced at the class node (`Context::$self`, no file path), so an unreferenced job stays `UnusedClass`.

**Why:** Psalm 7 resolves dead code by reachability, so an uncalled entry method is never alive and its private dependencies cascade into error-level `UnusedProperty`/`UnusedMethod`. A suppression hides only the entry point's own issue and adds no edge.

**Probed dead ends:**
- Suppression: adds no edge.
- Mutating `MethodStorage::$public_api`: an unconditional root, even for dead classes, and it mutates Psalm storage.
- Call-site AST hooks on `Route::*` / `dispatch()`: too many syntactic forms; string aliases (`'Plain@show'`) need a booted app.
- Roots from the booted router: deferred; needs a trusted boot and goes stale with route caching and env.

**Boundaries:** concrete user classes; public non-static members (the `Dispatchable` constructor at any visibility); a pipe needs a *native* `Closure` (or `?Closure`) on `handle()`'s second parameter. Not covered: route actions on non-controller classes, auto-discovered listeners, the remaining `SuppressHandler` convention entries.

**Known limitations (accepted):**
- A `ShouldQueue`-only class (no bus trait) gets no `handle` parameter injection: it may be a listener, and `CallQueuedListener` passes the event positionally. `make:job` always adds `Queueable`.
- Used traits are read from the class and its parents only, not from traits composed of other traits, and a `class_alias()`ed trait is not recognised.

## Handler Registration Order

### Property handler priority: relationship > factory > accessor > column

**Decision:** When registering property handlers per model in `ModelRegistrationHandler`, the order is: relationship properties first, then factory, then accessor, then migration columns. The first handler that returns a non-null result wins.

**Why:** A method named `posts()` that returns a `HasMany` relation should always be treated as a relationship property, even if a migration column named `posts` also exists. Similarly, an accessor `getFullNameAttribute()` should take priority over a `full_name` column. The order reflects specificity: relationships and accessors are explicit code the developer wrote; columns are inferred from migrations and serve as the fallback.

## Facades

### First-party facade `mixed` pseudo-methods get per-method stubs, not root-signature promotion

**Decision:** When Laravel's generated facade `@method` tag returns `mixed` but the underlying root method is templated (and would infer a precise type), add a real static method to `stubs/<layer>/Support/Facades/<Facade>.phpstub` copying the root signature. `FacadeStubPrecedenceHandler` then drops the pseudo-method, so Psalm uses the typed stub. Covered: `Cache::remember`, `rememberForever`, `flexible`, `sear`; `Cookie::queued`; `Context::scope` (#1368, #1737).

**Why:** A targeted stub is surgical and safe. The rejected alternative — generically promoting the root's `MethodStorage` over any `mixed` pseudo in `FacadeMethodHandler` or via `AtomicStaticCallAnalyzer::checkPseudoMethod()` — was probed in #1737 and ruled out for two reasons: (a) a full census of first-party facade `@method` tags found only four `mixed` pseudos with a more-precise templated root (all four are now stubbed); the remaining `mixed` pseudos are `mixed` in the root too, so generic promotion changes nothing for them; (b) root params are often narrower than pseudo params — `Cache::get`'s pseudo accepts `\UnitEnum|array|string $key` while the root is untyped — so swapping them would introduce new false-positive `ArgumentTypeCoercion` errors. `FacadeMethodHandler` is also not an alternative: it is wired only for app facades and returns the raw declared return without template inference.

**Known limitation:** A userland subclass of such a facade that declares its own same-name static method inherits the stub's signature checks.

### `Schema` facade: `@mixin Schema\Builder` types the introspection methods, with no handler whitelist (#1680)

**Decision:** `stubs/common/Support/Facades/Schema.phpstub` carries `@mixin \Illuminate\Database\Schema\Builder`. `Schema::getTables()`, `getViews()`, `getColumns()`, `getIndexes()`, `getForeignKeys()`, `getTableListing()`, `getColumnListing()` (and `getSchemas()`, `getIndexListing()`) resolve to Builder's `list<array{...}>` types (the installed Laravel's docblock, or the Builder stub's correction listed below) instead of the facade tag's bare `array`. No `FacadeStubPrecedenceHandler` change.

**Why:** Hand-copied shapes are wrong on one Laravel line (the `getColumns`/`getTypes` shapes changed between 12.x and 13.x; `getForeignKeys` is `array` before 13.7). The rejected alternative, a handler whitelist dropping those pseudo-static entries, is unnecessary: Psalm 7 resolves a static call on a facade through the `@mixin` target's real method (`AtomicStaticCallAnalyzer` mixin step) before it consults `pseudo_static_methods`, so the mixin alone wins.

**Scope of the behavior change:** the mixin applies to EVERY Builder method not declared as a real method on the facade stub, not only the `array` returns. Builder's parameter types and generic callback inference (`create()`/`table()` closures, `whenTableHasColumn()`, `withoutForeignKeyConstraints()` returning `TReturn`) now replace the facade tags', and Builder's stub taint sinks (`drop`, `create`, ...) now fire on facade calls.

**Builder-stub corrections (not facade carve-outs):** where Laravel's `Schema\Builder` docblock contradicts what the driver processors (`Query\Processors\*`) emit on a supported line, `stubs/common/Database/Schema/Builder.phpstub` declares the runtime-true shape. The facade picks it up through the mixin, and `Schema::connection()->...` instance calls share it (the Builder stub is the single source). Full audit matrix: processors are identical on 12.20-13.37 apart from Postgres `virtual` generation (12.70+).
- `getTypes()`: `type` repeated (invalid docblock, `mixed`) before 13.7, no `schema_qualified_name` before 13.8, `type`/`category` non-null on every line but null for unmapped Postgres codes.
- `getColumns()`: no `collation` before 13.8 (every driver emits it since 12.20); `generation.type` non-null on every line but null for unmapped MySQL/Postgres/SQLite codes.
- `getIndexes()`: `type: string` on every line, but SQLite always emits null; SQLite also `array_filter()`s the synthetic `primary` row on composite primary keys, so the result is `array<int, ...>`, not a list.
- `getForeignKeys()`: bare `array` before 13.7 (typed list declared on every line; the shape is identical).
- `blueprintResolver()`: Laravel 12.20-12.21 document `Closure(string, Closure, string)` but `createBlueprint()` passes `($connection, $table, $callback)` (fixed in 12.22).
- `getViews()`: `definition` is NULL for encrypted SQL Server views (`sys.sql_modules.definition`) and passes through `Processor::processViews()` uncast; declared `string|null`.

**Facade-side restatements (real static methods on the facade stub, restating the facade's own `@method` tag so a facade call is exactly what it was before the mixin).** Only the facade: the Builder instance keeps Laravel's docblocks, so `$schema->...` and `Schema::connection()->...` are unchanged.
- `hasColumns()`, `dropColumns()` (common stub): Builder documents `array<string>` for the column list, the tags accept any array, and the runtime accepts `Stringable` (`strtolower()` coercion) and `Expression` (grammar `wrap()`).
- `getColumn()` (`stubs/13.34.0/`, the method does not exist earlier): returns a full row or `[]` but documents only the full shape (`=== []` is a `DocblockTypeContradiction`); restated as the tag's bare `array`.
- Found by scripting `UnionTypeComparator::isContainedBy()` over every facade `@method` param against the Builder param the mixin resolves (12.20 and 13.37; arity/optionality/by-ref/variadic were identical). Not restated on purpose: `defaultStringLength(non-negative-int)` (a negative length is invalid DDL) and `blueprintResolver(Closure(Connection, string, ?Closure): Blueprint)` (native `Closure` rejects the 12.20 tag's `null`; the resolver is invoked as `($connection, $table, $callback)`).

**Known limitation:** each correction can go once laravel/framework fixes its docblock. The facade's `getColumn()` stays a bare `array` because Psalm merges `array{...}|array{}` into one shape with every key optional (`array{name?: string, ...}`), which turns a `!== []`-guarded `$column['name']` into a `PossiblyUndefinedArrayOffset` false positive; `array` reports the same offset check only as the generic `PossiblyUndefinedStringArrayOffset` every plain array gets under strict config (use `isset`), and accepts the `=== []` comparison.

## Laravel Docblock Gaps

### `Arr::toCssClasses()` accepts `null`; `toCssStyles()` is not widened

**Decision:** `stubs/12.50.0/Support/Arr.phpstub` widens `toCssClasses()` to `array<array-key, bool|int|string|null>|string` with a plain `@return string` (#1775). `toCssStyles()` is left as Laravel declares it.

**Why:**
- `toCssClasses()` tolerates `null` at runtime: `['a', null]` gives `'a '`, `['a', 'b' => null]` gives `'a'`. Laravel's docblock rejects it since 12.50.0 (earlier releases declare a plain `array|string`, which is why the stub is a version dir, not `common`), so `@class(['a', $maybe])` reported `InvalidArgument`.
- `toCssStyles()` does not tolerate it: a numeric-key `null` reaches `Str::finish(null, ';')` (PHP deprecation, stray `;`).
- Psalm merges `array<string, ...>|array<int, ...>` into one array type, so `null` cannot be allowed for string keys only.

**Boundaries:**
- The plugin does not model Laravel's conditional `@return` (`''` vs `non-empty-string`): the wrong types are an upstream docblock bug, and `[null]` renders `''`, so the upstream `non-empty-string` is unsound for nullable items. Fix it in laravel/framework, not here.
- A top-level `null` `$array` stays rejected.

## Collections

### In-place writes widen through `@psalm-this-out`; two receiver shapes are an accepted limitation

**Decision:** `Support\Collection::push()`/`put()`/`prepend()`/`unshift()`/`add()`/`splice()`/`getOrPut()` take fresh value (and key) templates and declare `@psalm-this-out static<...>`, so writing a value outside the current `TValue` widens the receiver instead of reporting `InvalidArgument` (#1890). `$c[] = $v` (`offsetSet`) stays strict because `@psalm-this-out` does not apply to array-access writes. `transform()` already works through Laravel's own `@phpstan-this-out`.

**Accepted limitation:** the model misbehaves for two receiver shapes, and `push()`/`put()` share both defects:
- A generic subclass that reshapes the template list (`Bag<T> extends Collection<int, T>`): `static<TKey, TValue>` is applied positionally to `Bag`'s own templates, so `Bag<User>` becomes `Bag<int, User>` and `first()` turns into `int|null`. Psalm does not route the arguments through `template_extended_params`.
- A union of different receiver classes (`Support\Collection|Eloquent\Collection`): this-out replaces the whole variable type with one branch and discards the unaffected branches, so a later `instanceof` reports a redundant or impossible condition.

Both are Psalm-side defects. The stub does not work around them: a per-shape workaround would be spread across every in-place writer, and the common receivers (plain `Collection`, `Eloquent\Collection`, fixed-template subclasses) are correct for writes within their fixed types (a write outside the fixed `@extends` types widens beyond the subclass's declared parameters).

**Boundaries:** `splice()` keeps its receiver key type `TKey` (`array_splice()` renumbers integer keys, which is not modelled) and returns the removed slice, so only its this-out carries the replacement values. `getOrPut()` leaves the key unbounded because Laravel accepts `mixed`.

### `groupBy()`/`keyBy()` with a `mixed`-returning callback is answered by a handler, not the stub

Laravel casts group/key values to array keys at runtime, but the stub's `TGroupKey`/`TNewKey` conditional keeps `mixed` in the outer key (`array-key|mixed`), so the next `static<TKey, ...>` call reports `InvalidTemplateParam` (#1884). Rewriting those conditionals did not help (two attempts, probed dead end); `CollectionGroupByKeyByHandler` answers `array-key` for callbacks whose return type contains `mixed` and declines for every typed callback.
### `filter()`/`reject()`/`where()` narrow TValue from a class-typed closure parameter (#1912)

**Decision:** `CollectionFilterHandler` narrows the result TValue of `filter(Closure)`, `where(Closure)` and `reject(Closure)` from the closure's first parameter. Laravel calls the callback for every element and keeps the original item, and PHP never coerces an object into a class type, so any non-instance throws `TypeError`: every surviving element is an instance of the parameter type. Return type only; `ArgumentTypeCoercion` / `MixedArgumentTypeCoercion` are still reported.

**Rules:**
- Exactly one argument, a `Closure`/arrow-fn literal (no first-class callables or callable strings), first param non-variadic and non-by-ref.
- The param's NATIVE type must be only class/interface/enum names and optionally `null`. Untyped, `mixed`, scalar, array, `object`, `iterable`, `callable` decline.
- Gate: TValue is `mixed`, or the param's non-null part is contained by TValue without coercion. An unrelated param keeps today's result and Psalm's `InvalidArgument`. A template-derived atomic in TValue declines.
- Per TValue atomic: kept when the param accepts it (preserves generics such as `Dog<int>`), replaced by the param's classes it contains (`mixed`/`object`/supertype) otherwise, dropped if neither. `null` survives only when the param is nullable and TValue had `null`/`mixed`.
- Param narrowing needs an exact-base receiver: the dispatched class and every atomic of the receiver expression type are exactly `Support\Collection`, `LazyCollection` or `Eloquent\Collection`, with no intersection and no template (`C of Collection`). Subclasses with another template layout (`WithMetadata<V, M> extends Collection<int, V>`) would get the positional `(TKey, TValue)` result applied to their own templates. A receiver union of the three classes still narrows per branch.
- A param typed `self`/`static`/`parent` declines: closures can be rebound, so the lexical class is not what the callback receives.
- filter/where then run the existing predicate matcher on the narrowed TValue and fall back to the param-narrowed result when it yields nothing. `reject()` gets only param narrowing (predicate inversion is a separate matter).

**Limitation:** `reject()`/`where()` on an `Eloquent\Collection` receiver do not reach the handler (declared on `EnumeratesValues`; the handler stays registered for `Support\Collection` and `LazyCollection` only), so only `filter()` narrows there.

**Rejected (probed dead end): narrowing scalar params when the user's file declares `strict_types=1`.** The coercion mode of a call is decided by the CALLING file. The caller is `Arr::where()`/`array_filter()` (`Collection`), a generator in `LazyCollection::filter()`, or `EnumeratesValues::reject()`'s wrapper. None of those Laravel files declares `strict_types`, and `array_filter` is internal, so callbacks run in coercive mode regardless of the user's file: `fn (int $x)` accepts `"5"` and the original `"5"` stays in the result.

**Boundaries:** `first`, `each`, `every`, `contains`, `partition`, `sortBy`, `keyBy`, `groupBy` do not qualify (early exit, or the result is not a kept subset). The receiver variable is not narrowed, and `Enumerable`-typed receivers and higher-order proxies are not covered.

### `toArray()` keeps Laravel's return type (probed dead end, Psalm 7.0.0-rc2)

**Rejected:** a conditional `toArray()` return that maps `Arrayable` items to `array<array-key, mixed>`. On the `EnumeratesValues` trait the template is not remapped through `@extends`, so a subclass `toArray(): array` override reports `InvalidReturnType`. On the class stubs, Psalm's conditional evaluation drops the `Arrayable` arm for concrete `Arrayable` classes and for generic `Arrayable`-plus-scalar unions, which is unsound. A narrower `Eloquent\Collection` return (`array<TKey, array<array-key, mixed>>`) breaks custom serializer overrides that only satisfy `Arrayable<TKey, TValue>`.

### `setCollection()` keeps `@return $this`; a chained call keeps the old paginator templates (probed dead end)

**Rejected:** `@return static<TSetKey, TSetValue>` on `setCollection()`. It is applied positionally to subclasses that reshape the template list (`Page<T> extends AbstractPaginator<int, T>` types its items as `int`; extra templates such as `TMeta` are dropped), and a userland `@return $this` override reports `ImplementedReturnTypeMismatch`.

**Accepted limitation:** `$p->setCollection($c)->items()` keeps the receiver's old keys and values, while `$p->setCollection($c); $p->items()` rebinds through `@psalm-this-out`. Pinned by `PaginatorSetCollectionChainKnownLimitationTest`.
### `HigherOrderCollectionProxy` uses Laravel 13.31+'s `<TMethod, TValue, TCollection>` on every version (#1920)

**Decision:** `stubs/common/Support/HigherOrderCollectionProxy.phpstub` declares `TMethod of string`, `TValue` (covariant) and `TCollection of Enumerable` (covariant); the `Collection`, `LazyCollection` and `Eloquent\Collection` stubs type `$map` as `HigherOrderCollectionProxy<'map', TValue, Collection<TKey, TValue>>`. The runtime proxy is identical on all supported Laravel versions (12.20-13.30 only lack the annotation), so the shape lives in `common`, and user code written against Laravel's 3-argument type checks on every cell. `HigherOrderCollectionProxyHandler` reads TValue at index 1 and takes the `Enumerable` fallback key from TCollection (index 2), `array-key` otherwise.

**Rejected (probed dead ends, Psalm 7.0.0-rc2):**
- **Laravel's verbatim `static` as the third argument.** In a class-stub `@property-read` it resolves to `Collection&static`, an unparameterized object: TKey/TValue are lost for `Collection<int, X>`, `LazyCollection` and `Eloquent\Collection`, so the `HigherOrderCollectionProxy<'map', X, Collection<int, X>>` parameter reports `MixedArgumentTypeCoercion`. Each stub spells its own class out instead.
- **Laravel's bound `TCollection of Enumerable<array-key, TValue>`.** The stub `Enumerable` declares an invariant `TKey`, so `Collection<int, X>` violates the bound and a user `@param` with the 3-argument shape reports `InvalidTemplateParam`. `Enumerable<array-key, mixed>` fails the same way; only the bare `Enumerable` bound passes.
- **Invariant `TCollection`.** `HigherOrderCollectionProxy<string, X, Enumerable<int, X>>` then rejects `$collection->map` (`InvalidArgument`); covariance accepts it. `TMethod` stays invariant (as in Laravel): a `'map'` proxy given to a `'filter'` parameter is reported.

**Accepted limitation:** a non-generic subclass (`final class UserCollection extends Eloquent\Collection` with `@extends`) and a template-bound receiver (`@template C of Collection<int, X>`) still resolve their proxy from the vendor trait annotation or lose TValue; this is unchanged by the 3-argument shape. The handler keeps a `count >= 2` guard because that vendor annotation is still `<TKey, TValue>` on Laravel 12.20-13.30, where dropping the guard turns `$subclass->each->method()` into `mixed`.

## Variance

### Paginators are covariant in `TValue`; `Support\Collection` stays invariant

**Decision:** The three paginator contracts and the five paginator classes in `stubs/common/Pagination/` and `stubs/common/Contracts/Pagination.phpstub` declare `@template-covariant TValue`, exactly as Laravel does; `TKey` stays invariant (#1860, paginator half). The contracts use Laravel's `TValue` name, not the former `TItem`. `Support\Collection` and `Eloquent\Collection` variance is a separate decision (#410) and is not changed here.

**Why:** Laravel 13.36 types `BelongsToMany` results as `TRelatedModel&object{pivot: TPivotModel}`, so `$post->tags()->paginate()` is a `LengthAwarePaginator<int, Tag&object{pivot: Pivot}>`. With an invariant `TValue` it could not be returned as, or passed to, `LengthAwarePaginator<int, Tag>`, a type Laravel itself accepts. A paginator is read-mostly, so covariance is sound for the common surface; the intended writes go through `setCollection()` / `through()`, whose `@psalm-this-out` retypes the receiver variable to the new value type (they still mutate the object in place; see Boundaries).

**Boundaries:**
- Accepted hole: a helper typed `LengthAwarePaginator<int, Model>` that receives a `LengthAwarePaginator<int, MechanicSpecialization>` can replace its items in place, and the caller keeps reading the narrower type. Not caught:
  - `$p->getCollection()->transform(fn (Model $_): Model => new Mechanic())`: `getCollection()` returns the live wrapped collection, not a copy.
  - `$p->through(...)` and `$p->setCollection(...)`: both mutate the same object at runtime; `@psalm-this-out` rebinds only the static type of the callee's receiver variable, never the caller's alias.
  - `offsetSet`, and a forwarded `push`/`put` (`@mixin` / `__call` onto the wrapped collection).
  Laravel's own PHPDoc has the same hole. Pinned by `PaginatorCovarianceKnownLimitationTest.phpt`.
- Unrelated value types are still rejected (`LengthAwarePaginator<int, Tag>` is not a `LengthAwarePaginator<int, User>`).
- Do not widen `Support\Collection` by copying this: a collection is routinely written through, which is why its variance is a separate decision.

## Producer Return Narrowing

### Provenance may narrow, conformance never widens

**Decision:** A stable producer (a framework method that hard-constructs one concrete with no supported extension point) narrows its declared contract return to that concrete. `ProducerReturnTypeHandler` holds the reviewed mapping (`PasswordBrokerManager::broker()`, `View\Factory::make()/file()/first()`, plus facades and root aliases). `view()`/`trans()` narrow via their existing handlers: zero-arg forms narrow to the resolved binding when it is the framework class or a subclass (`Illuminate\View\Factory` / `Illuminate\Translation\Translator`), else fall back to the contract; the argument-supplied `view('name')` form additionally requires the exact stock `Illuminate\View\Factory`. `Query\Builder` pagination is stub-narrowed (`stdClass` rows).

**Why:** Laravel declares contracts where runtime always yields one concrete, so concrete-only calls like `Password::broker()->createToken()` report false `UndefinedInterfaceMethod`.

**Boundaries:**
- Key on the producing expression, never the contract: contract FQCNs are never registered, interface storage never modified. Bare contract-typed values (params, properties, mocks, custom impls) keep the contract-only surface.
- Drift guard: each rule requires the declared return to still name the expected contract, else it disables itself.
- New mappings need source verification across supported Laravel versions plus positive and negative tests.
- Disclosed gap: producer internals like `Factory::viewInstance()` are protected, so a producer subclass inheriting the mapped method but overriding the construction still narrows to the stock concrete. Accepted (matches the Cache manager precedent). A substitute implementation yields a false negative (a stock-only method resolves though the substitute lacks it), plus a false positive (a substitute-only method flagged undefined) when the stock concrete is not Macroable. The View concrete's `__call` masks the false positive; a non-Macroable producer such as PasswordBroker would exhibit it. Standard apps return the stock concrete, and a call site that hits the gap can suppress locally.
- Excluded: driver-variant surfaces (Auth guards, Hash, Queue, Broadcast, Redis, Filesystem) where the concrete depends on runtime config.

## Third-Party Package Support

### Plugin covers Laravel framework only, not third-party packages

**Decision:** The plugin provides type support for `laravel/framework` (Illuminate namespace) and first-party packages that ship with a default Laravel install. Third-party packages (Sanctum, Cashier, Livewire, Filament, etc.) are out of scope unless their model subclasses are naturally discovered.

**Why:** Third-party packages evolve independently, have their own type stubs, and may ship their own Psalm plugins. Supporting them would multiply the maintenance surface. The plugin's model discovery will pick up any `Model` subclass in the scanned codebase (including vendor), and the generic handlers work for those. But package-specific magic (e.g. Livewire's component properties) belongs in a package-specific plugin.

## Blade Template Analysis

### `$attributes`/`$slot` classify a component view from its own SOURCE, not its render path or view location

**Decision:** `PreludeBuilder::componentTypesFor()` declares `$attributes`/`$slot` only when the template's own source writes `@props(...)`, `@aware(...)`, or mentions either name — never from where the view lives (`components/` or a registered anonymous namespace) or how it is reached (`<x-*>` vs `@component('view', [...])`). `$component` is never declared at all: Laravel does not pass it as view data on any path.

**Why:** `AMBIENT_TYPES` unconditionally declared `$attributes`/`$component`/`$slot` non-nullable in EVERY shadow, so Laravel's own compiled guards on those names (`isset()`, `??=`, `instanceof`) collapsed into `RedundantCondition`/`RedundantConditionGivenDocblockType`/`DocblockTypeContradiction` — 84% of all template findings on a measured real-world app (#1525). The source-based classifier is exact for the case that matters most (a `<x-*>` caller never writes any of the four markers itself) and is free: `ShadowManifest::fingerprint()` already hashes `$source`, so no manifest slot is needed and the classification cannot go stale independently of a recompile.

**Rejected alternatives:**
- **Blanket nullable typing** (`?ComponentAttributeBag` / `?ComponentSlot` everywhere, the issue's own first framing). Fixes the over-typing but adds a NEW false positive: `PossiblyNullReference` on an author's own `$attributes->merge()` in a class-component view that never writes `@props`, since `Component::data()` / `AnonymousComponent::data()` already guarantee the key there. Rejected in the issue itself before implementation started.
- **Directory/view-name classifier** (a view name under `components.` or a registered anonymous-component namespace/path, `ComponentTagCompiler::guessAnonymousComponentUsingNamespaces()`/`guessAnonymousComponentUsingPaths()`). More "principled" — it reflects how `<x-*>` actually resolves — but strictly worse in practice: it needs the view name threaded from `BladeBootstrapper` into `ShadowCompiler::compile()`, it cannot see class-component views outside `components/` (Laravel's own published `vendor/mail/**` overrides are exactly that shape, reached via `@component('mail::message')` **and** `<x-mail::message>`), and the view roots it would depend on are not part of `CompilerEnvironment::describe()`'s cache fingerprint, so a view-path config change would not invalidate cached shadows.
- **A `$slot` render-path union** (`ComponentSlot|HtmlString`, per the issue's "Render-path differentiation" addendum). Laravel has not produced `HtmlString` slots since before this plugin's floor (`illuminate/view: ^11.35`) — `ManagesComponents::componentData()` builds a `ComponentSlot` on both the `<x-*>` and `@component` paths, verified byte-identical across Laravel 11.50, 12.55, and 13.31. The union would be strictly weaker (losing `$slot->isEmpty()`/`$slot->attributes`) for no soundness gain; only `$attributes` is genuinely render-path-sensitive (absent on the `@component` path), and that distinction cannot be told apart statically from the template source alone — accepted as a documented gap rather than chased with cross-template tracking.

### Class component views are seeded at analysis time from `ClassLikeStorage` (#1804)

**Decision:** `Blade\ComponentViewRegistry` maps a component class to its view at `AfterCodebasePopulated`, from Psalm's class storage and the declaring `render()`'s cached AST. `Blade\ComponentViewSeedHandler` then writes the `Component::data()` types into the shadow's `Context` on the prelude's closing-tag `Stmt\Nop`. The shadow bytes never mention a class.

**Why:**
- Everything the map needs exists after population: `parent_classes`, `declaring_method_ids['render']` (inherited `render()` included), and the parsed method body via `getStatementsForFile()`. The registry is built once in the parent process, before workers fork, like `ModelMetadataRegistry`.
- Shadow freshness stays per template. The manifest fingerprints the template text and compiler environment only; a class change, or a `<x-…>` tag added in ANOTHER template, changes the seed without touching the shadow, and the seed is recomputed every run. A warm Psalm cache needs nothing extra on the CLI: `psalm` never runs in diff mode there (`Internal/Cli/Psalm.php`, `$is_diff = false`), so every shadow is re-analyzed against current storage (`ComponentViewTypingTest::a_warm_cache_follows_a_changed_property_type`). The language server is NOT covered: it sets `diff_methods = true` (`ProjectAnalyzer.php:361`) and re-analyzes only changed files and their recorded dependents, and no reference ties a shadow to the component class, so editing a class can leave its view's seed stale until the template itself changes.
- The closing-tag `Nop` is the earliest point that works: Psalm applies a statement's `@var` docblocks before analyzing it, so a seed placed in `BeforeFileAnalysis` or `BeforeStatementAnalysis` would be overwritten by the prelude's own `@var mixed` net.

**Rejected alternatives:**
- **Boot-time reflection** of every project class extending `Component`: autoloads arbitrary project files in the main process (a missing parent or trait is an `Error`), duplicates Psalm's own scan, and resolves inherited `render()` no better than `declaring_method_ids`.
- **Prelude text** (`@var` lines, or `render()`'s array copied into the shadow): makes one template's shadow depend on a class and on every other template's tags, so the fingerprint grows cross-file inputs; a copied expression also changes meaning (magic constants, imports, `self`/private scope) and duplicates its findings.
- **Next-run type cache**: cold and warm runs would report differently; the manifest changes speed, never results.
- **File-order tricks**: files are analyzed in worker chunks; producer-side types never cross workers.

### Composer-supplied view keys are read from the composer's AST (#1832)

**Decision:** A view reached by a composer or creator skips `MissingViewVariable` and `InvalidViewVariableType` only for the keys its composers pass. `Blade\ComposerSourceReader` finds the class and method at boot, recognising only the closure `Factory::buildClassEventCallback()` builds; `Blade\ComposerKeyResolver` reads the method body at `AfterCodebasePopulated` and collects the literal keys of `$view->with(...)`. A closure or a raw listener on the exact view name, a non-literal key, `$view` used as anything but the receiver of such a `with()` or of a read-only accessor, a `share()`, or a function that reaches `$view` by name or position (`extract()`, `get_defined_vars()`, `func_get_args()`, `func_get_arg()`, a `compact()` naming it or a non-literal name) makes the view unknown, which keeps the earlier whole-view skip.

**Why:** Skipping the whole view hid every real missing variable behind one unrelated composer key. The only error that costs the user anything is an under-approximated key set (a false positive on a correct call site), so the walker over-approximates and gives up on the first use of `$view` it cannot follow. Resolving before Psalm forks matches `ComponentViewRegistry` (#1804): workers inherit the result. A wildcard listener the composer API did not wrap is skipped as an observer: debugging and monitoring packages (Ray, Telescope, Debugbar) register one for every view, and reading it as an unknown composer turned both checks off for the whole app.

**Rejected alternatives:**
- **Invoking composers at analysis time** (what Bladestan does): runs arbitrary application code in the analysis process, needs request-time services (auth, session, database) that are not there, and its answer is the composer's runtime values rather than a key set read from code, so it cannot back an under-approximation guarantee.
- **A config list of keys per view or global** (`<composerKeys>`): duplicates what the composer already says in code, goes stale silently, and puts the maintenance on every user for a problem the AST answers.
- **Following `$view` into helper methods**: needs inter-procedural tracking of aliases and escapes for a rare shape; declining is correct and cheap.
- **Modelling every way the class or its keys can change** (container bindings, extenders, before-resolving hooks, `#[Bind]`, factory and dispatcher subclasses, constructor bodies, trait `insteadof`, method visibility, `call_user_func()`, `include`): an earlier draft did, at three times the size, for setups ordinary apps do not have. Their residual is a rare false positive with a documented fix.

**Accepted limitation:** the class is read as written. A container rebinding, extender or before-resolving hook, a factory or dispatcher subclass, a constructor that shares data, a protected method served by `__call()`, a `share()` hidden in a helper or an injected dependency, and view data added by a wildcard listener registered straight on the dispatcher (taken for an observer, such as a debugging package's view watcher, and skipped) are not seen; if the real keys differ, the call site reports, and the fix is `= null` on the declaration or a suppression. Composers registered after boot (middleware, a controller) are not read: a view whose only composer is registered at runtime is checked as if it had none, while a view with a boot composer plus a runtime one is checked against the boot keys only.

### `@aware`'s list-form arm is gated at issue emission, not widened in a stub

**Decision:** `ShadowIssueRelocator::isGeneratedAwareArgument()` drops argument 1 and string-cast issues on `$__value` in the generated list-form `$__env->getConsumableComponentData($__value)` call, and `NoValue` on `$__key` in the keyed call, only when that call text is absent from the raw template (#1695; gate from #1708, keyed `NoValue` arm from #1716). `Factory::getConsumableComponentData()` keeps Laravel's own `string $key`.

**Why:** the finding is a correlation gap, not a wrong signature. `compileAware()` picks the arm by `is_string($__key)`, and Psalm does not tie that ternary to the key it narrowed, so a keyed non-string default reaches an arm that runs only for an int key. The fault lives in one compiled shape, so the fix belongs where that shape is recognised. Residuals: [Compiler-generated code](../blade.md#compiler-generated-code).

**Rejected alternatives:**
- **A `string|null $key` stub.** Silences the `null` default from the original report, but hides a real `null` passed to a hand-written call in any PHP file. It also covers only `null`: `false`, `0`, `[]` defaults and the keyed arm's `NoValue` keep reporting.

## Per-Run Overrides

### One internal transport, bool and enum keys only

**Decision:** `analyze --plugin-option=KEY=VALUE` hands its tokens to the child psalm in `PSALM_LARAVEL_INTERNAL_OPTIONS` (whitespace-split, last wins), overwriting any inherited value; the plugin reads only that variable. Path settings (`configDirectory`, `<blade cacheDir>`) stay XML-only, so no value contains whitespace.

**Why internal, not a public env var:**
- A bare `vendor/bin/psalm` run with it would bypass the `--no-reference-cache` gate below.
- An exported variable silently changes every later run (shell profile, CI env), a config drift nobody sees in `psalm.xml`.
- Publishing it later is non-breaking; withdrawing a public contract is not.

**Rejected alternative:** a private JSON variable for CLI values plus env layer merging (#1709). It existed only to carry whitespace in paths and an env layer, and needed its own validation, forgery handling, and list semantics for `configDirectory`.

### Every plugin setting is report-only for Psalm's result cache, except `blade` (file-reference cache)

**Decision:** All keys of the override schema (`PluginOverrides::KEYS`) are classified report-only except `blade`. `psalm-laravel analyze` prepends `--no-reference-cache` whenever `--blade`/`--no-blade` or `--plugin-option` sets `blade`, even next to a user `--no-cache`: Psalm's getopt stops at the first positional, so the wrapper cannot tell which later flags Psalm parses, and Psalm accepts the duplicate. A future key that changes what Psalm persists at scan time (a scan-time `AfterClassLikeVisit`-style hook, stub selection, a `FileStorage`/`ClassLikeStorage` write) must be classified before it joins the schema, and needs the same wrapper treatment (`--no-reflection-cache` for storage writes).

**Why:** Psalm's cache key hashes the config file, `composer.lock` and Psalm's own storage classes, and the providers are built before any plugin runs, so a per-run override cannot change it. That only matters for state Psalm PERSISTS, and it persists storage only at scan time (`Scanner.php`, `ReflectorVisitor` via `Codebase`). Every handler this plugin gates on a setting runs at analysis time or in `AfterCodebasePopulated` (after the scan, never persisted); all `AfterClassLikeVisit` handlers register unconditionally and read no setting; stub selection depends on no setting except the plugin cache path.

Evidence (Psalm 7.0.0-rc1, laravel/framework 13.34, `--threads=1` and `--threads=4 --scan-threads=4`; each state compared with its own `--no-cache` run, sorted output):
- Every key flipped on/off/on/off against one cache dir (both directions; the reference diffs were 120–190 lines, so the comparison is sensitive): identical to `--no-cache` at every step, except `blade`.
- Positive control: a probe `AfterClassLikeVisit` handler gated on an env var went stale on the first warm run, so the method can detect a storage-affecting key.
- `blade` on→off→on with `<blade />` and dead-code detection: the off run (warm) loses `UnusedClass` for classes only templates reference. The ON run persists the shadows' references (they are analysed through `addFilesToAnalyze`); the OFF run does not analyse the shadows, nothing overwrites those references, and dead-code consolidation still counts the classes as used. Fixed only by `--no-reference-cache` (not `--no-file-cache`, not `--no-reflection-cache`). `blade` is the sole key that changes the ANALYSED FILE SET under an identical config hash; `blade.*` sub-settings change nothing once Blade is on.

**Rejected alternatives:**
- **Plugin-side cache wipe on a settings fingerprint change.** Works in a probe, but `Config::removeCacheDirectory()` deadlocks in-process (it takes `LOCK_EX` on files this process holds `LOCK_SH` on), the hand-rolled variant hard-codes Psalm's internal directory names, wipes caches of other configs, and races between concurrent runs sharing one cache dir.
- **Blanket `--no-cache` advice or injection.** False for every key but `blade`; costs a full cold run per invocation.
- **Upstream cache-key hook** (plugin contribution known before `initProviders`): worth an upstream issue, nothing needs it today.

Known separate defect, not caused by overrides: a Blade shadow with a syntax error loses its `ParseError` on a warm run (the parser cache replays the broken shadow's statements); it reproduces with the XML `<blade />` alone.

### MorphTo relation generics use Psalm's declared type

**Decision:** The AST relation parser does not reconstruct a `MorphTo` related-model generic from raw PHPDoc. Since its target model is selected at runtime and the parser has no static factory class-string to resolve, `relationType()` declines it; Psalm's declared return type supplies a documented generic such as `MorphTo<Model&HasTitle, self>`.

**Why:** Psalm 7 preserves the declared intersection on external calls to an autoloaded model and resolves aliases, traits, inheritance, and receiver context. Reconstructing the first generic slot from raw PHPDoc produced `App\Models\Model&HasTitle` and an `UndefinedClass` issue in the #1753 regression. Property access already reads the generic from Psalm's effective relation type, so its separate raw-PHPDoc fallback duplicated the same broken resolution path.

**Rejected:** A broader provider gate that declined for all explicit generic relation returns. It changed established non-MorphTo behavior: partial generic declarations with defaulted slots and `$this` receiver cases rely on AST binding to preserve concrete relation templates.

**Callback consumers:** `RelationCallbackParamsHandler` keeps using the AST-only `relationType()` helper for relation objects passed to eager-load callbacks. It explicitly declines `MorphTo` before eager resolution, so removing raw MorphTo reconstruction does not change callback relation typing.
