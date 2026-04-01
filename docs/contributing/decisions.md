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

## Static inference over runtime reflection

**Decision:** Prefer deriving types from Psalm's `ClassLikeStorage` and source code analysis. Use runtime reflection (booting the Laravel app via Testbench) only when the needed information is unavailable statically.

**Currently runtime:** Model table names (`getTable()`), model casts (`getCasts()`), container bindings, facade alias resolution.

**Currently static:** Relationships, accessors, migration schema parsing, stub overrides.

**Why:** Runtime reflection requires booting a real Laravel app, which adds startup cost, can fail in misconfigured projects, and couples the plugin to the user's environment. Static inference is faster, more predictable, and works in CI without a running app. But some Laravel conventions (dynamic table names, programmatic casts, container bindings) are only knowable at runtime.

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

**Why:** Accessor and relationship properties are discovered from the model's own method signatures — they don't depend on migration files. A user with `columnFallback="none"` still expects `$user->roles = $collection` to work when `sealAllProperties` is enabled. This is consistent with the read-side handlers, which are also unconditional (see below).

**See:** [#446](https://github.com/psalm/psalm-plugin-laravel/issues/446)

### Model property handlers always run, no per-handler config toggles

**Decision:** `ModelRelationshipPropertyHandler` and `ModelPropertyAccessorHandler` are always registered. Only `ModelPropertyHandler` (migration-based column inference) is gated by the `modelProperties` config.

**Why:** The relationship and accessor handlers use Psalm's own type inference with no external data source.
They produce no false positives, and there's no real-world scenario where a user would want one but not the other. Exposing per-handler toggles adds config complexity without value. The `@property` precedence rule (above) is the escape hatch for users who want to override specific properties.

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

**Example:** `sealAllProperties="false"` by default. `columnFallback="migrations"` (migration inference) by default.

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

## Third-Party Package Support

### Plugin covers Laravel framework only, not third-party packages

**Decision:** The plugin provides type support for `laravel/framework` (Illuminate namespace) and first-party packages that ship with a default Laravel install. Third-party packages (Sanctum, Cashier, Livewire, Filament, etc.) are out of scope unless their model subclasses are naturally discovered.

**Why:** Third-party packages evolve independently, have their own type stubs, and may ship their own Psalm plugins. Supporting them would multiply the maintenance surface. The plugin's model discovery will pick up any `Model` subclass in the scanned codebase (including vendor), and the generic handlers work for those. But package-specific magic (e.g. Livewire's component properties) belongs in a package-specific plugin.
