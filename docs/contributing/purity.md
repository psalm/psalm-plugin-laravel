---
title: Purity and Capabilities
parent: Contributing
nav_order: 5
---

# Purity and Capabilities

Psalm `7.0.0-beta23` replaced the four purity levels (pure, mutation-free, external-mutation-free, impure) with **capabilities**: a set of side effects a function, method, or closure may perform. The old tags still parse and map onto fixed sets. Psalm 6 (the `3.x` line) has none of this; see [Backporting](#backporting-to-3x).

Upstream reference: [Purity and capabilities](https://github.com/vimeo/psalm/blob/master/docs/annotating_code/supported_annotations.md#purity-and-capabilities). Source: `vendor/vimeo/psalm/src/Psalm/Storage/Capabilities.php`.

## The capabilities

| Capability | Allows |
|---|---|
| `read-props` | Reading properties of mutable objects, `$this` included |
| `write-this-props` | Writing or unsetting properties of `$this` |
| `write-props` | Writing or unsetting properties of any other object |
| `read-globals` | Reading static properties, superglobals, `global` variables |
| `write-globals` | Writing them; using `static` variables; process-wide builtins (`mt_rand`, `ini_set`) |
| `write-refs` | Writing through by-reference parameters |
| `io` | `echo`, `print`, `exit` with a message, builtins with side effects (`time`, `random_int`, `file_put_contents`) |

`pure` is the empty set, `impure` is all seven. Unannotated code is `impure`.

Rules that are easy to get wrong:

- Each name is exactly one capability. Writing does not imply reading: `$this->n++` needs `read-props|write-this-props`; `$this->n = 0` needs only `write-this-props`.
- A caller needs every capability of what it calls, implicit calls included (`__toString` on interpolation, `__get`, `ArrayAccess`, `Iterator` methods in `foreach`, constructors on `new`, destructors).
- Calling a `write-this-props` method costs `write-this-props` on `$this`, `write-props` on another object, and nothing on an object the caller created itself.
- Objects reached through global state (statics, superglobals, a function that reads globals) need `write-globals` to mutate, even with `write-props`.
- An override may need fewer capabilities than its parent, never more. Abstract and interface methods must be annotated explicitly.

## Annotations

| Tag | Target | Capability set |
|---|---|---|
| `@psalm-capabilities a\|b` (or `a, b`) | function, method, closure, class | Exactly the listed set. Accepts a `@psalm-type` alias of a set |
| `@psalm-pure` (`@pure`, `@phpstan-pure` on functions) | function, method | `pure`. On a class: every method pure and no property use |
| `@psalm-impure` | function, method | `impure` (the default, stated) |
| `@psalm-mutation-free` | function, method, class | `read-props` |
| `@psalm-external-mutation-free` | function, method, class | `read-props\|write-this-props\|write-refs` |
| `@psalm-immutable` | class | Properties readonly to consumers, methods `read-props` |
| `@psalm-mutable` | class | `impure` (the default, stated) |
| `@psalm-purity-template` | function, method, class | Declares a purity template, see [below](#purity-templates) |
| `@psalm-purity-from-template P` | function, method | Each call also needs the capabilities `P` is bound to |

When a docblock carries several, the first match wins in this order: `pure`, `mutation-free` (or `immutable` on a class), `external-mutation-free`, `impure` (or `mutable`), `capabilities`. A `@psalm-capabilities` line next to `@psalm-pure` is silently ignored.

None of the legacy tags grant `read-globals`, `write-globals`, or `io`. A method that reads or fills a static cache cannot be `@psalm-mutation-free` or `@psalm-external-mutation-free` any more; leave it unannotated or spell the set out.

## Callable types

The capability set goes in square brackets after `callable`, `Closure`, or `iterable`:

```php
Closure[pure](int): int           // also pure-Closure(int): int
callable[read-props|io](): void
Closure[Storage](): void          // a @psalm-type alias of a set
Closure[P](int): int              // a purity template
Closure[pure]                     // any pure closure, parameters unspecified
Closure(): void                   // impure (default); impure-Closure is the same
```

A closure needing fewer capabilities fits where more are allowed: a `pure-Closure` passes for `Closure[io]`, not the reverse. A closure literal's set is inferred from its body. Building a closure is never an effect; calling or passing it costs its set.

`Closure[_]` (or `callable[_]`, `Traversable[_]<K, V>`) in a **parameter** type declares an anonymous purity template: the function inherits the purity of whatever closure is passed for that parameter. It works at any depth (`list<Closure[_](int): int>`) and on `@method` parameters. Outside `@param` it is an `InvalidDocblock`.

```php
/**
 * @psalm-pure
 * @param Closure[_](int): int $callback
 */
function apply(Closure $callback): int { return $callback(1); }
```

## Purity templates

`@psalm-purity-template P` declares a template whose values are capability sets. Use it as `Closure[P](...)`, as a class purity argument (`Box[pure]<int>`, purity arguments before type arguments), and pair it with `@psalm-purity-from-template P` so each call pays for what `P` is bound to. Purity templates are covariant.

Bounds and default: `lower <= Name(default) <= upper`, every part but the name optional, several templates comma separated. With one bound, the side that is a capability is the bound (`io <= C` lower, `C <= io` upper).

```php
/** @psalm-purity-template write-this-props <= C(write-this-props) <= write-this-props|write-props|io */
abstract class Doer {}

/** @extends Doer[write-this-props|io] */
final class Printer extends Doer {}   // ok

/** @extends Doer[io] */
final class Broken extends Doer {}    // InvalidTemplateParam: must include write-this-props

final class Plain extends Doer {}     // C = write-this-props, the default
```

The upper bound must contain the lower bound bit for bit: `write-this-props <= C <= write-props|io` is an `InvalidDocblock`, because `write-props` does not include `write-this-props`.

## Iterables and generators

`Traversable`, `Iterator`, `IteratorAggregate`, `Generator`, and `iterable` carry a purity (`TPurity`, default `impure`): `Iterator[pure]<int, string>` can be consumed by pure code, `iterable[pure]<K, V>` accepts arrays and pure traversables. A generator function with a purity annotation binds its returned generator's purity to its own. A class implementing `Iterator` may bind it in `@implements Iterator[pure]<K, V>`.

## Plugin policy

- Self-analysis reports `MissingPureAnnotation` (and `MissingImmutableAnnotation` on classes). Annotate with what the issue suggests (`psalm --alter --issues=MissingPureAnnotation`); since beta23 the suggestion may be a `@psalm-capabilities` set instead of a legacy tag.
- Methods touching a static (memo caches, registries with `reset()`) stay unannotated: no legacy tag grants `read-globals`.
- Prefer `@psalm-pure` / `@psalm-mutation-free` / `@psalm-external-mutation-free` where they fit exactly: they also parse on Psalm 6, so the `3.x` backport keeps them.
- In stubs, a Laravel method that takes and invokes a callback is a candidate for `Closure[_]`, so pure user code can call it with a pure callback. Only where the Laravel body itself has no other effect.

## Backporting to 3.x

Psalm 6 rejects `@psalm-capabilities`, `@psalm-purity-template`, and `@psalm-purity-from-template` as `Unrecognised annotation`, and does not know the `[...]` purity syntax; only `pure-callable` / `pure-Closure` exist there. A `4.x` change using them must translate to a legacy tag (or drop the annotation) on `3.x`.
