# Document 5: Plugin System & Extension Points

*How to extend Psalm — focused on Laravel plugin development*

---

## Overview

Psalm's plugin system lets you extend its analysis capabilities. As a Laravel plugin developer, you'll use plugins to teach Psalm about Laravel's magic: Eloquent models with dynamic properties, facades, service container resolution, and more.

## Decision Flowchart: Which Hook Do I Use?

```
Need to add properties/methods to a class?
  → AfterClassLikeVisitInterface (scanning phase)

Need to compute a method's return type dynamically?
  → MethodReturnTypeProviderInterface (called during analysis, replaces return type)

Need to modify return type AFTER Psalm's own analysis ran?
  → AfterMethodCallAnalysisInterface (post-analysis adjustment)

Need to tell Psalm a magic method exists?
  → MethodExistenceProviderInterface

Need to tell Psalm a magic property exists and its type?
  → PropertyExistenceProviderInterface + PropertyTypeProviderInterface

Need to suppress false positive issues?
  → BeforeAddIssueInterface

Need the full inheritance graph before doing anything?
  → AfterCodebasePopulatedInterface (runs after Populator)
```

**MethodReturnTypeProvider vs. AfterMethodCallAnalysis**: Use the type provider when you want to **replace** the return type entirely (it runs BEFORE Psalm analyzes the method call). Use `AfterMethodCallAnalysis` when you want to **adjust** the return type after Psalm has already done its own analysis. Type providers have higher priority — if a type provider returns a value, `AfterMethodCallAnalysis` still fires but with the provider's type as the candidate.

## Plugin Lifecycle

```
1. REGISTRATION (psalm.xml loads your plugin)
   │
   ▼
2. SCANNING PHASE
   ├── AfterClassLikeVisitInterface    ← modify class storage during scanning
   │                                      (runs in workers if parallel scanning)
   └── AfterCodebasePopulatedInterface ← bulk modifications after scanning
                                          (runs in parent, single-threaded)
3. ANALYSIS PHASE
   ├── Before/AfterFileAnalysisInterface
   ├── Before/AfterStatementAnalysisInterface
   ├── Before/AfterExpressionAnalysisInterface
   ├── AfterMethodCallAnalysisInterface
   ├── AfterFunctionCallAnalysisInterface
   ├── AfterEveryFunctionCallAnalysisInterface  ← includes PHP builtins
   ├── AfterFunctionLikeAnalysisInterface
   ├── AfterClassLikeAnalysisInterface
   ├── AfterClassLikeExistenceCheckInterface
   │
   └── TYPE PROVIDERS (called on-demand)
       ├── Method{ReturnType,Existence,Visibility,Params}ProviderInterface
       ├── Property{Type,Existence,Visibility}ProviderInterface
       └── Function{ReturnType,Existence,Params}ProviderInterface

4. POST-ANALYSIS
   ├── BeforeAddIssueInterface         ← suppress/modify issues
   └── AfterAnalysisInterface          ← final processing
```

### Event Handler Ordering

When multiple plugins register for the same event:
- Handlers are called in registration order
- For type providers: the **first non-null return wins** — subsequent providers are not called
- For `BeforeAddIssueInterface`: returning `true` (keep) or `false` (suppress) stops other handlers
- For `Before*AnalysisInterface`: returning `false` skips analysis of that node

## Your First Plugin: A Complete Working Example

Let's build a minimal plugin that makes `config('app.name')` return `string` instead of `mixed`:

**Step 1: Create the plugin entry point**

```php
// src/PsalmPlugin.php
<?php declare(strict_types=1);

namespace MyLaravelPlugin;

use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\PluginRegistrationSocket;
use SimpleXMLElement;

class PsalmPlugin implements PluginEntryPointInterface
{
    public function __invoke(PluginRegistrationSocket $registration, ?SimpleXMLElement $config = null): void
    {
        $registration->registerHooksFromClass(ConfigReturnTypeProvider::class);
    }
}
```

**Step 2: Create the type provider**

