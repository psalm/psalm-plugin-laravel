# Architecture Decisions

Decisions made during development of the plugin. Contributors should follow these to keep the codebase consistent.

---

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
Registration order is preserved (relationship → factory → accessor → column).