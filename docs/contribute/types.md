# Psalm Type Annotations Reference

Quick reference of all type annotations supported by Psalm 7. Useful when writing stubs and handlers.

Source of truth: `vendor/vimeo/psalm/src/Psalm/Internal/Type/TypeTokenizer.php` (`PSALM_RESERVED_WORDS`) and `vendor/vimeo/psalm/src/Psalm/DocComment.php` (`PSALM_ANNOTATIONS`).

Psalm docs (deep links):
- [Typing in Psalm](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/typing_in_psalm.md)
- [Supported Annotations](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/supported_annotations.md)
- [Templated Annotations](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/templated_annotations.md)
- [Adding Assertions](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/adding_assertions.md)
- [Assertion Syntax](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/assertion_syntax.md)
- Type syntax: [Atomic](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/type_syntax/atomic_types.md), [Scalar](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/type_syntax/scalar_types.md), [Value](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/type_syntax/value_types.md), [Object](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/type_syntax/object_types.md), [Array](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/type_syntax/array_types.md), [Callable](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/type_syntax/callable_types.md), [Utility](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/type_syntax/utility_types.md), [Union](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/type_syntax/union_types.md), [Intersection](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/type_syntax/intersection_types.md), [Conditional](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/type_syntax/conditional_types.md), [Top/Bottom](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/type_syntax/top_bottom_types.md), [Other](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/type_syntax/other_types.md)

## Scalar Types

| Type                                   | Atomic              | Notes                                                              |
|----------------------------------------|---------------------|--------------------------------------------------------------------|
| `int`                                  | `TInt`              |                                                                    |
| `float`                                | `TFloat`            |                                                                    |
| `string`                               | `TString`           |                                                                    |
| `bool`                                 | `TBool`             |                                                                    |
| `true`                                 | `TTrue`             |                                                                    |
| `false`                                | `TFalse`            |                                                                    |
| `null`                                 | `TNull`             |                                                                    |
| `void`                                 | `TVoid`             |                                                                    |
| `scalar`                               | `TScalar`           | `int\|float\|string\|bool`                                         |
| `numeric`                              | `TNumeric`          | `int\|float\|numeric-string`                                       |
| `array-key`                            | `TArrayKey`         | `int\|string`                                                      |
| `mixed`                                | `TMixed`            | Top type                                                           |
| `never`                                | `TNever`            | Bottom type. Aliases: `no-return`, `never-return`, `never-returns` |
| `object`                               | `TObject`           | Any object                                                         |
| `resource`                             | `TResource`         |                                                                    |
| `open-resource`                        | `TResource`         | Active resource                                                    |
| `closed-resource`                      | `TClosedResource`   | Closed resource                                                    |
| `boolean`, `integer`, `double`, `real` |                     | Deprecated aliases                                                 |

## Integer Subtypes

| Type                       | Atomic                   | Adoption | Notes                                           |
|----------------------------|--------------------------|----------|-------------------------------------------------|
| `positive-int`             | `TIntRange`              | Wide     | `int<1, max>`                                   |
| `non-negative-int`         | `TIntRange`              | Wide     | `int<0, max>`                                   |
| `negative-int`             | `TIntRange`              | Rare     | `int<min, -1>`                                  |
| `non-positive-int`         | `TIntRange`              | Rare     | `int<min, 0>`                                   |
| `literal-int`              | `TNonspecificLiteralInt` | Niche    | An int known at analysis time                   |
| `int<min, max>`            | `TIntRange`              | Medium   | Range. `min` = PHP_INT_MIN, `max` = PHP_INT_MAX |
| `int-mask<1, 2, 4>`        | `TIntMask`               | Niche    | Bitmask of listed values                        |
| `int-mask-of<Foo::FLAG_*>` | `TIntMaskOf`             | Niche    | Bitmask from class constants                    |

## String Subtypes

