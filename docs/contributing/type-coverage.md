---
title: Type Coverage
parent: Contributing
nav_order: 6
---

# Keeping self-analysis at 100% type coverage

Plugin self-analysis must report `Psalm was able to infer types for 100% of the codebase`. Verify with:

```bash
composer psalm -- --no-progress --no-suggestions --stats 2>&1 | grep -E "mixed\)" | grep -vE '\b0 mixed'
```

Empty output means 100%. Any printed line names a file with mixed expressions. An empty result also appears when psalm itself failed, so confirm the run succeeded before trusting it.

Psalm's coverage counter fires on mixed expressions, mostly assignments to locals. Closure and function parameters do not count. The patterns below remove the counter honestly, without suppressing anything.

## 1. Inline mixed-returning calls

Do not bind a mixed value to a local variable when it is consumed once:

```php
// BAD: counts as 1 mixed (assignment)
$value = $repo->get($key);
return Reflector::reflect($value);

// GOOD: no local, no counter
return Reflector::reflect($repo->get($key));
```

## 2. Iterate mixed values via `array_map`, not `foreach`

A `foreach` over `array<array-key, mixed>` produces a mixed assignment per element variable. A closure parameter typed `mixed` does not count:

```php
// BAD: $sub_value foreach assignment counts as mixed
foreach ($value as $key => $sub_value) {
    $properties[$key] = self::reflectInternal($sub_value, ...);
}

// GOOD: closure parameter, no counter
$properties = array_map(
    static function (mixed $sub_value) use ($depth, &$budget): Union {
        return self::reflectInternal($sub_value, $depth + 1, $budget);
    },
    $value,
);
```

Gotcha: arrow functions (`static fn(mixed $x) => ...`) capture `use` variables by value only.
If by-reference state matters (for example a `&$budget` counter threaded through recursion), keep the long form `function () use (&$budget)`.
Switching to `fn()` silently breaks mutation propagation across iterations. Cover the budget or limit path with a unit test.

## 3. `@psalm-var mixed` does not help coverage

It suppresses the `MixedAssignment` issue, but the variable stays typed mixed, so the coverage counter still fires.
Use it only when restructuring is impossible, and take the coverage hit deliberately.

## 4. `@psalm-suppress MixedAssignment` is banned

Restructure the code instead. Plugin self-analysis has no baseline file, and none may be introduced (the only baseline in the repo is the Application-fixture snapshot).

## Trade-off: try/catch boundaries

Storing a `try`-captured value in a local for use after the catch always produces a mixed assignment when the value is mixed. Two options:

1. Restructure so the mixed value is consumed inline inside the `try`. Preferred when the inner call is pure and will not throw. Document that purity in a comment.
2. Add an `@psalm-var` when restructuring would obscure intent (see pattern 3).

`src/Handlers/Config/ConfigKeyResolver.php::warm()` is the worked example: `ConfigValueReflector::reflect()` is pure, so wrapping it under the same `Throwable` catch as `Repository::has()` and `get()` is acceptable and removes the mixed local.
