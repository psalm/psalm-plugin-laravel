---
title: ArrKeyNotFound
parent: Custom Issues
nav_order: 14
---

# ArrKeyNotFound

Emitted when `Arr::get()` or `Arr::pull()` looks up a literal key that cannot exist in the sealed array shape it is given.

Controlled by the `findArrKeyNotFound` flag (see [Configuration](../config.md#findarrkeynotfound)).

## Why this is a problem

A sealed shape (`array{name: string, age: int}`) lists every key the array can hold. A key it does not list is almost always a typo or a stale reference to a renamed field, and the lookup silently returns the default (`null` when none is given) instead of failing.

## Examples

```php
/** @param array{name: string, age: int} $data */
function show(array $data): void
{
    Arr::get($data, 'nmae');             // ArrKeyNotFound, always null
    Arr::pull($data, 'email', 'n/a');    // ArrKeyNotFound, always 'n/a'
}
```

```php
/** @param array{user: array{name: string}} $data */
function show(array $data): void
{
    Arr::get($data, 'user.email');       // ArrKeyNotFound: the dot walk reaches a missing segment
    Arr::get($data, 'user.name');        // fine
}
```

## How to fix

1. Fix the typo in the key
2. If the key is legitimately optional, add it to the shape as optional (`array{name: string, email?: string}`)
3. If the lookup is dead code, delete it and the default with it

## A default does not silence it

The issue is reported with or without a default. A default usually means "this key may be missing on purpose", but on a sealed shape the key is not "maybe missing": it can never be present, so the default is the only possible result and the lookup is dead code. This matches Larastan ([larastan/larastan#2635](https://github.com/larastan/larastan/issues/2635)). If the shape is deliberately incomplete, declare the key as optional or leave the shape unsealed (`array{a: int, ...<string, mixed>}`).

## Configuration

This check is disabled by default. Enable it in your `psalm.xml` (or via `<experimental value="true" />`):

```xml
<plugins>
    <pluginClass class="Psalm\LaravelPlugin\Plugin">
        <findArrKeyNotFound value="true" />
    </pluginClass>
</plugins>
```

Once enabled it reports at Psalm's normal `error` level; override per issue with `<issueHandlers>`.

## Limitations

- Only a single sealed array shape is checked: `array<string, mixed>`, `list<...>`, unsealed shapes, objects such as `ArrayObject`, and a receiver Psalm cannot reduce to one shape are skipped. Psalm merges a union of shapes into one shape with optional keys, so a key present in any member is not reported
- Only a string-literal key is checked; dynamic keys, `null`, and int keys are skipped
- A key that is optional in the shape (`a?: int`) is never reported, since the shape allows it to be present
- A dot walk that crosses a value that is not a sealed shape (a scalar, a plain `array`) is skipped
- A literal key that contains a dot and is itself optional is skipped, because `Arr::get()` can fall through to the dot walk
- Named arguments out of order, and argument unpacking, are skipped
- Shapes declared in docblocks are trusted: a wrong `@param` shape produces a wrong report