```php
// src/ConfigReturnTypeProvider.php
<?php declare(strict_types=1);

namespace MyLaravelPlugin;

use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Type;
use Psalm\Type\Union;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TNull;

class ConfigReturnTypeProvider implements FunctionReturnTypeProviderInterface
{
    /** @return list<lowercase-string> */
    public static function getFunctionIds(): array
    {
        return ['config'];
    }

    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        $call_args = $event->getCallArgs();

        // config() with no args returns Repository
        if (empty($call_args)) {
            return null; // Let Psalm handle it
        }

        // Simplified for illustration — a real plugin would handle more cases
        // config('key', 'default') — if a default is given, return its type
        if (count($call_args) >= 2) {
            $source = $event->getStatementsSource();
            $second_arg_type = $source->getNodeTypeProvider()->getType($call_args[1]->value);
            if ($second_arg_type !== null) {
                return $second_arg_type;
            }
        }

        return new Union([new TMixed()]); // single arg returns mixed
    }
}
```

**Step 3: Register in psalm.xml**

```xml
<psalm>
    <projectFiles>
        <directory name="app" />
    </projectFiles>
    <plugins>
        <pluginClass class="MyLaravelPlugin\PsalmPlugin" />
    </plugins>
</psalm>
```

That's it. Run `vendor/bin/psalm` and `config('app.name')` will now be typed as `string|null`.

## Event Handlers Reference

### Scanning Phase Hooks

#### AfterClassLikeVisitInterface — The Most Important Hook

**When**: During scanning, after a class's `ClassLikeStorage` is populated.

**Use case**: Add virtual methods/properties to Eloquent models, register magic methods on facades.

```php
class EloquentModelVisitor implements AfterClassLikeVisitInterface
{
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $storage = $event->getStorage();       // ClassLikeStorage — MUTABLE
        $class_node = $event->getStmt();       // ClassLike AST node
        $codebase = $event->getCodebase();

        // Check if this class extends Model (directly or indirectly)
        // Note: at scan time, inheritance isn't resolved yet, so check
        // parent_class for direct parent only. For full hierarchy,
        // use AfterCodebasePopulatedInterface instead.
        if ($storage->parent_class !== 'Illuminate\\Database\\Eloquent\\Model') {
            return;
        }

        // Add a virtual property: $email
        $property = new PropertyStorage();
        $property->type = new Union([new TString()]);
        $property->visibility = \Psalm\Internal\Analyzer\ClassLikeAnalyzer::VISIBILITY_PUBLIC;
        $storage->properties['email'] = $property;
        $storage->declaring_property_ids['email'] = $storage->name;
        $storage->appearing_property_ids['email'] = $storage->name;

        // Store custom data for later use
        $storage->custom_metadata['my_plugin'] = [
            'table' => 'users',
            'casts' => ['email_verified_at' => 'datetime'],
        ];
    }
}
```

**Reading the AST during scanning**: Yes, you can read the class body via `$event->getStmt()->stmts`. This is useful for finding things like the `$casts` property definition:

```php
// Find $casts property in the AST
foreach ($event->getStmt()->stmts as $stmt) {
    if ($stmt instanceof \PhpParser\Node\Stmt\Property
        && $stmt->props[0]->name->name === 'casts'
        && $stmt->props[0]->default instanceof \PhpParser\Node\Expr\Array_
    ) {
        // Parse the $casts array items...
    }
}
```

#### AfterCodebasePopulatedInterface — Post-Scanning

**When**: After ALL files are scanned AND the Populator has resolved inheritance.

**Use case**: Operations that need the complete type graph. Safe for checking full class hierarchies.

```php
class PostPopulationHook implements AfterCodebasePopulatedInterface
{
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        // Now you can safely check full inheritance:
        if ($codebase->classExtends('App\\Models\\User', 'Illuminate\\Database\\Eloquent\\Model')) {
            // Full hierarchy available here
        }
    }
}
```

### Analysis Phase Hooks

#### AfterMethodCallAnalysisInterface

**When**: After a method call expression has been analyzed. **Use case**: Override return types.

```php
class QueryBuilderReturnType implements AfterMethodCallAnalysisInterface
{
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $method_id = $event->getMethodId();  // e.g., "Illuminate\Database\Eloquent\Builder::where"
        $expr = $event->getExpr();
        $context = $event->getContext();

        // Override the return type for chaining
        $event->setReturnTypeCandidate(new Union([
            new TGenericObject('Illuminate\\Database\\Eloquent\\Builder', [
                new Union([new TNamedObject('App\\Models\\User')]),
            ]),
        ]));
    }
}
```

#### BeforeAddIssueInterface — Suppressing False Positives

