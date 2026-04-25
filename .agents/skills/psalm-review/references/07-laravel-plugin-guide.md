# Document 7: Practical Guide to Developing a Laravel Psalm Plugin

*Personal recommendations and real patterns for handling Eloquent and Facade magic*

---

## Why Laravel Needs a Plugin

Laravel is built on conventions and magic. Eloquent models use `__get`/`__set` for database columns, facades use `__callStatic` to proxy to service container bindings, the service container resolves classes dynamically, and Blade compiles to PHP behind the scenes. None of this is visible to a static analyzer out of the box.

Without a plugin, Psalm sees:
- `$user->email` → UndefinedPropertyFetch (no `$email` property declared)
- `Cache::get('key')` → UndefinedMethod (Facade doesn't have `get()`)
- `app(UserService::class)` → returns `mixed` (container binding unknown)
- `User::where('active', true)->first()` → returns `Model|null` (not `User|null`)

A plugin teaches Psalm about these patterns so it can provide real type safety for Laravel applications.

## Strategy: Scan-Time vs. Analysis-Time

The most important architectural decision is **when** to provide type information:

### Scan-Time (AfterClassLikeVisitInterface)

**Use for**: Things you know from looking at the class definition alone.

- Model properties derived from `$casts`, `$fillable`, `$dates`
- Scope methods (any method starting with `scope` becomes a query builder method)
- Relationship return types (derived from relationship method return type declarations)
- Accessor/mutator methods (`getNameAttribute` → `$name` property)

**Advantage**: Information is stored in `ClassLikeStorage`, inherited by child classes via the Populator, and cached.

**Limitation**: You only see the class's own source code. You can't check the full inheritance chain yet, and you can't see what other classes exist.

### Analysis-Time (Type Providers)

**Use for**: Things that depend on how code is called, not just how classes are defined.

- `User::find($id)` → should return `User|null`, not `Model|null`
- `$query->where('active', true)` → should return `Builder<User>`, not `Builder<Model>`
- `app(UserService::class)` → should return `UserService`
- `config('app.name')` → should return `string|null`

**Advantage**: Full context: you know the calling class, the arguments, and current type state.

**Limitation**: Not cached, runs every analysis. Must be fast.

### Rule of Thumb

> If the information comes from the class's source code → scan-time.
> If the information depends on how the code is used → analysis-time.

## Handling Eloquent Models

Eloquent is the biggest challenge. Here's how to approach each magic feature:

### 1. Database Column Properties

**Problem**: `$user->email` — no declared property, Psalm reports `UndefinedPropertyFetch`.

You need **two** providers working together:
- `PropertyExistenceProviderInterface` — tells Psalm the property exists (prevents `UndefinedPropertyFetch`)
- `PropertyTypeProviderInterface` — tells Psalm the property's type

Without the existence provider, Psalm reports the error before the type provider even gets called.

**Approach A: PropertyTypeProvider + PropertyExistenceProvider (analysis-time)**

Best when you want dynamic resolution based on actual database schema or complex logic:

```php
class ModelPropertyExistence implements PropertyExistenceProviderInterface
{
    public static function getClassLikeNames(): array
    {
        return ['Illuminate\\Database\\Eloquent\\Model'];
    }

    public static function doesPropertyExist(PropertyExistenceProviderEvent $event): ?bool
    {
        $property_name = $event->getPropertyName();
        $model_class = $event->getFqClasslikeName();
        $source = $event->getSource();
        if ($source === null) {
            return null;
        }
        $codebase = $source->getCodebase();

        $storage = $codebase->classlike_storage_provider->get($model_class);
        $columns = $storage->custom_metadata['laravel_columns'] ?? [];
        return isset($columns[$property_name]) ? true : null;
    }
}

class ModelPropertyType implements PropertyTypeProviderInterface
{
    public static function getClassLikeNames(): array
    {
        return ['Illuminate\\Database\\Eloquent\\Model'];
    }

    public static function getPropertyType(PropertyTypeProviderEvent $event): ?Union
    {
        $property_name = $event->getPropertyName();
        $model_class = $event->getFqClasslikeName();

        // getSource() can return null — always check
        $source = $event->getSource();
        if ($source === null) {
            return null;
        }
        $codebase = $source->getCodebase();

        // Quick reject: if property is explicitly declared, let Psalm handle it
        $storage = $codebase->classlike_storage_provider->get($model_class);
        if (isset($storage->properties[$property_name])) {
            return null;
        }

        // Check custom metadata (set during scanning)
        // Use flat keys for custom_metadata (it only supports scalar values)
        $columns = $storage->custom_metadata['laravel_columns'] ?? [];
        if (!isset($columns[$property_name])) {
            return null; // Unknown column — let Psalm report the error
        }

        return self::columnTypeToUnion($columns[$property_name]);
    }

    private static function columnTypeToUnion(string $column_type): Union
    {
        return match ($column_type) {
            'string', 'text' => new Union([new TString(), new TNull()]),
            'integer', 'bigint' => new Union([new TInt()]),
            'boolean' => new Union([new TBool()]),
            'datetime', 'timestamp' => new Union([
                new TNamedObject('Carbon\\Carbon'),
                new TNull(),
            ]),
            'json', 'array' => new Union([
                new TArray([new Union([new TArrayKey()]), new Union([new TMixed()])]),
            ]),
            default => new Union([new TMixed()]),
        };
    }
}
```

**Approach B: AfterClassLikeVisit (scan-time)**

Best when you can determine properties from the class's `$casts` array:

```php
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;

class ModelPropertyScanner implements AfterClassLikeVisitInterface
{
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $storage = $event->getStorage();

        // Check if this extends Model (directly or via a base class).
        // At scan time, parent_classes may not be fully populated yet,
        // so check parent_class for direct parent. For production code,
        // use AfterCodebasePopulated to build a full model-descendant map.
        if (!isset($storage->parent_classes['illuminate\database\eloquent\model'])) {
            // Fallback: check direct parent
            if ($storage->parent_class !== 'Illuminate\\Database\\Eloquent\\Model') {
                return;
            }
        }

        // getStmt() returns the class AST node (singular, not plural)
        $class_node = $event->getStmt();
        $casts = self::extractCastsFromAst($class_node->stmts ?? []);

        // Store in metadata using flat keys (custom_metadata only supports scalar values)
        $storage->custom_metadata['laravel_casts'] = $casts;

        // Add properties directly to storage
        foreach ($casts as $name => $cast_type) {
            if (!isset($storage->properties[$name])) {
                $property = new \Psalm\Storage\PropertyStorage();
                $property->type = self::castTypeToUnion($cast_type);
                $property->visibility = ClassLikeAnalyzer::VISIBILITY_PUBLIC;
                $storage->properties[$name] = $property;
                $storage->declaring_property_ids[$name] = $storage->name;
                $storage->appearing_property_ids[$name] = $storage->name;
            }
        }
    }

    private static function extractCastsFromAst(array $stmts): array
    {
        $casts = [];
        foreach ($stmts as $stmt) {
            if (!$stmt instanceof \PhpParser\Node\Stmt\Property) {
                continue;
            }
            foreach ($stmt->props as $prop) {
                if ($prop->name->name !== 'casts'
                    || !$prop->default instanceof \PhpParser\Node\Expr\Array_) {
                    continue;
                }
                foreach ($prop->default->items as $item) {
                    if ($item !== null
                        && $item->key instanceof \PhpParser\Node\Scalar\String_
                        && $item->value instanceof \PhpParser\Node\Scalar\String_) {
                        $casts[$item->key->value] = $item->value->value;
                    }
                }
            }
        }
        return $casts;
    }

    private static function castTypeToUnion(string $cast): Union
    {
        return match ($cast) {
            'int', 'integer' => new Union([new TInt()]),
            'bool', 'boolean' => new Union([new TBool()]),
            'string' => new Union([new TString()]),
            'float', 'double', 'real' => new Union([new TFloat()]),
            'array', 'json' => new Union([
                new TArray([new Union([new TArrayKey()]), new Union([new TMixed()])]),
            ]),
            'datetime', 'date', 'timestamp' => new Union([
                new TNamedObject('Carbon\\Carbon'),
                new TNull(),
            ]),
            'collection' => new Union([
                new TGenericObject('Illuminate\\Support\\Collection', [
                    new Union([new TArrayKey()]),
                    new Union([new TMixed()]),
                ]),
            ]),
            default => new Union([new TMixed()]),
        };
    }
}
```

### 2. Eloquent Scopes

**Problem**: Model has `scopeActive(Builder $query)`, but users call `User::active()` or `$query->active()`. Psalm doesn't know `active()` exists on the Builder.

**Approach**: During scanning, find all `scope*` methods and store them for the Builder method existence provider:

```php
class ScopeMethodRegistrar implements AfterClassLikeVisitInterface
{
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $storage = $event->getStorage();

        if ($storage->parent_class !== 'Illuminate\\Database\\Eloquent\\Model') {
            return;
        }

        // IMPORTANT: method names in $storage->methods are LOWERCASE.
        // To get the original casing, use MethodStorage::$cased_name
        $scope_names = [];
        foreach ($storage->methods as $method_name_lc => $method_storage) {
            if (str_starts_with($method_name_lc, 'scope') && strlen($method_name_lc) > 5) {
                // Use cased_name to preserve original casing: scopeActiveUsers → activeUsers
                $cased = $method_storage->cased_name ?? $method_name_lc;
                $scope_name = lcfirst(substr($cased, 5));
                $scope_names[] = $scope_name;
            }
        }

        // Store scope names for the Builder method existence provider
        $storage->custom_metadata['laravel_scopes'] = $scope_names;
    }
}
```

Then in a `MethodExistenceProvider` for Builder, check if the method name matches a scope.

### 3. Eloquent Relationships

**Problem**: `$user->posts` returns `Collection<Post>`, `$user->posts()` returns `HasMany<Post>`. These are magic based on method return types.

**Approach**: During scanning, find relationship methods and register corresponding properties:

```php
// In AfterClassLikeVisit handler:
foreach ($storage->methods as $method_name => $method_storage) {
    $return_type = $method_storage->return_type;
    if ($return_type === null) {
        continue;
    }

    // Check if return type is a relationship (HasMany, BelongsTo, etc.)
    foreach ($return_type->getAtomicTypes() as $atomic) {
        if ($atomic instanceof TGenericObject
            && self::isRelationshipClass($atomic->value)
        ) {
            // The generic param is the related model
            $related_model = $atomic->type_params[0] ?? null;
            if ($related_model === null) {
                continue;
            }

            // Add $user->posts as Collection<int, Post>
            $property = new \Psalm\Storage\PropertyStorage();
            $property->type = self::relationToPropertyType($atomic->value, $related_model);
            $property->visibility = ClassLikeAnalyzer::VISIBILITY_PUBLIC;
            $storage->properties[$method_name] = $property;
            $storage->declaring_property_ids[$method_name] = $storage->name;
            $storage->appearing_property_ids[$method_name] = $storage->name;
        }
    }
}
```

### 4. Query Builder Chaining

**Problem**: `User::where('active', true)->orderBy('name')->first()` — each method should return `Builder<User>`, and `first()` should return `User|null`.

**Approach**: Use a `MethodReturnTypeProvider` for Builder:

```php
class BuilderReturnTypeProvider implements MethodReturnTypeProviderInterface
{
    public static function getClassLikeNames(): array
    {
        return ['Illuminate\\Database\\Eloquent\\Builder'];
    }

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        // getMethodNameLowercase() always returns lowercase — compare with lowercase
        $method_name = $event->getMethodNameLowercase();

        // Get the model type from Builder<TModel>'s template param
        $template_types = $event->getTemplateTypeParameters();
        $model_type = $template_types[0] ?? null;
        if ($model_type === null) {
            return null;
        }

        // Chaining methods: return Builder<TModel>
        $chaining_methods = ['where', 'orwhere', 'orderby', 'limit', 'offset',
                            'groupby', 'having', 'with', 'withcount', 'select'];
        if (in_array($method_name, $chaining_methods, true)) {
            return new Union([
                new TGenericObject('Illuminate\\Database\\Eloquent\\Builder', [$model_type]),
            ]);
        }

        // Terminal methods
        return match ($method_name) {
            'first', 'sole' => Type::combineUnionTypes(
                $model_type,
                new Union([new TNull()]),
            ),
            'firstorfail', 'soleorabort' => clone $model_type,
            'get', 'all' => new Union([
                new TGenericObject('Illuminate\\Database\\Eloquent\\Collection', [
                    new Union([new TInt()]),
                    $model_type,
                ]),
            ]),
            'count' => new Union([new TInt()]),
            'exists', 'doesntexist' => new Union([new TBool()]),
            'paginate' => new Union([
                new TNamedObject('Illuminate\\Pagination\\LengthAwarePaginator'),
            ]),
            // sum/avg/min/max have complex return types depending on the column
            // — fall back to declared return type for accuracy
            default => null,
        };
    }
}
```

**Performance tip**: For hot-path methods like `where()` that are called many times per file, consider caching the constructed `Union` objects in static properties to reduce object allocation:

```php
private static array $builder_cache = [];

// In getMethodReturnType:
$cache_key = $model_type->getId();
if (!isset(self::$builder_cache[$cache_key])) {
    self::$builder_cache[$cache_key] = new Union([
        new TGenericObject('Illuminate\\Database\\Eloquent\\Builder', [$model_type]),
    ]);
}
return self::$builder_cache[$cache_key];
```

## Handling Facades

Facades are `__callStatic` proxies to service container bindings. The challenge: `Cache::get('key')` actually calls `CacheManager::get('key')`.

For facades, you need three providers working together:
- `MethodExistenceProviderInterface` — tells Psalm the method exists
- `MethodReturnTypeProviderInterface` — provides the return type
- `MethodVisibilityProviderInterface` — reports methods as public (underlying methods may be protected)

### Approach: Resolve the Underlying Class

```php
class FacadeMethodProvider implements MethodReturnTypeProviderInterface
{
    // In production, build this map dynamically by reading getFacadeAccessor()
    public static array $facade_map = [
        'Illuminate\\Support\\Facades\\Cache' => 'Illuminate\\Cache\\CacheManager',
        'Illuminate\\Support\\Facades\\DB' => 'Illuminate\\Database\\DatabaseManager',
        'Illuminate\\Support\\Facades\\Log' => 'Psr\\Log\\LoggerInterface',
        'Illuminate\\Support\\Facades\\Auth' => 'Illuminate\\Auth\\AuthManager',
        // ... etc
    ];

    public static function getClassLikeNames(): array
    {
        return array_keys(self::$facade_map);
    }

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $facade_class = $event->getFqClasslikeName();
        $method_name = $event->getMethodNameLowercase();
        // getSource() on MethodReturnTypeProviderEvent is non-nullable
        $codebase = $event->getSource()->getCodebase();

        $underlying_class = self::$facade_map[$facade_class] ?? null;
        if ($underlying_class === null || !$codebase->classOrInterfaceExists($underlying_class)) {
            return null;
        }

        // Look up the method on the underlying class
        $method_id = new \Psalm\Internal\MethodIdentifier($underlying_class, $method_name);
        if (!$codebase->methods->methodExists($method_id)) {
            return null;
        }

        $method_storage = $codebase->methods->getStorage($method_id);
        return $method_storage->return_type;
    }
}
```

**Better approach for production**: Instead of a hardcoded map, read the `getFacadeAccessor()` method from each Facade class during scanning and resolve the binding dynamically. The existing `psalm-plugin-laravel` does this — study its `FacadeHandler`.

### Facade Method Existence

```php
class FacadeMethodExistence implements MethodExistenceProviderInterface
{
    public static function getClassLikeNames(): array
    {
        return array_keys(FacadeMethodProvider::$facade_map);
    }

    public static function doesMethodExist(MethodExistenceProviderEvent $event): ?bool
    {
        $facade_class = $event->getFqClasslikeName();
        $method_name = $event->getMethodNameLowercase();

        // getSource() on MethodExistenceProviderEvent is NULLABLE — must check
        $source = $event->getSource();
        if ($source === null) {
            return null;
        }
        $codebase = $source->getCodebase();

        $underlying_class = FacadeMethodProvider::$facade_map[$facade_class] ?? null;
        if ($underlying_class === null) {
            return null;
        }

        $method_id = new \Psalm\Internal\MethodIdentifier($underlying_class, $method_name);
        return $codebase->methods->methodExists($method_id) ?: null;
    }
}
```

## Handling the Service Container

**Problem**: `app(UserService::class)` should return `UserService`, not `mixed`.

```php
class ContainerResolveProvider implements FunctionReturnTypeProviderInterface
{
    public static function getFunctionIds(): array
    {
        return ['app', 'resolve'];
    }

    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        $args = $event->getCallArgs();

        // No args: app() returns Application
        if (empty($args)) {
            return new Union([
                new TNamedObject('Illuminate\\Contracts\\Foundation\\Application'),
            ]);
        }

        // Get the type of the first argument
        $source = $event->getStatementsSource();
        $first_arg_type = $source->getNodeTypeProvider()->getType($args[0]->value);

        if ($first_arg_type === null) {
            return null;
        }

        // If it's a class-string literal like UserService::class
        foreach ($first_arg_type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof \Psalm\Type\Atomic\TLiteralClassString) {
                return new Union([new TNamedObject($atomic->value)]);
            }
            if ($atomic instanceof \Psalm\Type\Atomic\TClassString
                && $atomic->as !== 'object') {
                return new Union([new TNamedObject($atomic->as)]);
            }
        }

        return null;
    }
}
```

## Common Pitfalls

### 1. Forgetting declaring_*_ids

When adding methods or properties to `ClassLikeStorage`, you must also set the declaring/appearing IDs:

```php
// WRONG: just adding to $methods
$storage->methods['mymethod'] = $method_storage;

// RIGHT: also set declaring and appearing IDs
$storage->methods['mymethod'] = $method_storage;
$storage->declaring_method_ids['mymethod'] = new \Psalm\Internal\MethodIdentifier(
    $storage->name,
    'mymethod',
);
$storage->appearing_method_ids['mymethod'] = new \Psalm\Internal\MethodIdentifier(
    $storage->name,
    'mymethod',
);
```

Without these, Psalm may not find the method during analysis or may report confusing errors.

### 2. Case Sensitivity

Method names in Psalm storage are **lowercase**. Property names are **case-sensitive**. When looking up or storing:

```php
// Methods: always lowercase keys
$storage->methods['getname'] = ...; // NOT 'getName'
// Use MethodStorage::$cased_name to preserve display casing

// Properties: keep original case
$storage->properties['emailAddress'] = ...; // Keep camelCase
```

### 3. Not Handling Inheritance

If you register a `MethodReturnTypeProvider` for `Model`, it fires for ALL subclasses. Use `$event->getFqClasslikeName()` to get the actual class, not `Model`:

```php
// WRONG: always returns Model
return new Union([new TNamedObject('Illuminate\\Database\\Eloquent\\Model')]);

// RIGHT: returns the actual class (User, Post, etc.)
return new Union([new TNamedObject($event->getFqClasslikeName())]);
```

### 4. Only Checking Direct Parent Class

```php
// WRONG: misses models that extend a base model class
if ($storage->parent_class !== 'Illuminate\\Database\\Eloquent\\Model') {
    return;
}

// RIGHT: check the full ancestor chain (lowercase keys)
if (!isset($storage->parent_classes['illuminate\database\eloquent\model'])) {
    return;
}
```

Most real Laravel apps have intermediate base classes (`App\Models\BaseModel extends Model`). Always use `parent_classes` (plural, lowercase keys) instead of `parent_class` for inheritance checks.

### 5. Expensive Operations in Providers

Type providers run for EVERY matching method/property access. Keep them fast:

```php
public static function getMethodReturnType(...): ?Union
{
    // GOOD: quick reject first
    $method = $event->getMethodNameLowercase();
    if ($method !== 'find' && $method !== 'findorfail') {
        return null; // Fast path — don't do expensive work
    }

    // Now do the expensive lookup...
}
```

### 6. Static State Across Workers

Covered in Document 6, but bears repeating: **static arrays in your plugin are per-worker**. If you accumulate data during analysis, it won't be available in the parent process. Use `ClassLikeStorage::$custom_metadata` for data that needs to persist.

### 7. custom_metadata Constraints

`ClassLikeStorage::$custom_metadata` supports **scalar values** and **nested arrays of scalars** (up to 5 levels deep). You cannot store objects (like `Union` instances) — only strings, ints, bools, floats, and arrays thereof:

```php
// OK: scalar values and nested arrays
$storage->custom_metadata['laravel_columns'] = ['email' => 'string', 'age' => 'integer'];
$storage->custom_metadata['laravel_table'] = 'users';
$storage->custom_metadata['laravel_config'] = [
    'casts' => ['email' => 'string'],
    'fillable' => ['name', 'email'],
]; // nested arrays of scalars — fully supported

// NOT OK: storing objects
$storage->custom_metadata['type'] = new Union([new TString()]); // WILL FAIL on serialization
```

### 8. Stub Files vs. Type Providers

Use stubs for **stable type information** that doesn't change per call site:
```php
// stubs/Collection.phpstub — good for stubs:
/** @template TKey @template TValue */
class Collection {
    /** @return TValue|null */
    public function first() {}
}
```

Use type providers for **dynamic types** that depend on the call context:
```php
// Type provider — good for dynamic resolution:
// User::find(1) → User|null
// Post::find(1) → Post|null
```

**Precedence**: If a stub declares a method AND a type provider handles it, the type provider's return type takes priority. The stub still matters for parameter types and method existence. Avoid declaring the same method in both a stub and a type provider — it leads to confusing behavior.

## Registering Everything in the Plugin Entry Point

Don't forget to register all your handlers:

```php
class LaravelPlugin implements PluginEntryPointInterface
{
    public function __invoke(PluginRegistrationSocket $registration, ?SimpleXMLElement $config = null): void
    {
        // Scan-time hooks
        $registration->registerHooksFromClass(ModelPropertyScanner::class);
        $registration->registerHooksFromClass(ScopeMethodRegistrar::class);

        // Analysis-time providers
        $registration->registerHooksFromClass(ModelPropertyExistence::class);
        $registration->registerHooksFromClass(ModelPropertyType::class);
        $registration->registerHooksFromClass(BuilderReturnTypeProvider::class);
        $registration->registerHooksFromClass(FacadeMethodProvider::class);
        $registration->registerHooksFromClass(FacadeMethodExistence::class);
        $registration->registerHooksFromClass(ContainerResolveProvider::class);

        // Stub files
        $registration->addStubFile(__DIR__ . '/../stubs/Model.phpstub');
        $registration->addStubFile(__DIR__ . '/../stubs/Builder.phpstub');
    }
}
```

## Testing Your Plugin

### Unit Tests with Psalm's Test Traits

For external plugins, require `vimeo/psalm` as a dev dependency:

```php
class EloquentModelTest extends \Psalm\Tests\TestCase
{
    use \Psalm\Tests\Traits\ValidCodeAnalysisTestTrait;
    use \Psalm\Tests\Traits\InvalidCodeAnalysisTestTrait;

    public function providerValidCodeParse(): iterable
    {
        yield 'model_find_returns_nullable_model' => [
            'code' => '<?php
                namespace App\Models;

                use Illuminate\Database\Eloquent\Model;

                class User extends Model {
                    protected $casts = ["email" => "string"];
                }

                function test(): void {
                    $user = User::find(1);
                    if ($user !== null) {
                        /** @psalm-check-type-exact $user = User */
                        echo $user->email;
                    }
                }
            ',
            'assertions' => [],
        ];
    }

    public function providerInvalidCodeParse(): iterable
    {
        yield 'model_undefined_property' => [
            'code' => '<?php
                namespace App\Models;

                use Illuminate\Database\Eloquent\Model;

                /** @psalm-seal-properties */
                class User extends Model {}

                function test(User $user): void {
                    echo $user->nonexistent;
                }
            ',
            'error_message' => 'UndefinedPropertyFetch',
        ];
    }
}
```

## Recommended Development Workflow

1. **Start with `--threads=1 --no-cache`** during development
2. **Write a failing test first** — create a test case with the Laravel code pattern you want to support
3. **Implement the simplest handler** that makes the test pass
4. **Add edge cases** — null checks, inheritance, generics
5. **Test with a real Laravel app** — install your plugin and run it against a real project
6. **Profile with `--debug`** — check that your hooks aren't slowing things down
7. **Enable cache** and verify your plugin works correctly with cached data

## Where to Look in psalm-plugin-laravel

If you're extending the existing plugin, study these key areas:

- **Plugin entry point**: How handlers and stubs are registered
- **Model handler**: How it reads `$casts`, `$fillable`, `$dates` from AST and populates ClassLikeStorage
- **Facade handler**: How it resolves `getFacadeAccessor()` to find the underlying service
- **Collection stubs**: How generics are declared for Eloquent collections
- **Builder stubs**: How the query builder maintains the model's generic type through method chains

The existing plugin is well-structured. Your contributions will likely be adding new method handlers, improving type precision for specific Laravel features, or handling new Laravel versions.

---

*This document is part of the [Psalm Internals series](01-architecture-overview.md). Read Documents 1-6 first for the foundational knowledge.*
