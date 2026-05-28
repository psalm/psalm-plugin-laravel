<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Return_;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNonEmptyArray;
use Psalm\Type\Atomic\TNonFalsyString;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

/**
 * Narrows Collection::filter(), Collection::where(), and Collection::whereNotNull() return types.
 *
 * filter() without a callback (or with an explicit null):
 *   Calls array_filter(), removing all falsy values. Removes `null` and `false` from
 *   TValue and narrows `string` → `non-falsy-string`, `array` → `non-empty-array`.
 *   With any callback we can't statically prove what it filters; defer to Psalm's default.
 *
 * where() with a recognized predicate body:
 *   Laravel forwards a callable where() to filter() at runtime. The handler narrows
 *   TValue when the closure body matches one of these AST shapes:
 *
 *   - Identity: `fn ($x) => $x` / `function ($x) { return $x; }` → removeFalsy (drops
 *     null/false, narrows string→non-falsy-string, array→non-empty-array).
 *   - Instanceof: `fn ($x) => $x instanceof Foo` → intersect TValue with `Foo` (keeps
 *     Foo and its subclasses; `mixed` collapses to `Foo`).
 *   - Type-check function: `fn ($x) => is_string($x)` and friends (is_int, is_array,
 *     is_object, is_bool, is_float, is_null, is_callable) → intersect TValue with the
 *     primitive type.
 *
 *   Anything else (property access, complex comparisons, negation, multi-statement
 *   bodies) is opaque to this AST matcher and Psalm uses its default static return.
 *   Larastan does scope-based predicate evaluation via PHPStan's filterByTruthyValue,
 *   which Psalm has no public equivalent for. See issue #1018.
 *
 *   Why where() and not filter(): Laravel's where() declares `callable|string $key`
 *   loosely, so any closure shape passes Psalm's type check. filter() requires
 *   `callable(TValue, TKey): bool`, which rejects identity closures returning the value
 *   (not bool) upstream, so those never reach this handler.
 *
 * whereNotNull() without a key argument:
 *   Removes only `null` from TValue (does not narrow other falsy types).
 *
 * Not covered (intentionally, 80/20):
 * - Numeric falsy types (0, 0.0) are not narrowed — Psalm has no `non-zero-int` atomic
 *   type, so the complexity of constructing `int<min, -1>|int<1, max>` isn't worth it.
 * - `Enumerable` type-hints — the handler only fires for Collection and LazyCollection
 *   concrete types, not the Enumerable interface.
 * - whereNotNull($key) with a string key — we don't narrow TValue when filtering by a
 *   nested field key, since the item type itself is unchanged.
 * - reject() with a callback — symmetric truthy-removal is rarely the predicate's intent.
 * - where(column, operator, value) — column/operator/value form leaves TValue unchanged.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/441
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/706
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1018
 */
