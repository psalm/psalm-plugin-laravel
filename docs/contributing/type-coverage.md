---
title: Type Coverage
parent: Contributing
nav_order: 7
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

## 5. String and array callables in `array_map` count one mixed

On Psalm 7.0.0-rc2, `array_map('strval', $keys)` and `array_map([self::class, 'fmt'], $keys)` each count 1 mixed and report no issue. `ArrayMapReturnTypeProvider` analyzes a synthetic call on an array fetch whose offset is mixed, and suppresses `MixedArrayOffset` but not the counter. A typed closure or a first-class callable avoids it:

```php
// BAD: counts as 1 mixed ($keys is list<int|string>)
$names = array_map('strval', $keys);

// GOOD: typed closure, no counter
$names = array_map(static fn(int|string $key): string => (string) $key, $keys);

// GOOD: first-class callable, no counter
$names = array_map(strval(...), $keys);
```

Worked example: `BladeBootstrapper::publishTemplateFacts()` in `src/Blade/BladeBootstrapper.php`.

## 6. Narrow container lookups in a helper, not a local

A mixed argument passed to a `mixed` parameter does not count, so a one-line narrowing helper replaces the local that would hold the mixed value:

```php
// BAD: counts as 1 mixed (assignment)
$resolved = $container->make('view');

return $resolved instanceof Factory ? $resolved : null;

// GOOD: no local, no counter
return $this->asFactory($container->make('view'));

/** @psalm-pure */
private function asFactory(mixed $resolved): ?Factory
{
    return $resolved instanceof Factory ? $resolved : null;
}
```

Worked example: `asCompiler()`, `asFinder()` and `asFactory()` in `src/Blade/BladeBootstrapper.php`.

## 7. Narrow a mixed local with `assert()` or an early exit

When the local is needed, `assert()` or an early-exit guard on it removes both the count and the `MixedAssignment` issue. A ternary does not, and neither does a positive guard:

```php
// GOOD: no counter
$x = $container->make('view');
assert($x instanceof Factory);

// GOOD: no counter
$x = $container->make('view');
if (!$x instanceof Factory) {
    return null;
}

// BAD: counts as 1 mixed, plus MixedAssignment
$x = $container->make('view');

return $x instanceof Factory ? $x : null;

// BAD: same, the positive guard narrows only inside its branch
$x = $container->make('view');
if ($x instanceof Factory) {
    return $x;
}

return null;
```

## 8. Locate the expression Psalm counts

`--stats` prints only per-file counts. To find the lines, back up `vendor/vimeo/psalm/src/Psalm/Internal/Codebase/Analyzer.php` and add this at the top of `incrementMixedCount()` (Psalm 7.0.0-rc2):

```php
foreach (debug_backtrace() as $frame) {
    foreach ($frame['args'] ?? [] as $arg) {
        if ($arg instanceof \PhpParser\Node) {
            file_put_contents('/tmp/mixed.log', $file_path . ':' . $arg->getStartLine() . "\n", FILE_APPEND);
            break 2;
        }
    }
}
```

Run `vendor/bin/psalm --no-cache --threads=1 --no-progress --stats <file>` on a file `--stats` names, then read the log (a file, because forked workers swallow stdout). It lists every increment, so a line that `assert()` or a guard removes again (section 7) shows up too. Restore the backup afterwards.

## Trade-off: try/catch boundaries

Storing a `try`-captured value in a local for use after the catch always produces a mixed assignment when the value is mixed. Two options:

1. Restructure so the mixed value is consumed inline inside the `try`. Preferred when the inner call is pure and will not throw. Document that purity in a comment.
2. Add an `@psalm-var` when restructuring would obscure intent (see pattern 3).

`src/Handlers/Config/ConfigKeyResolver.php::warm()` is the worked example: `ConfigValueReflector::reflect()` is pure, so wrapping it under the same `Throwable` catch as `Repository::has()` and `get()` is acceptable and removes the mixed local.
