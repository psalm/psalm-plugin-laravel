---
title: Purity and Mutability
parent: Contributing
nav_order: 5
---

# Purity and Mutability

Psalm 6 (the `3.x` line) tracks side effects with four fixed levels. Psalm 7 (`4.x`) replaced them with capability sets (`@psalm-capabilities`); see [Porting from 4.x](#porting-from-4x) when backporting. Verified against Psalm `6.19.1`.

Upstream reference: [supported annotations (6.x)](https://github.com/vimeo/psalm/blob/6.x/docs/annotating_code/supported_annotations.md#psalm-mutation-free).

## The levels

| Tag | Target | Allows | Forbids |
|---|---|---|---|
| `@psalm-pure` (functions also bare `@pure`, `@phpstan-pure`) | function, method | Nothing beyond its arguments | Property reads, statics, I/O, impure calls |
| `@psalm-mutation-free` | method; class (same as `@psalm-immutable`) | Reading `$this` | Writing any property, statics, I/O |
| `@psalm-external-mutation-free` | method, class | Reading and writing `$this`; reading and writing static properties | Writing other objects, I/O |
| (none) | | Everything | |

`@psalm-immutable` on a class makes every property readonly to consumers and every method `@psalm-mutation-free`.

Probed on `6.19.1`: a `@psalm-mutation-free` or `@psalm-pure` method touching a static reports `ImpureStaticProperty`; a `@psalm-external-mutation-free` one does not. Psalm 7 forbids statics at every level.

Not available on Psalm 6 (each is `InvalidDocblock: Unrecognised annotation`): `@psalm-impure`, `@psalm-mutable`, `@psalm-capabilities`, `@psalm-purity-template`, `@psalm-purity-from-template`.

## Callable types

`pure-callable(...)` and `pure-Closure(...)` are the only purity forms. A plain `callable` / `Closure` is impure: a `@psalm-pure` function that calls one reports `ImpureFunctionCall`.

```php
/**
 * @psalm-pure
 * @param pure-callable(int): int $f
 */
function apply(callable $f): int { return $f(1); }
```

`impure-callable`, `self-accessing-callable`, `self-mutating-callable`, `Closure[...]` and `iterable[...]` do not exist on Psalm 6 (`InvalidDocblock`, the type becomes `mixed`).

## Overrides

A purity tag on a parent method is a contract for every override: an override that does more reports `MissingImmutableAnnotation` on Psalm 6 (`ImmutableDependency` on Psalm 7). For a stub method that apps commonly override, such as `Request::input()` (#1622), prefer no tag.

## Plugin policy

- Self-analysis reports `MissingPureAnnotation` (and `MissingImmutableAnnotation` on classes). Annotate with what the issue suggests.
- Psalm 6 rejects `@psalm-pure` / `@psalm-mutation-free` / `@psalm-external-mutation-free` when a callee lacks the matching annotation; Psalm 7 accepts more. Leave a method unannotated rather than suppressing.

## Porting from 4.x

| Psalm 7 (`4.x`) | Psalm 6 (`3.x`) |
|---|---|
| `@psalm-capabilities` (none of the sets below) | Drop the tag |
| `@psalm-capabilities read-props` | `@psalm-mutation-free` |
| `@psalm-capabilities read-props\|write-this-props\|write-refs` | `@psalm-external-mutation-free` |
| `@psalm-impure`, `@psalm-mutable` | Drop the tag |
| `Closure[pure](...)`, `callable[pure](...)` | `pure-Closure(...)`, `pure-callable(...)` |
| `Closure[_]`, other `Closure[...]` / `iterable[...]` | Plain `Closure` / `iterable` |
| `@psalm-purity-template`, `@psalm-purity-from-template` | Drop the tags |

The 4.x [Purity and Capabilities](https://github.com/psalm/psalm-plugin-laravel/blob/4.x/docs/contributing/purity.md) page describes the Psalm 7 model.