final class CollectionFilterHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Collection::class, LazyCollection::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $method = $event->getMethodNameLowercase();

        if ($method === 'filter') {
            return self::handleFilter($event);
        }

        if ($method === 'where') {
            return self::handleWhere($event);
        }

        if ($method === 'wherenotnull') {
            return self::handleWhereNotNull($event);
        }

        return null;
    }

    /** @psalm-mutation-free */
    private static function handleFilter(MethodReturnTypeProviderEvent $event): ?Union
    {
        // Only narrow when called with no arguments (or explicit null).
        // With a callback we can't know what it filters — let Psalm use the default.
        if (!self::isCalledWithoutArgOrNull($event)) {
            return null;
        }

        $templateTypeParameters = $event->getTemplateTypeParameters();
        if ($templateTypeParameters === null || \count($templateTypeParameters) < 2) {
            return null;
        }

        $narrowed = self::removeFalsyTypes($templateTypeParameters[1]);
        if (!$narrowed instanceof Union) {
            return null; // nothing to narrow, or would become empty
        }

        return self::buildNarrowedReturn($event, $templateTypeParameters[0], $narrowed);
    }

    /**
     * Narrow Collection::where(callable) when the closure body matches a recognized
     * shape (identity, instanceof, is_*). Returns null for anything else so Psalm's
     * default static return stands.
     *
     * Not annotated `@psalm-mutation-free` because the instanceof / is_* branches
     * reach into Psalm's codebase (`getCodebase()`, `Type::intersectUnionTypes`) and
     * PhpParser attributes (`Name::getAttribute`), which Psalm treats as impure.
     */
    private static function handleWhere(MethodReturnTypeProviderEvent $event): ?Union
    {
        $args = $event->getCallArgs();
        if (\count($args) !== 1) {
            return null;
        }

        $body = self::extractSingleParamClosureBody($args[0]->value);
        if ($body === null) {
            return null;
        }

        $templateTypeParameters = $event->getTemplateTypeParameters();
        if ($templateTypeParameters === null || \count($templateTypeParameters) < 2) {
            return null;
        }

        [$paramName, $bodyExpr] = $body;
        $tValue = $templateTypeParameters[1];

        // Identity closure body — same value passes the predicate iff truthy.
        if (self::isVarRef($bodyExpr, $paramName)) {
            $narrowed = self::removeFalsyTypes($tValue);
        } else {
            $narrowed = self::narrowByTypeCheck($bodyExpr, $paramName, $tValue, $event->getSource()->getCodebase());
        }

        if (!$narrowed instanceof Union) {
            return null;
        }

        return self::buildNarrowedReturn($event, $templateTypeParameters[0], $narrowed);
    }

    /** @psalm-mutation-free */
    private static function handleWhereNotNull(MethodReturnTypeProviderEvent $event): ?Union
    {
        // Only narrow when called with no key (or explicit null key).
        // With a string key, whereNotNull filters by a nested field — TValue type is unchanged.
        if (!self::isCalledWithoutArgOrNull($event)) {
            return null;
        }

        $templateTypeParameters = $event->getTemplateTypeParameters();
        if ($templateTypeParameters === null || \count($templateTypeParameters) < 2) {
            return null;
        }

        $narrowed = self::removeNullType($templateTypeParameters[1]);
        if (!$narrowed instanceof Union) {
            return null; // nothing to narrow, or would become empty
        }

        return self::buildNarrowedReturn($event, $templateTypeParameters[0], $narrowed);
    }

    /**
     * Build the narrowed return type with the same Collection subclass and is_static.
     * @psalm-mutation-free
     */
    private static function buildNarrowedReturn(
        MethodReturnTypeProviderEvent $event,
        Union $tKey,
        Union $narrowedValue,
    ): Union {
        // is_static: true preserves the `&static` intersection, matching `return static`.
        $className = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();

        return new Union([
            new TGenericObject($className, [$tKey, $narrowedValue], is_static: true),
        ]);
    }

    /**
     * Check if the method was called with no arguments or with an explicit null literal.
     *
     * Both filter(null) and whereNotNull(null) treat an explicit null argument as
     * equivalent to no argument — it means "no callback" and "filter by value itself",
     * respectively.
     *
     * @psalm-mutation-free
     */
    private static function isCalledWithoutArgOrNull(MethodReturnTypeProviderEvent $event): bool
    {
        $args = $event->getCallArgs();

        if ($args === []) {
            return true;
        }

        if (\count($args) === 1) {
            $argValue = $args[0]->value;
            if ($argValue instanceof ConstFetch && \strtolower((string) $argValue->name) === 'null') {
                return true;
            }
        }

        return false;
    }

    /**
     * For a single-param Closure or ArrowFunction, return [$paramName, $bodyExpr].
     * Returns null for unrecognized shapes (variadic, multi-param, multi-statement
     * Closure bodies, variable-variable param names).
     *
     * @return array{string, Expr}|null
     * @psalm-mutation-free
     */
    private static function extractSingleParamClosureBody(Expr $expr): ?array
    {
        if (!$expr instanceof Closure && !$expr instanceof ArrowFunction) {
            return null;
        }

        if (\count($expr->params) !== 1) {
            return null;
        }

        $param = $expr->params[0];
        // Variadic captures all callback args as an array — `fn (...$xs) => $xs` returns
        // [$value, $key] at runtime (always truthy when non-empty), breaking the narrowing
        // semantics for every supported predicate shape.
        if ($param->variadic) {
            return null;
        }

        if (!$param->var instanceof Variable || !\is_string($param->var->name)) {
            return null;
        }

        $paramName = $param->var->name;

        if ($expr instanceof ArrowFunction) {
            return [$paramName, $expr->expr];
        }

        // Closure body must be exactly `return $expr;` (single statement, non-empty return).
        if (\count($expr->stmts) !== 1) {
            return null;
        }

        $stmt = $expr->stmts[0];
        if (!$stmt instanceof Return_ || !$stmt->expr instanceof \PhpParser\Node\Expr) {
            return null;
        }

        return [$paramName, $stmt->expr];
    }

    /** @psalm-mutation-free */
    private static function isVarRef(Expr $expr, string $name): bool
    {
        return $expr instanceof Variable && $expr->name === $name;
    }

    /**
     * Match instanceof and is_* type-check predicates whose argument is the closure
     * param. Returns the intersected TValue, or null if the predicate shape isn't
     * recognized or the intersection is empty.
     */
    private static function narrowByTypeCheck(Expr $body, string $paramName, Union $tValue, Codebase $codebase): ?Union
    {
        $target = self::predicateTargetType($body, $paramName);
        if (!$target instanceof \Psalm\Type\Union) {
            return null;
        }

        $intersected = Type::intersectUnionTypes($tValue, $target, $codebase);
        if (!$intersected instanceof \Psalm\Type\Union || $intersected->getAtomicTypes() === []) {
            return null;
        }

        return $intersected;
    }

    /**
     * Map a recognized type-check predicate body to the Union it narrows TValue toward.
     *
     * Supported shapes:
     *   - `$x instanceof Foo` → `Foo`
     *   - `is_string($x)` / `is_int($x)` / `is_array($x)` / `is_object($x)` /
     *     `is_bool($x)` / `is_float($x)` / `is_null($x)` / `is_callable($x)`
     */
    private static function predicateTargetType(Expr $body, string $paramName): ?Union
    {
        if ($body instanceof Instanceof_
            && self::isVarRef($body->expr, $paramName)
            && $body->class instanceof Name
        ) {
            $fqcn = self::resolveClassName($body->class);
            if ($fqcn === '') {
                return null;
            }

            return new Union([new TNamedObject($fqcn)]);
        }

        if ($body instanceof FuncCall
            && $body->name instanceof Name
            && \count($body->args) >= 1
        ) {
            $firstArg = $body->args[0];
            if (!$firstArg instanceof Arg || !self::isVarRef($firstArg->value, $paramName)) {
                return null;
            }

            // `is_*()` functions are global; the resolved name should match the lowercased short name.
            $name = \strtolower($body->name->toString());

            return match ($name) {
                'is_string' => new Union([new TString()]),
                'is_int', 'is_integer', 'is_long' => new Union([new TInt()]),
                'is_array' => new Union([new TArray([Type::getArrayKey(), Type::getMixed()])]),
                'is_object' => new Union([new TObject()]),
                'is_bool' => new Union([new TBool()]),
                'is_float', 'is_double', 'is_real' => new Union([new TFloat()]),
                'is_null' => new Union([new TNull()]),
                'is_callable' => new Union([new TCallable()]),
                default => null,
            };
        }

        return null;
    }

    /**
     * Resolve a class name node to its FQCN. Prefers Psalm's `resolvedName` attribute
     * (Psalm's SimpleNameResolver stores it as a string via `.toString()`) and falls
     * back to the source spelling.
     *
     * `@psalm-var` (not `@var`) so Rector preserves it (CLAUDE.md). Declaring the local
     * as `string|null` rather than letting it default to mixed avoids the coverage hit
     * that a bare `mixed` assignment would cause.
     */
    private static function resolveClassName(Name $name): string
    {
        /** @psalm-var string|null $resolved */
        $resolved = $name->getAttribute('resolvedName');

        return $resolved ?? $name->toString();
    }

    /**
     * Remove only `null` from the union type. Used for whereNotNull() narrowing.
     *
     * Unlike removeFalsyTypes(), this does not remove `false` or narrow strings/arrays,
     * since whereNotNull() only guarantees items are !== null.
     *
     * Returns null if nothing changed or narrowing would leave the union empty.
     * @psalm-mutation-free
     */
    private static function removeNullType(Union $type): ?Union
    {
        $atomics = $type->getAtomicTypes();
        $filtered = [];
        $changed = false;

        foreach ($atomics as $atomic) {
            if ($atomic instanceof TNull) {
                $changed = true;
                continue;
            }

            $filtered[] = $atomic;
        }

        if (!$changed || $filtered === []) {
            return null;
        }

        return new Union($filtered);
    }

    /**
     * Remove falsy types and narrow remaining types to their non-empty variants.
     *
     * - Removes `null` and `false` entirely
     * - Narrows `string` → `non-falsy-string`, `array` → `non-empty-array`
     *
     * Returns null if nothing changed or narrowing would leave the union empty.
     * @psalm-mutation-free
     */
    private static function removeFalsyTypes(Union $type): ?Union
    {
        $atomics = $type->getAtomicTypes();
        $filtered = [];
        $changed = false;

        foreach ($atomics as $atomic) {
            if ($atomic instanceof TNull || $atomic instanceof TFalse) {
                $changed = true;
                continue;
            }

            $narrowed = self::narrowAtomic($atomic);
            if ($narrowed !== $atomic) {
                $changed = true;
            }

            $filtered[] = $narrowed;
        }

        if (!$changed || $filtered === []) {
            return null;
        }

        return new Union($filtered);
    }

    /**
     * Narrow an atomic type to its non-empty variant where possible.
     *
     * array_filter() removes "", "0", and [] — so `string` becomes `non-falsy-string`
     * (excludes both "" and "0") and `array` becomes `non-empty-array`.
     * Already-narrow subtypes are left as-is.
     *
     * Not narrowed: int/float — Psalm has no `non-zero-int` atomic type, and constructing
     * `int<min, -1>|int<1, max>` adds complexity for a rare use case.
     *
     * @psalm-pure
     */
    private static function narrowAtomic(Atomic $atomic): Atomic
    {
        // Narrow TString but not its subclasses (TNonFalsyString, TNonEmptyString, TLiteralString, etc.)
        if ($atomic::class === TString::class) {
            return new TNonFalsyString();
        }

        // Narrow TArray but not TNonEmptyArray or other subclasses
        if ($atomic::class === TArray::class) {
            return new TNonEmptyArray($atomic->type_params);
        }

        return $atomic;
    }
}