| Type                         | Atomic                              | Adoption    | Notes                                           |
|------------------------------|-------------------------------------|-------------|-------------------------------------------------|
| `non-empty-string`           | `TNonEmptyString`                   | Wide        |                                                 |
| `non-falsy-string`           | `TNonFalsyString`                   | Medium      | Not empty and not `'0'`. Alias: `truthy-string` |
| `numeric-string`             | `TNumericString`                    | Wide        | Passes `is_numeric()`                           |
| `literal-string`             | `TNonspecificLiteralString`         | Medium      | Composed entirely of literals in source         |
| `non-empty-literal-string`   | `TNonEmptyNonspecificLiteralString` | Niche       |                                                 |
| `lowercase-string`           | `TLowercaseString`                  | Niche       | Psalm-only                                      |
| `non-empty-lowercase-string` | `TNonEmptyLowercaseString`          | Niche       | Psalm-only                                      |
| `callable-string`            | `TCallableString`                   | Medium      | Passes `is_callable()`                          |
| `class-string`               | `TClassString`                      | Wide        | Valid FQCN                                      |
| `class-string<Foo>`          | `TClassString`                      | Wide        | FQCN of `Foo` or subclass                       |
| `interface-string`           | `TClassString`                      | Niche       |                                                 |
| `trait-string`               | `TTraitString`                      | Niche       |                                                 |
| `enum-string`                | `TClassString`                      | Niche       |                                                 |

## Literal Types

```
42              // literal int
3.14            // literal float
'hello'         // literal string
"hello"         // literal string
Foo::class      // literal class-string
Foo::CONST      // class constant value
```

## Array / List Types

| Type                            | Atomic           | Notes                                    |
|---------------------------------|------------------|------------------------------------------|
| `array`                         | `TArray`         | Untyped                                  |
| `array<TValue>`                 | `TArray`         | Shorthand for `array<array-key, TValue>` |
| `array<TKey, TValue>`           | `TArray`         |                                          |
| `non-empty-array<TKey, TValue>` | `TNonEmptyArray` | At least one element                     |
| `associative-array`             | `TArray`         |                                          |
| `list<TValue>`                  | `TKeyedArray`    | Sequential `int`-keyed array             |
| `non-empty-list<TValue>`        | `TKeyedArray`    |                                          |
| `non-empty-countable`           |                  | `Countable` with at least one element    |

### Array Shapes

```php
array{key: string, id: int}        // required keys
array{key?: string}                // optional key
array{0: string, 1: int, ...}     // known prefix + open-ended
list{string, int, float}          // positional list shape
```

### Object Shapes

```php
object{foo: string, bar: int}
object{foo?: string}               // optional property
```

## Callable Types

| Type                             | Atomic            | Adoption | Notes                      |
|----------------------------------|-------------------|----------|----------------------------|
| `callable`                       | `TCallable`       | Wide     |                            |
| `Closure`                        | `TClosure`        | Wide     |                            |
| `callable(int, string): bool`    | `TCallable`       | Wide     | Typed callable             |
| `Closure(int, string): bool`     | `TClosure`        | Wide     | Typed closure              |
| `callable(int, string=): void`   | `TCallable`       | Medium   | `=` marks optional param   |
| `callable(int, string...): void` | `TCallable`       | Medium   | `...` marks variadic param |
| `callable-string`                | `TCallableString` | Medium   | String that is callable    |
| `callable-array`                 | `TKeyedArray`     | Niche    | Array that is callable     |
| `callable-list`                  | `TKeyedArray`     | Niche    | List that is callable      |
| `callable-object`                | `TCallableObject` | Niche    | Object with `__invoke`     |
| `stringable-object`              | `TNamedObject`    | Niche    | Object with `__toString`   |

### Callable Mutation Modifiers

| Type                                                 | Adoption | Notes                      |
|------------------------------------------------------|----------|----------------------------|
| `pure-callable` / `pure-Closure`                     | Niche    | No side effects            |
| `impure-callable` / `impure-Closure`                 | Rare     | Default behavior, explicit |
| `self-accessing-callable` / `self-accessing-Closure` | Rare     | Reads `$this` properties   |
| `self-mutating-callable` / `self-mutating-Closure`   | Rare     | Reads and writes `$this`   |

## Generics

```php
/** @template T */
/** @template T of SomeType */           // upper bound
/** @template-covariant T */
/** @extends Base<int, string> */
/** @implements Interface<Foo> */
/** @use TraitName<Bar> */
```

## Utility Types

