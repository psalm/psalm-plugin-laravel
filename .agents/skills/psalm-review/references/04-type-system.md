# Document 4: Type System Internals

*How Psalm represents, compares, and manipulates types*

---

## Overview

Every type in Psalm is a `Union` — a container holding one or more `Atomic` types. Even a simple `int` is internally `Union([TInt])`. This document covers how types are represented, constructed, compared, and resolved — with a focus on what you'll need for plugin development.

## Quick Reference: Constructing Types in Plugin Code

Before diving into theory, here's the cookbook you'll use daily as a plugin developer:

```php
use Psalm\Type;
use Psalm\Type\Union;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TKeyedArray;

// string
$string = new Union([new TString()]);

// int
$int = new Union([new TInt()]);

// string|null
$nullable_string = new Union([new TString(), new TNull()]);

// App\Models\User
$user = new Union([new TNamedObject('App\\Models\\User')]);

// App\Models\User|null
$nullable_user = new Union([
    new TNamedObject('App\\Models\\User'),
    new TNull(),
]);

// Collection<int, User>  (Eloquent collection)
$user_collection = new Union([
    new TGenericObject('Illuminate\\Database\\Eloquent\\Collection', [
        new Union([new TInt()]),                              // TKey
        new Union([new TNamedObject('App\\Models\\User')]),   // TValue
    ]),
]);

// Builder<User>  (Eloquent query builder)
$builder = new Union([
    new TGenericObject('Illuminate\\Database\\Eloquent\\Builder', [
        new Union([new TNamedObject('App\\Models\\User')]),
    ]),
]);

// array{name: string, email: string}  (array shape)
$shape = new Union([
    TKeyedArray::make([
        'name' => new Union([new TString()]),
        'email' => new Union([new TString()]),
    ]),
]);

// bool
$bool = new Union([new TBool()]);
```

**Shorthand factory methods**: For common types, Psalm provides convenience methods that are shorter than the verbose constructors above:

```php
use Psalm\Type;

$string = Type::getString();     // same as new Union([new TString()])
$int = Type::getInt();           // same as new Union([new TInt()])
$bool = Type::getBool();
$null = Type::getNull();
$mixed = Type::getMixed();
$void = Type::getVoid();
$float = Type::getFloat();
```

Use the factory methods for simple types and the verbose constructors when you need generics, named objects, or shapes.

Keep this reference handy — it answers the most common "how do I represent type X?" question.

## The Type Hierarchy

```
Union                          ← Container: holds one or more Atomic types
│
└── Atomic (abstract)          ← Base class for all type atoms
    │
    ├── SCALARS (most common in plugins)
    │   ├── TInt, TFloat, TString, TBool
    │   ├── TTrue, TFalse                    ← bool literals
    │   ├── TLiteralInt(42), TLiteralString("hello")  ← specific values
    │   ├── TNonEmptyString, TNonFalsyString  ← string subtypes
    │   ├── TNumericString                    ← "42" (numeric strings)
    │   ├── TClassString                      ← class-string<T>
    │   ├── TArrayKey                         ← int|string (array keys)
    │   └── TNumeric                          ← int|float|numeric-string
    │
    ├── OBJECTS (you'll use these most)
    │   ├── TObject                           ← generic "object"
    │   ├── TNamedObject("User")              ← specific class
    │   ├── TGenericObject("Collection", [...]) ← parameterized class
    │   └── TCallableObject                   ← object with __invoke
    │
    ├── ARRAYS & ITERABLES
    │   ├── TArray(TKey, TValue)              ← array<K, V>
    │   ├── TNonEmptyArray(TKey, TValue)
    │   ├── TKeyedArray([...])                ← array shapes
    │   └── TIterable(TKey, TValue)           ← iterable<K, V>
    │
    ├── GENERICS & TEMPLATES
    │   ├── TTemplateParam("T", as_type)      ← template parameter
    │   ├── TConditional(T, if, else)         ← conditional types
    │   ├── TKeyOf, TValueOf                  ← key-of<T>, value-of<T>
    │   └── TTemplateParamClass               ← class-string<T>
    │
    ├── CALLABLES
    │   ├── TCallable(params, return)         ← callable signature
    │   └── TClosure(params, return)          ← Closure specifically
    │
    └── SPECIAL
        ├── TNull, TVoid, TNever              ← null, void, never/bottom
        ├── TMixed                            ← mixed (anything)
        ├── TIntRange(min, max)               ← int<0, 100>
        └── TResource                         ← resource type
```

