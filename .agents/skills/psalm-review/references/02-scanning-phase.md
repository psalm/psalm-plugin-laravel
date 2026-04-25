# Document 2: The Scanning Phase — From PHP Files to Type Information

*How Psalm reads your code and builds its internal database*

---

## Overview

Scanning is Psalm's first pass over your codebase. Its job: read every PHP file, parse it into an AST, and extract all **declarations** — classes, interfaces, traits, functions, constants, properties, method signatures, type annotations — into structured Storage objects. No actual type checking happens here.

## How a Single File Gets Scanned

Let's start with the concrete case — what happens to one PHP file:

```
FileScanner::scan(file_path)
│
├── 1. Parse PHP to AST
│   └── nikic/php-parser reads the file and produces a tree of Node objects
│       └── Uses Composer-style autoloading to find the parser
│       └── Handles syntax errors gracefully (reports them, continues scanning)
│
├── 2. Walk the AST with ReflectorVisitor
│   └── NodeTraverser calls enterNode()/leaveNode() for every AST node
│   └── ReflectorVisitor extracts declarations into Storage objects
│
└── 3. Result: FileStorage + ClassLikeStorage(s) + FunctionLikeStorage(s) populated
```

### What ReflectorVisitor Extracts

`ReflectorVisitor` (`src/Psalm/Internal/PhpVisitor/ReflectorVisitor.php`) is the workhorse of scanning. For each construct it encounters:

**When it enters a Class/Interface/Trait:**
- Creates a new `ClassLikeStorage` object
- Records: name, namespace, location, parent class, interfaces, traits
- Parses `@template` docblock annotations for generics
- Records whether it's abstract, final, readonly
- Queues parent/interface/trait classes for scanning (they may be in other files)

**When it enters a Method/Function:**
- Creates a new `FunctionLikeStorage` (or `MethodStorage` for methods)
- Records: name, parameters, return type, visibility
- Parses docblock `@param`, `@return`, `@template`, `@psalm-assert`, etc.

**When it enters a Property:**
- Creates a property entry in `ClassLikeStorage::$properties`
- Records: name, type (from typehint or docblock), visibility, default value
- Handles promoted constructor parameters (`public function __construct(private string $name)`) — this is how Psalm handles Laravel's common constructor injection pattern

**Docblock `@property` annotations:** When ReflectorVisitor sees `@property string $email` on a class docblock, it DOES create a property in ClassLikeStorage. However, Eloquent models typically don't have these annotations — that's why the Laravel plugin needs to add properties dynamically.

## Deep vs. Shallow Scanning

Not all files get the same treatment. This is Psalm's main performance optimization:

**Deep scan** (your project files): Traverses into function/method bodies to find:
- All class/function references (to discover more files to scan)
- Constant usage
- More precise type information from implementations

**Shallow scan** (vendor/dependency files): Extracts function signature, parameter types, return type, docblock — then **skips the method body entirely**. The visitor returns `DONT_TRAVERSE_CHILDREN`, saving significant time.

```php
// Inside ReflectorVisitor, when entering a function node:
if (!$this->scan_deep) {
    // Shallow scan: we only care about the signature, skip the body
    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
}
```

The decision is simple: files listed in your `psalm.xml` `<projectFiles>` get deep scanned. Everything else (vendor, stubs) gets shallow scanned.

## How Multiple Files Are Managed

Scanning is **iterative** because of cross-file references. The `Scanner` (`src/Psalm/Internal/Codebase/Scanner.php`) maintains a work queue:

```
Scanner::scanFiles()
│
├── while (there are files or classes left to scan):
│   │
│   ├── Scan all queued files
│   │   └── Scanning may discover NEW classes to scan
│   │       (e.g., "class User extends Model" → queue Model for scanning)
│   │
│   └── Convert any queued classes to file paths
│       └── Uses Composer's autoloader to find the file
│       └── Adds found files to the scan queue → loop continues
│
└── Done: all reachable code has been scanned
```

Here's a concrete example with a Laravel app:

```
1. Scan app/Http/Controllers/UserController.php (DEEP — project file)
   → Found: class UserController extends Controller
   → Queue Controller for scanning

2. Scan app/Http/Controllers/Controller.php (DEEP — project file)
   → Found: use App\Models\User in a method body
   → Queue App\Models\User for scanning

3. Scan app/Models/User.php (DEEP — project file)
   → Found: class User extends Model
   → Queue Illuminate\Database\Eloquent\Model for scanning

4. Scan vendor/laravel/.../Model.php (SHALLOW — vendor file)
   → Extracts method signatures only, skips method bodies
   → Found: implements ArrayAccess, JsonSerializable...
   → Queue those interfaces (already known via stubs)

5. No more files to scan → scanning complete
```