| Type                            | Atomic                   | Adoption | Notes                            |
|---------------------------------|--------------------------|----------|----------------------------------|
| `key-of<T>`                     | `TKeyOf`                 | Medium   | Array key type                   |
| `value-of<T>`                   | `TValueOf`               | Medium   | Array value type                 |
| `properties-of<T>`              | `TPropertiesOf`          | Niche    | All properties as keyed array    |
| `public-properties-of<T>`       | `TPropertiesOf`          | Niche    | Psalm-only                       |
| `protected-properties-of<T>`    | `TPropertiesOf`          | Niche    | Psalm-only                       |
| `private-properties-of<T>`      | `TPropertiesOf`          | Niche    | Psalm-only                       |
| `class-string-map<T of Foo, T>` | `TClassStringMap`        | Niche    | Maps class-strings to instances  |
| `T[K]`                          | `TTemplateIndexedAccess` | Niche    | Indexed access on template types |
| `arraylike-object`              | `TNamedObject`           | Rare     | Object usable as array           |

## Conditional Types

Syntax: `(condition ? TypeIfTrue : TypeIfFalse)`. Conditions can test `is`, type narrowing on params, or even `func_num_args()`.

```php
// Basic: narrow return type based on a template param
/** @return (T is string ? int : float) */

// Nullable input → nullable output
/** @return ($path is null ? null : string) */

// Return type depends on a boolean flag
/** @return ($choose is true ? TA : TB) */

// Non-empty guard
/** @return ($format is non-empty-string ? non-empty-string : string) */

// Lowercase propagation
/** @return ($lowercase is true ? lowercase-string : string) */

// Null-or-value pattern (common in Laravel)
/** @return ($location is null ? int : int|null) */

// Ternary with union fallback
/** @return ($return is true ? string : void) */
/** @return ($return is true ? string : true) */
/** @return ($return is true ? string : bool) */

// Array emptiness drives return type
/** @return (TArray is non-empty-array ? non-empty-list<key-of<TArray>> : list<key-of<TArray>>) */
/** @return (TArray is array<never, never> ? null : TValue) */
/** @return (TArray is array<never, never> ? false : TValue|false) */

// Nested conditionals
/**
 * @return ($num is int ? positive-int|0 : ($num is float ? float : positive-int|0|float))
 */

// Overload based on argument count
/** @return (func_num_args() is 2 ? (null|list<float|int|string|null>) : int) */
/** @return (func_num_args() is 0 ? array<string, string> : string|false) */
```

## Union and Intersection

```php
int|string              // union
?string                 // shorthand for string|null
Foo&Bar                 // intersection (must satisfy both)
```

## Type Aliases

```php
/** @psalm-type UserId = positive-int */
/** @psalm-import-type UserId from UserService */
```

---

## Docblock Annotations

### Standard PHPDoc (Psalm-aware)

`@var`, `@param`, `@return`, `@property`, `@property-read`, `@property-write`, `@method`, `@throws`, `@deprecated`, `@internal`, `@mixin`

All of the above also accept a `@psalm-` prefix (e.g. `@psalm-param`) for advanced type syntax that phpDocumentor can't parse. PHPStan prefix (`@phpstan-param`, etc.) is also recognized.

### Assertions

| Annotation                           | When it applies           |
|--------------------------------------|---------------------------|
| `@psalm-assert Type $param`          | Function returns normally |
| `@psalm-assert-if-true Type $param`  | Function returns `true`   |
| `@psalm-assert-if-false Type $param` | Function returns `false`  |

### Output Type Narrowing

| Annotation                     | Notes                           |
|--------------------------------|---------------------------------|
| `@psalm-param-out Type $param` | By-ref param type after call    |
| `@psalm-self-out Type`         | `$this` type changes after call |
| `@psalm-this-out Type`         | Same as `self-out`              |
| `@psalm-if-this-is Type`       | Precondition on `$this` type    |

### Suppression and Debugging

| Annotation                        | Notes                                 |
|-----------------------------------|---------------------------------------|
| `@psalm-suppress IssueType`       | Suppress a specific issue             |
| `@psalm-trace`                    | Print inferred type (debug)           |
| `@psalm-check-type`               | Assert inferred type matches          |
| `@psalm-check-type-exact`         | Assert exact type match               |
| `@psalm-ignore-var`               | Ignore `@var` in same docblock        |
| `@psalm-ignore-nullable-return`   | Suppress nullable return issues       |
| `@psalm-ignore-falsable-return`   | Suppress false return issues          |
| `@psalm-ignore-variable-method`   | Ignore variable method in dead code   |
| `@psalm-ignore-variable-property` | Ignore variable property in dead code |