```php
class SuppressFacadeIssues implements BeforeAddIssueInterface
{
    public static function beforeAddIssue(BeforeAddIssueEvent $event): ?bool
    {
        $issue = $event->getIssue();

        // Suppress UndefinedMethod on Facade classes
        if ($issue instanceof \Psalm\Issue\UndefinedMethod) {
            if (str_contains($issue->method_id ?? '', 'Facade')) {
                return false; // suppress this issue
            }
        }
        return null; // let other handlers decide
    }
}
```

#### AfterEveryFunctionCallAnalysisInterface vs. AfterFunctionCallAnalysisInterface

- `AfterFunctionCallAnalysis`: Only fires for **user-defined** (project) functions
- `AfterEveryFunctionCallAnalysis`: Fires for **ALL** function calls including PHP built-ins (`strlen`, `array_map`, etc.). Cannot modify return type. Use for monitoring or analysis only.

### Type Providers — Dynamic Type Resolution

Type providers are the most powerful tool for Laravel plugins. They dynamically compute types for methods, properties, and functions.

#### MethodReturnTypeProviderInterface

```php
class EloquentFindReturnType implements MethodReturnTypeProviderInterface
{
    // Which classes does this provider handle?
    // Registering for Model means it fires for User::find(), Post::find(), etc.
    // — any class that extends Model.
    public static function getClassLikeNames(): array
    {
        return ['Illuminate\\Database\\Eloquent\\Model'];
    }

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $method_name = $event->getMethodNameLowercase();
        $fq_classlike_name = $event->getFqClasslikeName(); // "App\Models\User"

        if ($method_name === 'find') {
            return new Union([
                new TNamedObject($fq_classlike_name), // User (not Model!)
                new TNull(),
            ]);
        }

        return null; // Return null = fall back to declared return type
    }
}
```

#### PropertyTypeProviderInterface

**Use case**: Eloquent model attributes (`$user->email`) that don't have declared properties.

```php
class EloquentPropertyType implements PropertyTypeProviderInterface
{
    public static function getClassLikeNames(): array
    {
        return ['Illuminate\\Database\\Eloquent\\Model'];
    }

    public static function getPropertyType(PropertyTypeProviderEvent $event): ?Union
    {
        $property_name = $event->getPropertyName();  // "email"
        $fq_classlike_name = $event->getFqClasslikeName();

        // Read metadata stored during scanning
        $codebase = $event->getCodebase();
        $storage = $codebase->classlike_storage_provider->get($fq_classlike_name);
        $metadata = $storage->custom_metadata['my_plugin'] ?? [];

        // Look up column type from metadata
        $casts = $metadata['casts'] ?? [];
        if (isset($casts[$property_name])) {
            return match($casts[$property_name]) {
                'int', 'integer' => new Union([new TInt()]),
                'bool', 'boolean' => new Union([new TBool()]),
                'datetime' => new Union([new TNamedObject('Carbon\\Carbon'), new TNull()]),
                default => new Union([new TString()]),
            };
        }

        return null; // Unknown property — let Psalm decide
    }
}
```

## Adding Stub Files

Stubs let you provide type information for classes you can't modify:

```php
// stubs/Model.phpstub
namespace Illuminate\Database\Eloquent;

/**
 * @template TModel of Model
 * @psalm-seal-methods
 * @psalm-seal-properties
 */
class Model {
    /**
     * @param int|string $id
     * @return TModel|null
     */
    public static function find($id) {}
}
```

- `@psalm-seal-methods` — tells Psalm "this class has no magic methods beyond what's declared." If code calls an undeclared method, Psalm reports it. Without this annotation, Psalm assumes `__call` might handle anything.
- `@psalm-seal-properties` — same for properties.

Register stubs in your plugin entry point:
```php
$registration->addStubFile(__DIR__ . '/stubs/Model.phpstub');
```

**Stubs override real declarations.** If you provide a stub for `Model::find()`, it completely replaces whatever Laravel's actual `Model::find()` signature is.

**Stubs vs. type providers**: If a stub declares a method AND a type provider handles it, the type provider wins — the provider's return type replaces the stub's declared return type. The stub still matters for parameter types and method existence.

## Accessing Data in Plugins

| Data | How to get it | What you can do |
|---|---|---|
| `Codebase` | `$event->getCodebase()` | Access any Storage, check class hierarchy |
| `Context` | `$event->getContext()` | Read/modify `$vars_in_scope` (current variable types) |
| `StatementsSource` | `$event->getSource()` | Get file name, function scope, node type provider |
| `ClassLikeStorage` | Via Codebase or event | Read/modify class metadata |
| `NodeDataProvider` | Via `$source->getNodeTypeProvider()` | Read types computed for AST nodes |