Notice step 4: the scanning phase found Model's method signatures but NOT its method bodies. This means Psalm knows `Model::save()` exists and returns `bool`, but it doesn't analyze what `save()` does internally. This is why Model's scanning is fast.

**Also notice the gap:** scanning found NO `$email` property on `User` or `Model` — because Eloquent properties are magic (`__get`/`__set`). This is exactly the gap that the Laravel plugin fills.

## Storage Objects: What Scanning Produces

### ClassLikeStorage — The Most Important One

`ClassLikeStorage` (`src/Psalm/Storage/ClassLikeStorage.php`) is the class you'll interact with most as a plugin developer. Here's what it looks like for a basic Eloquent model **before** any plugin touches it:

```
ClassLikeStorage for App\Models\User:
├── $name = "App\Models\User"
├── $parent_class = "Illuminate\Database\Eloquent\Model"
├── $used_traits = ["HasFactory", "Notifiable"]
├── $methods = [
│     // Only methods explicitly declared in User.php
│     // NOT inherited methods (those come from Populator later)
│   ]
├── $properties = []              ← EMPTY! No declared properties
├── $template_types = []          ← No generics on User itself
├── $template_extended_params = [] ← Will be filled by Populator
├── $custom_metadata = []         ← Available for YOUR plugin data
├── $is_abstract = false
├── $is_final = false
└── $location = CodeLocation(app/Models/User.php:10)
```

**Key fields for plugin developers:**

| Field | What It Is | Plugin Use Case |
|---|---|---|
| `$methods` | Map of method name to `MethodStorage` | Add virtual methods (scopes, relationships) |
| `$properties` | Map of property name to `PropertyStorage` | Add virtual properties (database columns) |
| `$template_types` | Generic type parameters (`@template T`) | Read to understand class generics |
| `$template_extended_params` | How parent generics are filled | `Collection<User>` — T is User |
| `$custom_metadata` | `array<string, mixed>` — for plugins | Store model metadata, casts, table name |
| `$parent_class` | FQCN of parent class | Check if class extends Model |

### FileStorage and FunctionLikeStorage

**FileStorage** (`src/Psalm/Storage/FileStorage.php`): Per-file metadata — which classes, functions, and constants are declared in the file, type aliases, and include/require paths.

**FunctionLikeStorage** (`src/Psalm/Storage/FunctionLikeStorage.php`): Per-function/method type profile:
- `$params` — array of `FunctionLikeParameter` (name, type, default, by_ref, variadic)
- `$return_type` — the `Union` return type
- `$template_types` — method-level generics
- `$assertions` — `@psalm-assert` annotations
- `$if_true_assertions`, `$if_false_assertions` — conditional assertions
- `$pure` — whether the function has side effects

## The Populator: Resolving Inheritance

After all files are scanned, the `Populator` (`src/Psalm/Internal/Codebase/Populator.php`) resolves inheritance. Scanning only recorded "User extends Model" — the Populator copies Model's methods, properties, and interfaces down to User.

```
Populator::populateCodebase()
│
├── Sort classes by inheritance depth (parents first)
│   └── Model before User, Collection before UserCollection
│
├── For each ClassLikeStorage:
│   ├── Copy parent methods to child (unless overridden)
│   ├── Copy parent properties to child
│   ├── Resolve trait use (copy trait methods/properties, apply aliases)
│   ├── Add interface method signatures
│   └── Resolve template params
│       └── If class UserCollection extends Collection<User>,
│           substitute T=User in inherited method signatures
│
└── Done: every ClassLikeStorage is fully resolved
```

**Important for plugin developers:** The `AfterClassLikeVisitInterface` hook runs **during scanning, BEFORE the Populator**. This means:
- You CAN add methods/properties to a class's own storage
- Those additions WILL be inherited by child classes (the Populator handles that)
- You CANNOT yet see inherited methods from parent classes at this point
- If you need the full inheritance picture, use `AfterCodebasePopulatedInterface` instead

If you've ever seen a Psalm error about circular references, or been confused why changing a base class caused errors in unrelated files — this inheritance resolution step is why. The Populator re-resolves the entire chain.

## Stubs and the Callmap

Psalm needs to know types for PHP's built-in functions (`strlen`, `array_map`, etc.) and classes (`DateTime`, `PDO`, etc.). It gets this from stubs and a callmap — covered in detail in Document 4's "Callmap" section. The key point for now: these work just like your plugin's stub files — they declare types for things Psalm can't scan from PHP source.

---

*Next: [Document 3 — Analysis Phase Deep Dive](03-analysis-phase.md)*
