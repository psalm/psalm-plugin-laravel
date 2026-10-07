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

### A returned `$this->morphTo()` takes its related model from the enclosing method's declared return

**Decision:** In a method declaring `@return MorphTo<X, …>`, a `$this->morphTo()` call that one of the method's own return statements yields, directly or as the root of a chain that keeps the relation (`->withTrashed()->withoutGlobalScopes()`), resolves to `MorphTo<X, receiver>` (`ModelRelationReturnTypeHandler::getEnclosingMorphToReturnType()`, #1091). The stub keeps `MorphTo<Model, static>`: the morph map picks the target at runtime, so nothing else can prove X, and without this a narrowed declaration raises MoreSpecificReturnType / LessSpecificReturnStatement (InvalidReturnType for `Model&Contract`).

- **One closure on the `HasRelationships` trait**, registered at the top of `ModelRegistrationHandler::afterCodebasePopulated()`. Return-type providers fall back to the declaring trait, so it fires for every model, including the non-autoloadable ones the per-model loop skips. The per-model `getReturnType()` cannot carry it: it never registers for those models, and its `$unionCache` key has no enclosing method.
- **Returned call only, matched by node identity.** The returns of the analyzed `ClassMethod` node (read off the `MethodAnalyzer` by reflection; Psalm has no getter) are walked with `BodyReturnCollectorVisitor` and `RelationMethodParser`'s chain walk, so returns in nested closures, arrow functions and anonymous classes do not count. A morphTo() that is assigned, passed on, or returned through a variable keeps the stub type: narrowing it would hide real errors (`onlyPost($this->morphTo('origin')->getRelated())` raises ArgumentTypeCoercion).
- **Only slot 1 of the declaration is read.** Slot 2 can still be an unresolved `self` (trait methods) or `static`; the declaring slot is the receiver's own node type, template arguments and `&static` included, and declines unless that is a single named object of the called class.
- **Declines:** any receiver other than `$this`; `parent::morphTo()`; `self::morphTo()` / `static::morphTo()` (Psalm analyzes them as a virtual `$this->morphTo()` node no return statement holds); closures and arrow functions (their own declaration applies); a chain call that replaces the relation (`->clone()`); any declaration other than exactly `MorphTo<X, …>` (parent or subclass relation, `|null`, native-only); and an X with an alternative that is not a named class or intersection of named classes including a Model subclass, or that has a `static` or template part.

**Accepted unsoundness** (same trust as the external-call path's docblock read): X is the user's docblock, unverified. A declaration naming the wrong models is believed, inside the method and by its callers (`MorphToEnclosingDeclaredTypeKnownLimitation`).

**Rejected:** a stub or variance change (`MorphTo<Model, static>` is all the stub can claim, and no variance makes `Model` fit a narrower X; #913); a morph-map-aware resolver (the map is runtime state and lists every morphable model, not the subset one relation targets).

**External-call path (#1753):** the call-site handler reads the same declaration through `RelationMethodParser::declaredMorphToRelatedModelType()`, from the declaring method's `MethodStorage::return_type` (a trait-hosted method declares on the trait). A regex over the raw docblock was tried first and dropped: it split on `|` only and namespace-prefixed a `Model&Contract` token into a bogus `App\Models\Model&Contract`. The `$this`-collapse that motivated it does not happen on Psalm 7.0.0-rc1: storage keeps the generics for `$this`, `self`, `static`, trait-hosted, `@phpstan-return`-only and `|null` declarations. Declines, so Psalm's declared return applies: a nullable or union return, a parent relation (`Relation`, `BelongsTo`) or a `MorphTo` subclass, and a slot 1 holding a template parameter, `static` / `$this`, or a trait's unresolved `self` / `parent` at any depth (`MorphTo<Box<static>, self>`). A provider result skips Psalm's type expansion, so those would leak unbound, and a nested `static` recurses in the expander until memory runs out.

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

## Handler Registration Order

### Property handler priority: relationship > factory > accessor > column

**Decision:** When registering property handlers per model in `ModelRegistrationHandler`, the order is: relationship properties first, then factory, then accessor, then migration columns. The first handler that returns a non-null result wins.

**Why:** A method named `posts()` that returns a `HasMany` relation should always be treated as a relationship property, even if a migration column named `posts` also exists. Similarly, an accessor `getFullNameAttribute()` should take priority over a `full_name` column. The order reflects specificity: relationships and accessors are explicit code the developer wrote; columns are inferred from migrations and serve as the fallback.

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