**Warning about Context modification**: Modifying `$vars_in_scope` is powerful but dangerous — you're telling Psalm "this variable definitely has this type now." If wrong, you'll suppress real errors. Use type providers instead when possible.

### Custom Metadata

Plugin data stored in `ClassLikeStorage::$custom_metadata` is cached with the storage:
```php
// Stored during AfterClassLikeVisit (scanning phase)
$storage->custom_metadata['my_plugin']['table'] = 'users';

// Read during analysis (type providers, etc.)
$metadata = $codebase->classlike_storage_provider
    ->get('App\\Models\\User')
    ->custom_metadata['my_plugin'] ?? [];
```

This data survives between Psalm runs (it's cached). **Cache invalidation**: the cache invalidates when the source file changes, so if your metadata depends on the file content, it's automatically fresh. If your plugin logic changes, users need to clear the Psalm cache (`--no-cache` or delete the cache directory).

## Debugging and Testing Your Plugin

### Debugging

```bash
# See which hooks fire and when
vendor/bin/psalm --debug

# Run single-threaded for simpler debugging (no forked workers)
vendor/bin/psalm --threads=1

# Clear cache if you suspect stale plugin data
vendor/bin/psalm --no-cache
```

Within your hook, write to stderr for debug output (stdout is used for issue output):
```php
fwrite(STDERR, "My hook fired for: " . $storage->name . "\n");
```

To inspect types, call `$type->getId()` to get a readable string representation:
```php
fwrite(STDERR, "Return type: " . $return_type->getId() . "\n");
// Output: "Illuminate\Database\Eloquent\Collection<int, App\Models\User>"
```

### Testing

Psalm's own test patterns work well for plugin tests. For external plugins, require `vimeo/psalm` as a dev dependency. Create PHPUnit tests using `ValidCodeAnalysisTestTrait` and `InvalidCodeAnalysisTestTrait`:

```php
class MyPluginTest extends \Psalm\Tests\TestCase
{
    use \Psalm\Tests\Traits\ValidCodeAnalysisTestTrait;

    public function providerValidCodeParse(): iterable
    {
        yield 'eloquent_find_returns_model' => [
            'code' => '<?php
                $user = \App\Models\User::find(1);
            ',
            'assertions' => [
                '$user' => 'App\Models\User|null',
            ],
        ];
    }
}
```

## Practical Pattern: Laravel Plugin Architecture

```
psalm-plugin-laravel/
├── src/
│   ├── Plugin.php                    # Entry point (PluginEntryPointInterface)
│   ├── Handlers/
│   │   ├── EloquentHandler.php       # AfterClassLikeVisitInterface
│   │   │                             # Reads $casts, $fillable, scopes from AST
│   │   │                             # Adds virtual properties to ClassLikeStorage
│   │   ├── FacadeHandler.php         # MethodReturnTypeProviderInterface
│   │   │                             # Resolves Facade::method() to underlying class
│   │   └── ContainerHandler.php      # FunctionReturnTypeProviderInterface
│   │                                 # Resolves app(), resolve() to bound classes
│   ├── Providers/
│   │   ├── ModelMethodProvider.php    # MethodReturnTypeProviderInterface
│   │   │                             # Dynamic return types for find(), where(), etc.
│   │   ├── ModelPropertyProvider.php  # PropertyTypeProviderInterface
│   │   │                             # Types for $user->email, $user->name
│   │   └── RelationProvider.php      # MethodReturnTypeProviderInterface
│   │                                 # Types for hasMany(), belongsTo(), etc.
│   └── Support/
│       └── ModelMetadataExtractor.php # Extracts column info from DB or config
├── stubs/
│   ├── Model.phpstub                 # Type annotations for Eloquent Model
│   ├── Builder.phpstub               # Type annotations for query builder
│   └── Collection.phpstub            # Type annotations for Eloquent Collection
└── tests/
    └── EloquentTest.php              # PHPUnit tests using Psalm's test traits
```

**Key architectural insight**: Use `AfterClassLikeVisit` for things you know at scan time (model scopes from method names, properties from `$casts` definitions). Use type providers for things that depend on the call site (return type of `find()` depends on which model class).

> **Important**: If your plugin accumulates state during analysis hooks, read Document 6 first — there's a critical gotcha with parallel workers that can silently lose your data.

---

*Next: [Document 6 — Caching, Parallelism & Performance](06-caching-parallelism.md)*