**For plugin development**, you'll use 5 types 90% of the time: `TString`, `TInt`, `TNamedObject`, `TGenericObject`, `TNull`.

## Union: The Container

`Union` (`src/Psalm/Type/Union.php`) holds atomic types keyed by their string representation:

```php
class Union {
    private array $types;  // ['int' => TInt, 'string' => TString]

    // Metadata flags
    public bool $from_docblock = false;     // Type came from docblock
    public bool $possibly_undefined = false; // Variable might not exist
    public bool $by_ref = false;            // Passed by reference
}
```

### `from_docblock`: Trust Levels

Types from PHP native typehints are fully trusted. Types from docblocks are less trusted — Psalm tracks this via `$from_docblock`:

```php
// Native typehint — fully trusted:
function getName(): string { ... }  // $from_docblock = false

// Docblock — less trusted:
/** @return string */
function getName() { ... }  // $from_docblock = true
```

When `$from_docblock = true`, Psalm may emit `DocblockTypeContradiction` if the docblock conflicts with what the code actually does. **For plugins**: when constructing return types, set `$from_docblock = true` if your type is based on docblock annotations or heuristics rather than concrete declarations. This lets Psalm provide better error messages.

### Key Union Operations

| Method | What it does |
|---|---|
| `getAtomicTypes()` | Returns array of Atomic types |
| `hasType('int')` | Checks if union contains a specific type |
| `isNullable()` | Contains TNull? |
| `isSingle()` | Contains exactly one atomic type? |
| `getId()` | String representation: `"int\|string\|null"` |

**Combining unions** (used when merging contexts after branches):
```php
$merged = Type::combineUnionTypes($string_type, $int_type);
// Result: Union(TString, TInt) — i.e., string|int
```

## Atomic Types: Details That Matter

### TNamedObject vs. TGenericObject

```php
// TNamedObject: a class without type parameters
new TNamedObject('App\\Models\\User')
// Represents: User

// TGenericObject: a class WITH type parameters
new TGenericObject('Illuminate\\Database\\Eloquent\\Collection', [
    new Union([new TInt()]),                            // key type
    new Union([new TNamedObject('App\\Models\\User')]), // value type
])
// Represents: Collection<int, User>
```

**When to use which?** Use `TGenericObject` when the class has `@template` parameters and you know the concrete types. Use `TNamedObject` when you just want "an instance of this class" without specifying generics.

### TKeyedArray: Array Shapes

Array shapes describe arrays with known keys and value types:

```php
// array{name: string, age: int}
TKeyedArray::make([
    'name' => new Union([new TString()]),
    'age' => new Union([new TInt()]),
])
```

The third argument to `TKeyedArray::make()` handles **open shapes** — arrays that have known keys but may also have unknown ones:
```php
// array{name: string, ...}  (open: may have other keys)
TKeyedArray::make(
    ['name' => new Union([new TString()])],
    null,  // class_strings
    [new Union([new TArrayKey()]), new Union([new TMixed()])],  // fallback_params
)
```

A closed shape (no `fallback_params`) means the array has EXACTLY those keys. An open shape means "at least these keys, possibly more."

### TTemplateParam: Generics

Represents a template parameter with an upper bound:

```php
// @template T of Model
new TTemplateParam('T', new Union([new TNamedObject('Model')]), 'MyClass')
// param_name: "T", upper bound: Model, defined in: MyClass
```

### TConditional: Conditional Types

Used in stubs for functions whose return type depends on input:

```php
// Collection::first() returns TValue|null with no args, TValue with a default
// This is represented internally as TConditional
```

You'll rarely construct these in plugins — they're mainly used in Psalm's stubs for PHP built-ins and framework type declarations.

## Type Comparison: Subtype Checking

The comparator system (`src/Psalm/Internal/Type/Comparator/`) answers: "Is type A a subtype of type B?"

