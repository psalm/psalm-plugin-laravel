<?php

declare(strict_types=1);

/**
 * Issue #991 fixture. Simulates a vendor package registering closure macros from
 * a file Psalm does NOT scan ahead of time.
 *
 * This file is intentionally NOT listed under `<projectFiles>`, the `autoloader`
 * attribute, or `<stubs>` in `tests/Type/psalm.xml`. It only reaches PHP at
 * runtime via `require_once` from `macro-fixtures.php` (the autoloader file),
 * which is enough for `Macroable::$macros` to be populated by the time
 * `AfterCodebasePopulated` runs — but not enough for Psalm's
 * {@see \Psalm\Storage\FunctionLikeStorage} to exist for the closure source.
 *
 * The storage path added by #989 misses (`file_storage_provider->has()` returns
 * `false`), and the AST-scan fallback added by #991 (
 * {@see \Psalm\LaravelPlugin\Util\Ast\CachedClosureTypeFactory::fromClosureObject()}
 * ) is the only way for the docblock-only `@param positive-int $count` and
 * `@return non-empty-string` narrowing below to survive into the synthesised
 * pseudo-method.
 *
 * Docblock placement mirrors the motivating Inertia case: the docblock is
 * attached to the wrapping `Stmt\Expression` (the `MacroFixtureBag::macro(...)`
 * call), NOT to the closure node directly. That's the row the issue table
 * identifies as "closure in vendor with docblock-only types".
 */

use Illuminate\Support\Collection;
use Tests\Psalm\LaravelPlugin\Type\Fixtures\MacroFixtureBag;

/**
 * @param positive-int $count
 * @return non-empty-string
 */
MacroFixtureBag::macro('astDocblockReturnTest', static function (int $count) {
    return \str_repeat('y', $count);
});

/**
 * @return Collection<int, string>
 */
MacroFixtureBag::macro('astDocblockGenericTest', static function (): \Illuminate\Support\Collection {
    return new Collection(['a', 'b']);
});

// Issue #991 coverage matrix row 3 — "closure with native return type +
// @param-only narrowing". Reflection sees `int $count`/`string` return; the
// docblock only narrows the parameter to `positive-int`. The recovery path
// must preserve the native string return (no docblock @return) while still
// applying the @param narrowing.
/**
 * @param positive-int $count
 */
MacroFixtureBag::macro('astDocblockParamOnlyTest', static function (int $count): string {
    return \str_repeat('p', $count);
});

// Arrow-function syntax (`fn () => …`) — a common shape for one-liner
// macros in modern Laravel code. The visitor handles `ArrowFunction` and
// `Closure` symmetrically; this fixture locks that branch in.
/**
 * @param positive-int $count
 * @return non-empty-string
 */
MacroFixtureBag::macro('astDocblockArrowFnTest', static fn(int $count) => \str_repeat('a', $count));

// PR #994 fixtures — body-flow inference. None of the closures below have a
// docblock @return OR a native return type, so storage-only and reflection
// recovery both bottom out at `mixed`. The factory's new body-flow inference
// is the only path that can produce a narrower return type.

// Literal string: `fn () => 'hello'` should surface as `'hello'`.
MacroFixtureBag::macro('astBodyInferLiteralStringTest', static fn(): string => 'hello');

// Multi-return union: each branch is a literal, the result is their union.
MacroFixtureBag::macro('astBodyInferUnionTest', static function (): int|string {
    if (\random_int(0, 1) === 0) {
        return 1;
    }

    return 'x';
});

// Unhandled node (`new` expression) — body inference bails. `Expr\New_` is
// firmly outside the PR's rule table; adding it later would be a deliberate
// spec change, not a sloppy refactor of `inferExpression()`. Without any
// source of narrowing, the factory falls back to its null-return path and
// the caller's reflection-only pseudo-method surfaces `mixed`.
MacroFixtureBag::macro('astBodyInferBailsOnComplex', static fn(): \stdClass => new \stdClass());

// Concat of two literal strings should fold to a single literal.
MacroFixtureBag::macro('astBodyInferConcatTest', static fn(): string => 'ab');
