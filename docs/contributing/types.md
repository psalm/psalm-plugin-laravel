---
title: Psalm Type Syntax
parent: Contributing
nav_order: 3
---

# Psalm Type Syntax

Every type expression Psalm 6 accepts inside a docblock, for writing stubs and for building `Union`/`Atomic` values in handlers. Verified against Psalm `6.19.1`; the `3.x` line requires `^6.16.1`, so forms marked with a later version need that Psalm release. The `4.x` page covers Psalm 7.

Companion pages: [Docblock Annotations](annotations.md) (the tags that carry these types), [Purity and Mutability](purity.md) (`pure-callable`), [Taint Analysis Stubs](taint-analysis.md).

Ground truth when this page and Psalm disagree:

- `vendor/vimeo/psalm/src/Psalm/Internal/Type/TypeTokenizer.php`: `PSALM_RESERVED_WORDS`, the words never resolved as class names
- `vendor/vimeo/psalm/src/Psalm/Type/Atomic.php`: `createInner()`, keyword to `Atomic` map
- `vendor/vimeo/psalm/src/Psalm/Internal/Type/TypeParser.php` and `ParseTreeCreator.php`: generics, shapes, callables, conditionals
- Upstream prose: [type syntax docs](https://github.com/vimeo/psalm/tree/6.x/docs/annotating_code/type_syntax)

To check what Psalm makes of an expression, put `/** @psalm-trace $x */` on a statement and run `vendor/bin/psalm --no-cache --threads=1` on a scratch file. The `Atomic` column names the class a handler gets back or constructs.

## Scalars, top and bottom

| Type | Atomic | Notes |
|---|---|---|
| `int`, `float`, `string`, `bool` | `TInt`, `TFloat`, `TString`, `TBool` | `integer`, `double`, `real`, `boolean` are legacy aliases |
| `true`, `false`, `null` | `TTrue`, `TFalse`, `TNull` | |
| `void` | `TVoid` | Return position only |
| `scalar` | `TScalar` | `int\|float\|string\|bool` |
| `numeric` | `TNumeric` | `int\|float\|numeric-string` |
| `array-key` | `TArrayKey` | `int\|string` |
| `mixed` | `TMixed` | Top type |
| `never` | `TNever` | Bottom type. Aliases: `no-return`, `never-return`, `never-returns`, `empty` |
| `object` | `TObject` | Any object |
| `resource` | `TResource` | |
| `closed-resource` | `TClosedResource` | |
| `iterable`, `iterable<V>`, `iterable<K, V>` | `TIterable` | `array\|Traversable` |

## Integer subtypes

| Type | Atomic | Notes |
|---|---|---|
| `positive-int` | `TIntRange` | `int<1, max>` |
| `non-negative-int` | `TIntRange` | `int<0, max>` |
| `negative-int` | `TIntRange` | `int<min, -1>` |
| `non-positive-int` | `TIntRange` | `int<min, 0>` |
| `int<5, 10>`, `int<min, 0>`, `int<1, max>` | `TIntRange` | Bounds are int literals or `min`/`max`. `int<min, max>` is plain `int` |
| `literal-int` | `TNonspecificLiteralInt` | Any int written as a literal in source |
| `int-mask<1, 2, 4>` | `TIntMask` | Any bitwise OR of the listed values (literals or single class constants) |
| `int-mask-of<Foo::FLAG_*>` | `TIntMaskOf` | Same, from a wildcard constant set; also accepts `key-of<...>` / `value-of<...>` |

## String subtypes

| Type | Atomic | Notes |
|---|---|---|
| `non-empty-string` | `TNonEmptyString` | |
| `non-falsy-string` | `TNonFalsyString` | Not `''` and not `'0'`. Alias: `truthy-string` |
| `numeric-string` | `TNumericString` | Passes `is_numeric()` |
| `lowercase-string`, `non-empty-lowercase-string` | `TLowercaseString`, `TNonEmptyLowercaseString` | |
| `literal-string`, `non-empty-literal-string` | `TNonspecificLiteralString`, `TNonEmptyNonspecificLiteralString` | Built only from literals in source |
| `callable-string` | `TCallableString` | Passes `is_callable()` |
| `class-string`, `class-string<Foo>` | `TClassString` | FQCN of `Foo` or a subtype; `Foo` may be a template |
| `interface-string`, `interface-string<Foo>` | `TClassString` | Interface names only |
| `enum-string`, `enum-string<Foo>` | `TClassString` | Enum names only |
| `trait-string` | `TTraitString` | |
| `Foo::class` | `TLiteralClassString` | |

Intersections of string refinements collapse (Psalm 6.19+): `non-empty-string&lowercase-string` is `non-empty-lowercase-string`.

## Literals and constants

| Type | Atomic | Notes |
|---|---|---|
| `42`, `-3`, `1_000` | `TLiteralInt` | `_` separators allowed for ints only |
| `3.14`, `-0.5` | `TLiteralFloat` | No `_` separators |
| `'foo'`, `"foo"` | `TLiteralString` | No heredoc/nowdoc form |
| `Foo::BAR` | `TClassConstant` | Resolved to the constant's literal type |
| `Foo::BAR_*`, `Foo::*` | `TClassConstant` | Union of the matching constants. Trailing `*` or bare `*` only |
| `Suit::Hearts` | `TEnumCase` | Same syntax as a constant; becomes an enum case when `Suit` is an enum |

## Arrays and lists

| Type | Atomic | Notes |
|---|---|---|
| `array` | `TArray` | `array<array-key, mixed>` |
| `array<V>`, `array<K, V>` | `TArray` | One parameter means `array<array-key, V>`. `V[]` also works |
| `non-empty-array<K, V>` | `TNonEmptyArray` | |
| `list<V>`, `non-empty-list<V>` | `TKeyedArray` | Keys `0..n-1` in order |
| `associative-array<K, V>` | `TArray` | Plain alias of `array` |
| `callable-array` | `TKeyedArray` | `[class-string\|object, non-empty-string]` pair. `callable-list` is Psalm 7 only |

### Shapes

```php
array{id: int, name?: string}       // sealed; `?` = key may be absent
array{0: string, 1: int}            // explicit int keys
array{string, int}                  // implicit keys 0, 1 (cannot mix with explicit)
list{string, int}                   // list shape
array{id: int, ...}                 // unsealed: more keys of array-key => mixed
array{id: int, ...<string, int>}    // unsealed with typed extras
list{string, ...<int>}              // unsealed list
array{}                             // the empty array
callable-array{Foo, 'bar'}          // shape that must be callable
object{id: int, name?: string}      // object shape (TObjectWithProperties)
```

Keys may be quoted (`'quoted key': T`) or `Foo::class`; other `Foo::CONST` keys are rejected. Object shapes have no unsealed form: `object{id: int, ...}` parses and drops the `...`.

## Objects

| Type | Atomic | Notes |
|---|---|---|
| `Foo` | `TNamedObject` | |
| `Foo<A, B>` | `TGenericObject` | Class must declare matching `@template`s |
| `self`, `static`, `parent` | `TNamedObject` | `self` and `parent` resolve to the FQCN; `static` stays late-bound |
| `$this` | `TNamedObject` | Same as `static`. Inside generic parameters (`Foo<$this>`) since Psalm 6.17 |
| `Generator<K, V, TSend, TReturn>` | `TGenericObject` | Fewer parameters: missing ones become `mixed`; `Generator<V>` sets the value |
| `Traversable<K, V>`, `Iterator<K, V>`, `IteratorAggregate<K, V>` | `TGenericObject` | One parameter is the value type |
| `callable-object` | `TCallableObject` | Has `__invoke()` |
| `stringable-object` | `TObjectWithProperties` | Has `__toString()` |
| `arraylike-object<K, V>` | intersection | `Traversable<K, V>&ArrayAccess<K, V>&Countable` |

## Callables

```php
callable                                // TCallable
Closure                                 // TClosure
callable(int, string): bool             // typed
Closure(int, string=): void             // `=` optional parameter
callable(int, string...): void          // `...` variadic parameter
callable(int $id, string ...$rest): void // named parameters
pure-callable(int): int                 // may be called from pure code
pure-Closure(int): int
```

By-reference parameters are not expressible: `callable(int &$x): void` is an `InvalidDocblock`. A named parameter cannot carry `=` (`string $name=`) or a `...` before its name (`string... $rest`): Psalm crashes the whole scan on both (vimeo/psalm#11588); write the name only on required or `...$rest` parameters. Psalm 7's bracket purity (`Closure[pure]`, `Closure[_]`) does not exist here; see [Purity and Mutability](purity.md#callable-types).

## Utility types

| Type | Atomic | Notes |
|---|---|---|
| `key-of<T>` | `TKeyOf`, `TTemplateKeyOf` | Keys of an array, shape, constant array, or template |
| `value-of<T>` | `TValueOf`, `TTemplateValueOf` | Values; on a backed enum, the backing values (`value-of<Suit>` is `'h'\|'s'`) |
| `properties-of<T>` | `TPropertiesOf`, `TTemplatePropertiesOf` | Properties as a keyed array |
| `public-properties-of<T>`, `protected-properties-of<T>`, `private-properties-of<T>` | `TPropertiesOf` | Visibility filtered |
| `class-string-map<T of Foo, T>` | `TClassStringMap` | Maps each class-string key to an instance of it. `as` works in place of `of` |
| `T[K]` | `TTemplateIndexedAccess` | `T` and `K` must both be templates of the same declaration |

## Conditional types

`(Subject is Type ? IfTrue : IfFalse)`, where `Subject` is a template, a `$param`, or `func_num_args()`. Parameter subjects work in `@return`, and in `@psalm-self-out` / `@psalm-this-out` since Psalm 6.18.1.

```php
/** @return ($key is null ? array<string, mixed> : mixed) */
/** @return (T is string ? int : float) */
/** @return (func_num_args() is 0 ? static : string) */
/** @return ($num is int ? int : ($num is float ? float : int|float)) */
/** @return (TArray is array<never, never> ? null : TValue) */
```

Plugin traps: write `static`, never `$this`, on the chainable branch of a stub conditional (#888, hard rule 3 in `AGENTS.md`); `func_num_args()` is unsound under a leading spread argument.

## Union, intersection, nullable

```php
int|string      // union
?string         // string|null
Foo&Bar         // intersection: object types only (or keyed arrays)
```

## Templates and aliases

A declared `@template T` is a `TTemplateParam` wherever it appears as a type. Type aliases are declared with `@psalm-type Name = ...` and pulled in with `@psalm-import-type Name from Foo [as Local]` (`TTypeAlias`). Declaration tags live on the [annotations page](annotations.md#generics-and-templates).

## Accepted but misleading

Syntax that parses without the meaning its name suggests. Do not use it in stubs.

| Written | What Psalm does |
|---|---|
| `uppercase-string`, `non-empty-uppercase-string` | Plain `string` / `non-empty-string` (unimplemented in Psalm) |
| `open-resource` | Reserved word but no type: `InvalidDocblock` |
| `non-empty-countable` | Reserved word Psalm uses internally (from `count()` checks); not writable, `InvalidDocblock` even in `@psalm-assert` |
| `empty` | `never`, not "empty value" |
| `impure-callable`, `self-accessing-callable`, `self-mutating-callable` (and `-Closure`), `callable-list` | Not Psalm 6 syntax: `InvalidDocblock`, the type becomes `mixed` |
| `Closure[pure](...)`, `iterable[pure]<K, V>` | Psalm 7 only: `InvalidDocblock`, the type becomes `mixed` |
| `object{a: int, ...}` | Unsealed marker silently dropped |
| `callable(int &$x): void` | `InvalidDocblock`, the whole type is lost |
| `callable(string $name=): void`, `callable(string... $rest): void` | Uncaught exception, Psalm aborts the run |