```php
UnionTypeComparator::isContainedBy(
    $codebase,
    $input_type,      // What you have  (e.g., User)
    $container_type,  // What's expected (e.g., Model)
): bool
```

**Algorithm**: For each atomic type in the input, check if it's contained by at least one atomic type in the container. If ALL input atomics pass, the union is contained:

```
Is (string|int) a subtype of (string|int|float)?
├── Is string a subtype of (string|int|float)? → yes
├── Is int a subtype of (string|int|float)?    → yes
└── All passed → YES
```

Specialized comparators handle different type pairs:

| Comparator | Examples |
|---|---|
| `ScalarTypeComparator` | int is subtype of numeric, string is subtype of array-key |
| `ObjectTypeComparator` | User is subtype of Model (checks class hierarchy) |
| `ArrayTypeComparator` | `array<int, User>` is subtype of `array<array-key, Model>` |
| `CallableTypeComparator` | callable signatures (contravariant params, covariant return) |
| `GenericTypeComparator` | `Collection<User>` is subtype of `Collection<Model>` |

### Variance in Generics

In Laravel terms:

- **Covariant** (output/return position): `UserCollection extends Collection<User>` can be used where `Collection<User>` is expected — safe because you're only reading values out
- **Contravariant** (input/parameter position): a function accepting `Model` can be used where a function accepting `User` is expected — safe because it handles the broader type
- **Invariant** (both read and write): exact match required — e.g., a mutable array's value type

You'll encounter variance mainly when defining generic stubs. If Psalm complains about "template param T on class X is not covariant," it means you're passing a subtype where an exact match is needed.

## Template Resolution

When Psalm encounters a generic method call, it infers template parameter values:

```php
// Given: function first<T>(array<T> $items): T
// Called: first([new User(), new User()])
//
// 1. Psalm sees array<T> parameter, input is array<User>
// 2. Template inference: T = User
// 3. Return type T → resolves to User
// 4. Result: first() returns User
```

This happens automatically. For plugins, if your method has templates, Psalm resolves them from the call-site arguments. You can also manually resolve templates using `TemplateInferredTypeReplacer`.

## The Callmap: Built-in Function Types

Psalm knows types for thousands of PHP built-in functions via the callmap (`dictionaries/CallMap*.php`):

```php
// Simplified format:
'strlen' => ['int', 'string' => 'string'],
//            ^^^     ^^^^^^^^^^^^^^^^
//            return   param_name => type

'array_filter' => ['array', 'array' => 'array', 'callback=' => 'callable', 'mode=' => 'int'],
//                                               ^^^^^^^^^                  ^^^^^
//                                               '=' suffix = optional param
```

| Convention | Meaning |
|---|---|
| First element | Return type |
| `'param='` | Optional parameter (= suffix) |
| `'...param'` | Variadic parameter (... prefix) |
| `'?type'` | Nullable type (? prefix) |

Delta files (`CallMap_83_delta.php`, etc.) add/modify entries per PHP version. Psalm applies the right deltas based on your configured PHP version.

Additionally, `stubs/` contains PHP files with full docblock annotations for built-in classes, providing richer type information (generics, conditional returns, assertions) than the callmap alone.

## Key Type Operations Summary

| Operation | Where | What it does |
|---|---|---|
| `Type::combineUnionTypes()` | `src/Psalm/Type/` | Merge types from branches |
| `UnionTypeComparator::isContainedBy()` | `Comparator/` | Subtype checking |
| `Reconciler::reconcileKeyedTypes()` | `Type/Reconciler.php` | Type narrowing from assertions |
| `TemplateInferredTypeReplacer` | `Internal/Type/` | Substitute template params |
| `TypeParser::parseTokens()` | `Internal/Type/` | Parse type strings to objects |
| `TypeExpander::expandUnion()` | `Internal/Type/` | Resolve `self`, `static`, type aliases to concrete types |

---

*Now that you understand how Psalm represents and compares types, you have the vocabulary to build plugins. When your plugin returns a `Union` from a type provider, you're speaking the same language as Psalm's internal analyzers.*

*Next: [Document 5 — Plugin System & Extension Points](05-plugin-system.md)*