### Purity and Mutability

| Annotation                      | Scope                                                     |
|---------------------------------|-----------------------------------------------------------|
| `@psalm-pure`                   | Function: output depends only on input                    |
| `@psalm-impure`                 | Explicitly marks side effects                             |
| `@psalm-mutation-free`          | Method: no mutation of any state                          |
| `@psalm-external-mutation-free` | Method: may mutate `$this`, nothing external              |
| `@psalm-immutable`              | Class: all properties readonly, all methods mutation-free |
| `@psalm-mutable`                | Class: explicitly not immutable (default)                 |

### Readonly

| Annotation                               | Notes                                    |
|------------------------------------------|------------------------------------------|
| `@psalm-readonly` / `@readonly`          | Property only writable in constructor    |
| `@psalm-allow-private-mutation`          | Readonly but writable in private methods |
| `@psalm-readonly-allow-private-mutation` | Shorthand for both                       |

### Sealing

| Annotation                  | Notes                                |
|-----------------------------|--------------------------------------|
| `@psalm-seal-properties`    | No undeclared `__get`/`__set` access |
| `@psalm-seal-methods`       | No undeclared `__call` access        |
| `@psalm-no-seal-properties` | Reverse seal                         |
| `@psalm-no-seal-methods`    | Reverse seal                         |

### Visibility Overrides

| Annotation                            | Notes                        |
|---------------------------------------|------------------------------|
| `@psalm-override-property-visibility` | Override property visibility |
| `@psalm-override-method-visibility`   | Override method visibility   |

### Class-Level

| Annotation                            | Notes                                         |
|---------------------------------------|-----------------------------------------------|
| `@psalm-consistent-constructor`       | All child constructors match signature        |
| `@psalm-consistent-templates`         | Template params stay consistent in children   |
| `@psalm-inheritors`                   | Restrict which classes can extend             |
| `@psalm-require-extends ClassName`    | Trait only usable in subclasses of ClassName  |
| `@psalm-require-implements Interface` | Trait only usable in implementors             |
| `@psalm-api` / `@api`                 | Mark as used (suppress unused code detection) |
| `@psalm-internal Namespace`           | Restrict usage to a namespace                 |

### Generators and Scope

| Annotation               | Notes                                                                                                                                                                                                         |
|--------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `@psalm-yield TValue`    | On a class/interface: declares what type a generator receives when yielding this object. Used for Promise/Deferred patterns -- `TValue` must be a `@template` param. Psalm resolves it via template expansion |
| `@psalm-variadic`        | On a function: marks it as accepting unlimited arguments even without `...` in the signature. Useful for functions that rely on `func_get_args()` internally                                                  |
| `@psalm-scope-this Type` | On a statement block: overrides the type of `$this` for the enclosed code. Useful for closures bound to other objects at runtime (e.g. `Closure::bind()`, Laravel macros)                                     |

### Stub-Specific

| Annotation             | Notes                                                                                                                                                                                            |
|------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `@psalm-stub-override` | Safety guard for stubs: asserts that the annotated class/method exists in the original codebase. Psalm throws an error if no original counterpart is found, catching typos and stale stubs early |

### Other

| Annotation            | Notes                                     |
|-----------------------|-------------------------------------------|
| `@no-named-arguments` | Disallow named arguments on this function |

---

## Taint Analysis Annotations

| Annotation                           | Notes                                                     |
|--------------------------------------|-----------------------------------------------------------|
| `@psalm-taint-source TaintType`      | Mark return as taint source (e.g. `html`, `sql`, `shell`) |
| `@psalm-taint-sink TaintType $param` | Mark param as taint sink                                  |
| `@psalm-taint-escape TaintType`      | Mark return as escaped/sanitized                          |
| `@psalm-taint-unescape TaintType`    | Mark return as unescaped                                  |
| `@psalm-taint-specialize`            | Track taint per-instance or per-call                      |
| `@psalm-flow ($param) -> return`     | Define explicit taint flow path                           |

Taint types: `html`, `sql`, `shell`, `file`, `cookie`, `header`, `redirect`, `ldap`, `ssrf`, `user_secret`, `system_secret`, `callable`, `eval`, `unserialize`, `include`, `text`, `crypto` (and custom ones).
