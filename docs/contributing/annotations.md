---
title: Docblock Annotations
parent: Contributing
nav_order: 4
---

# Docblock Annotations

Every docblock tag Psalm 6 reads, with the spellings it accepts. Verified against Psalm `6.19.1`; the `3.x` line requires `^6.16.1`, so tags marked with a later version need that Psalm release. The `4.x` page covers Psalm 7.

The types these tags carry: [Psalm Type Syntax](types.md). Purity tags: [Purity and Mutability](purity.md). Taint tags: [Taint Analysis Stubs](taint-analysis.md#annotations-quick-reference).

Ground truth:

- `vendor/vimeo/psalm/src/Psalm/DocComment.php`: `PSALM_ANNOTATIONS`, the only suffixes allowed after `@psalm-`. Anything else is `InvalidDocblock: Unrecognised annotation @psalm-...`
- `vendor/vimeo/psalm/src/Psalm/Internal/Scanner/DocblockParser.php`: how bare, `@phpstan-` and `@psalm-` spellings merge
- `.../Internal/PhpVisitor/Reflector/FunctionLikeDocblockParser.php`, `ClassLikeDocblockParser.php`, `.../Internal/Analyzer/CommentAnalyzer.php`, `StatementsAnalyzer.php`: per-target readers
- Upstream prose: [Supported annotations](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/supported_annotations.md)

## Prefixes

- Unprefixed and `@phpstan-` tags are not validated: an unknown one is ignored. An unknown `@psalm-` tag is an error.
- `@param`, `@var`, `@param-out`, `@template`, `@extends` and friends merge all three spellings. `@return` does not: `@psalm-return` beats `@phpstan-return` beats `@return`. Templates resolve per name with the same order.
- Use the `@psalm-` form when the type is beyond plain PHPDoc and the file is also read by IDEs or PHPStan. PHPStan ignores `@psalm-*` entirely (a plain `@return` is needed where both engines must agree). Rector strips narrower plain `@var` / `@return` but leaves `@psalm-` tags alone.
- Not accepted: `@psalm-mixin`, `@psalm-throws`, `@psalm-deprecated`, `@phpstan-method`, `@phpstan-property`, bare `@assert`, bare `@self-out`, bare `@immutable` / `@mutation-free`. Psalm 7 only: `@psalm-impure`, `@psalm-mutable`, `@psalm-capabilities`, `@psalm-purity-template`, `@psalm-purity-from-template`.

## Types on declarations

| Tag | Spellings | Target | Notes |
|---|---|---|---|
| `@param Type $name` | bare, `psalm-`, `phpstan-` | parameter | `Type ...$name` for variadics, `Type &$name` for by-ref |
| `@return Type` | bare, `psalm-`, `phpstan-` | function-like | Precedence instead of merge, see above |
| `@var Type [$name]` | bare, `psalm-`, `phpstan-` | property, constant, statement | Inline `@var` narrows a variable |
| `@psalm-ignore-var` | also bare `@ignore-var` | statement | Drops the `@var` of the same docblock (for IDE-only hints) |
| `@param-out Type $name` | bare, `psalm-`, `phpstan-` | by-ref parameter | Type after the call returns |
| `@param-closure-this Type $name` | bare, `psalm-`, `phpstan-` | closure parameter | Type of `$this` inside the passed closure (Psalm 6.19+) |
| `@property Type $name` | bare, `psalm-` | class | Magic property. Also `@property-read`, `@property-write`. Needs a real `__get` / `__set` (or `usePhpDocPropertiesWithoutMagicCall`) |
| `@method [static] Ret name(Type $p = default)` | bare, `psalm-` | class | Magic method. Needs a real `__call` / `__callStatic` in the hierarchy (or `usePhpDocMethodsWithoutMagicCall`), else `UndefinedMethod` |
| `@mixin Foo` | bare only | class, trait | Forward unknown members to `Foo`. A child `@mixin` replaces the parent's |
| `@psalm-type Name = Type` | `psalm-`, `phpstan-` | class, file | Local type alias |
| `@psalm-import-type Name from Foo [as Local]` | `psalm-`, `phpstan-` | class | |

## Generics and templates

| Tag | Spellings | Target | Notes |
|---|---|---|---|
| `@template T [of\|as Bound]` | bare, `psalm-`, `phpstan-` | function-like, class | Invariant. `super Bound` sets a lower bound |
| `@template-covariant T` | bare, `psalm-`, `phpstan-` | class | |
| `@extends Parent<A>` | bare, `psalm-`, `phpstan-`, `@template-extends`, `@inherits` | class, interface | |
| `@implements Iface<A>` | bare, `psalm-`, `phpstan-`, `@template-implements` | class | |
| `@use Trait<A>` | bare, `psalm-`, `phpstan-`, `@template-use` | docblock above `use Trait;` | |
| `@psalm-consistent-templates` | | class | Children keep the template list, so `new static` is safe (`UnsafeGenericInstantiation`) |

No `@template-contravariant` exists.

## Assertions and narrowing

| Tag | Spellings | Target | Notes |
|---|---|---|---|
| `@psalm-assert [!]Type $x` | `psalm-`, `phpstan-` | function-like | After a normal return. Target may be `$x->prop` or `$x->method()` |
| `@psalm-assert-if-true [!]Type $x` | `psalm-`, `phpstan-` | function-like | When the call returns `true` |
| `@psalm-assert-if-false [!]Type $x` | `psalm-`, `phpstan-` | function-like | When the call returns `false` |
| `@psalm-this-out Type` | `psalm-`, `phpstan-`, `@psalm-self-out`, `@phpstan-self-out` | method | Type of `$this` after the call (fluent builders). Parameter-conditional types here need Psalm 6.18.1+ |
| `@psalm-if-this-is Type` | | method | Callable only when `$this` matches |

Negations (`!null`, `!false`) assert the opposite.

## Purity and mutability

Full model: [Purity and Mutability](purity.md).

| Tag | Target |
|---|---|
| `@psalm-pure` (functions also bare `@pure`, `@phpstan-pure`) | function-like, class |
| `@psalm-mutation-free` | method; class (same as `@psalm-immutable`) |
| `@psalm-external-mutation-free` | method, class |
| `@psalm-immutable` | class |

## Properties

| Tag | Target | Notes |
|---|---|---|
| `@psalm-readonly`, `@readonly` | property | Writable only inside the declaring class's constructor |
| `@psalm-allow-private-mutation` | property | With readonly: also writable from the declaring class's other methods |
| `@psalm-readonly-allow-private-mutation` | property | Both in one tag |

## Magic members and sealing

| Tag | Spellings | Target | Notes |
|---|---|---|---|
| `@psalm-seal-properties`, `@psalm-no-seal-properties` | also bare `@seal-properties` | class | Only declared `@property` names pass `__get`/`__set` |
| `@psalm-seal-methods`, `@psalm-no-seal-methods` | also bare `@seal-methods` | class | Only declared `@method` names pass `__call` |
| `@psalm-override-property-visibility` | | class | Let `@property` override real property visibility |
| `@psalm-override-method-visibility` | | class | Let `@method` override real method visibility |

Defaults depend on `sealAllProperties` / `sealAllMethods` in `psalm.xml`.

## Class contracts

| Tag | Target | Notes |
|---|---|---|
| `@psalm-consistent-constructor` | class | Children keep the constructor signature, so `new static(...)` is safe (`UnsafeInstantiation`) |
| `@psalm-require-extends Foo` | trait, abstract class | Users must extend `Foo` |
| `@psalm-require-implements Foo` | trait, interface | Users must implement `Foo` |
| `@psalm-inheritors A\|B` | class, interface | Closed set of allowed subtypes |
| `@final` | class | Treated as final without the keyword |
| `@inheritDoc` | function-like | Inherit the parent docblock (also matched inside description text) |

## Visibility, API, deprecation

| Tag | Target | Notes |
|---|---|---|
| `@psalm-internal Ns\Prefix` | class, function-like, property | Use restricted to the namespace. Argument required |
| `@internal` | class, function-like, property | Unscoped internal |
| `@psalm-api`, `@api` | class, function-like | Public API: exempt from unused-code detection |
| `@deprecated` | class, function-like, property, constant | |
| `@no-named-arguments` | function-like | Named-argument calls are an error; lets variadics infer `list<T>` |

## Suppression and debugging

| Tag | Target | Notes |
|---|---|---|
| `@psalm-suppress Issue[, Issue]` | any declaration, statement | Plugin rule: fix at the source first (hard rule 6) |
| `@psalm-trace $a, $b` | statement | Emits a `Trace` issue with the inferred types |
| `@psalm-check-type $x = Type` | statement | Inferred type must be contained by `Type`; `$x? = ...` also checks possibly-undefined |
| `@psalm-check-type-exact $x = Type` | statement | Inferred type must equal `Type`. The workhorse of `tests/Type/` |
| `@psalm-ignore-nullable-return`, `@psalm-ignore-falsable-return` | function-like | Callers skip `null` / `false` checks on the return |
| `@psalm-ignore-variable-method`, `@psalm-ignore-variable-property` | statement | Unused-code detection ignores `$obj->$name()` / `$obj->$name` |

## Stubs and special cases

| Tag | Target | Notes |
|---|---|---|
| `@psalm-stub-override` | stubbed class, function-like | Error if the real codebase has no such symbol. Catches stale stubs; does not change merge order |
| `@psalm-variadic` | function-like | Accepts any number of arguments without `...` (`func_get_args()` bodies) |
| `@psalm-scope-this Foo` | statement holding a closure | `$this` inside is `Foo` (closures bound at runtime) |
| `@psalm-yield Type` | class, interface | What a `yield $thisObject` sends back (promise libraries); usually a template |
| `@since X.Y` | stubbed class, function-like | In stub files only: PHP version that introduced the symbol |

## Taint analysis

`@psalm-taint-source`, `@psalm-taint-sink`, `@psalm-taint-escape` (plain and conditional), `@psalm-taint-unescape`, `@psalm-taint-specialize`, `@psalm-flow`, `@psalm-assert-untainted`. Semantics, the escape plus flow pairing rule, and the kind list: [Taint Analysis Stubs](taint-analysis.md#annotations-quick-reference). Psalm also reads bare `param-taint` / `return-taint` (a MediaWiki compatibility shim); do not use them.

## Attributes Psalm reads

| Attribute | Equivalent |
|---|---|
| `#[\Deprecated]`, `#[Psalm\Deprecated]`, `#[JetBrains\PhpStorm\Deprecated]` | `@deprecated` |
| `#[\Override]` | Checked: must override something |
| `#[\NoDiscard]` | Callers must use the return value |
| `#[\ReturnTypeWillChange]` | Skips return covariance against a builtin parent |
| `#[Psalm\Pure]`, `#[JetBrains\PhpStorm\Pure]` | `@psalm-pure` |
| `#[Psalm\Immutable]`, `#[JetBrains\PhpStorm\Immutable]` | `@psalm-immutable` |
| `#[Psalm\ExternalMutationFree]` | `@psalm-external-mutation-free` |
| `#[Psalm\Internal]` | `@psalm-internal` (namespace of the declaring class) |
| `#[JetBrains\PhpStorm\NoReturn]` | return type `never` |

Psalm matches the `Psalm\` and `JetBrains\` attributes by name and ships no classes for them, so code using them needs its own class or gets `UndefinedAttributeClass`.
