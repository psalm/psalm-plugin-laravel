<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Return_;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Type\Comparator\TypeComparisonResult;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TIterable;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNonEmptyArray;
use Psalm\Type\Atomic\TNonEmptyString;
use Psalm\Type\Atomic\TNonFalsyString;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TResource;
use Psalm\Type\Atomic\TScalar;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

/**
 * Narrows Collection::filter(), Collection::where(), and Collection::whereNotNull() return types.
 *
 * filter() without a callback (or with an explicit null):
 *   Calls array_filter(), removing all falsy values. Removes `null` and `false` from
 *   TValue and narrows `string` → `non-falsy-string`, `array` → `non-empty-array`.
 *
 * filter(callable) and where(callable) with a recognized predicate body:
 *   Laravel forwards a callable where() to filter() at runtime, so both share one
 *   matcher. It needs a single-param (non-variadic, non-by-ref) closure or arrow fn whose
 *   native param type (if any) accepts every TValue atomic without coercion, and whose
 *   body is one expression of these AST shapes (function calls must resolve to the
 *   global builtin, not a namespaced or imported function):
 *
 *   - Identity: `fn ($x) => $x` / `function ($x) { return $x; }` → removeFalsy (drops
 *     null/false, narrows string→non-falsy-string, array→non-empty-array).
 *   - Null check: `!is_null($x)`, `$x !== null`, `null !== $x` → remove `null`.
 *   - Instanceof: `fn ($x) => $x instanceof Foo` → intersect TValue with `Foo` (keeps
 *     Foo and its subclasses; `mixed` collapses to `Foo`).
 *   - Type-check function with one argument: `is_string($x)` and friends (is_int,
 *     is_array, is_object, is_bool, is_float, is_null, is_callable, is_scalar,
 *     is_iterable, is_resource) → intersect TValue with the primitive type.
 *   - Class check: `is_subclass_of($x, Foo::class)` / `is_a($x, Foo::class[, true|false])`
 *     → objects intersect with Foo; strings become `class-string<…&Foo>` only when
 *     strings are allowed (default true for is_subclass_of, false for is_a). Any
 *     template or other unsupported atomic in TValue declines narrowing.
 *
 *   Anything else (key-dependent 2-param callbacks, property access, complex
 *   comparisons, other negations, multi-statement bodies) is opaque to this AST matcher
 *   and Psalm uses its default static return. Larastan does scope-based predicate
 *   evaluation via PHPStan's filterByTruthyValue, which Psalm has no public equivalent
 *   for. See issue #1018.
 *
 *   filter() declares `(callable(TValue, TKey): bool)|null`, so identity closures that
 *   return the value (not bool) are rejected upstream there; where() declares
 *   `callable|string $key` loosely and accepts every shape.
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
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1648
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
            return self::narrowByCallback($event);
        }

        if ($method === 'wherenotnull') {
            return self::handleWhereNotNull($event);
        }

        return null;
    }

    private static function handleFilter(MethodReturnTypeProviderEvent $event): ?Union
    {
        if (!self::isCalledWithoutArgOrNull($event)) {
            return self::narrowByCallback($event);
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
     * Narrow filter(callable) / where(callable) when the sole argument is a closure whose
     * body matches a recognized predicate shape. Returns null for anything else so
     * Psalm's default static return stands.
     *
     * Not annotated `@psalm-mutation-free` because the type-check branches reach into
     * Psalm's codebase (`getCodebase()`, `Type::intersectUnionTypes`) and PhpParser
     * attributes (`Name::getAttribute`), which Psalm treats as impure.
     */
    private static function narrowByCallback(MethodReturnTypeProviderEvent $event): ?Union
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
        $source = $event->getSource();
        $codebase = $source->getCodebase();

        $call = $bodyExpr instanceof BooleanNot ? $bodyExpr->expr : $bodyExpr;
        if ($call instanceof FuncCall && !self::callsGlobalFunction($call, $source)) {
            return null;
        }

        if (!self::paramReceivesItemUnchanged($args[0]->value, $tValue, $source)) {
            return null;
        }

        $classCheck = self::classCheckTarget($bodyExpr, $paramName);

        if (self::isVarRef($bodyExpr, $paramName)) {
            // Identity closure body — same value passes the predicate iff truthy.
            $narrowed = self::removeFalsyTypes($tValue);
        } elseif (self::isNotNullCheck($bodyExpr, $paramName)) {
            $narrowed = self::removeNullType($tValue);
        } elseif ($classCheck !== null) {
            $narrowed = self::narrowByClassCheck($tValue, $classCheck[0], $classCheck[1], $codebase);
        } else {
            $narrowed = self::narrowByTypeCheck($bodyExpr, $paramName, $tValue, $codebase);
        }

        if (!$narrowed instanceof Union) {
            return null;
        }

        return self::buildNarrowedReturn($event, $templateTypeParameters[0], $narrowed);
    }

    /**
     * Whether the call resolves to a global function the way PHP does: a same-named function
     * in the current namespace or a `use function` import wins over the builtin.
     */
    private static function callsGlobalFunction(FuncCall $call, StatementsSource $source): bool
    {
        if (!$call->name instanceof Name || !$source instanceof StatementsAnalyzer) {
            return false;
        }

        $name = $call->name->toString();
        if ($call->name instanceof FullyQualified) {
            return !\str_contains($name, '\\');
        }

        $functions = $source->getCodebase()->functions;
        $resolved = $functions->getFullyQualifiedFunctionNameFromString($name, $source);

        return $resolved === $name || !$functions->functionExists($source, \strtolower($resolved));
    }

    /**
     * Laravel invokes the callback in coercive typing mode, so a scalar-typed param may receive
     * a converted item (`Stringable` → string, int → string). Narrowing is only sound when every
     * TValue atomic already fits the param's native type without a cast.
     */
    private static function paramReceivesItemUnchanged(Expr $closure, Union $tValue, StatementsSource $source): bool
    {
        foreach ($source->getNodeTypeProvider()->getType($closure)?->getAtomicTypes() ?? [] as $atomic) {
            if (!$atomic instanceof TClosure) {
                continue;
            }

            $signature = $atomic->params[0]->signature_type ?? null;
            if (!$signature instanceof Union) {
                return true;
            }

            $comparison = new TypeComparisonResult();

            return UnionTypeComparator::isContainedBy(
                $source->getCodebase(),
                $tValue,
                $signature,
                union_comparison_result: $comparison,
                allow_float_int_equality: false,
            ) && $comparison->to_string_cast !== true;
        }

        return false;
    }

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
     */
    private static function isCalledWithoutArgOrNull(MethodReturnTypeProviderEvent $event): bool
    {
        $args = $event->getCallArgs();

        if ($args === []) {
            return true;
        }

        return \count($args) === 1 && self::isNullConst($args[0]->value);
    }

    /** @psalm-mutation-free */
    private static function isNullConst(Expr $expr): bool
    {
        return $expr instanceof ConstFetch && \strtolower((string) $expr->name) === 'null';
    }

    /**
     * For a single-param Closure or ArrowFunction, return [$paramName, $bodyExpr].
     * Returns null for unrecognized shapes (variadic, by-ref, multi-param, multi-statement
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
        // semantics for every supported predicate shape. By-ref params are opaque for the
        // same reason: the callback no longer receives the item as a plain value.
        if ($param->variadic || $param->byRef) {
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
     *   - `is_string($x)` / `is_int($x)` / `is_array($x)` / `is_object($x)` / `is_bool($x)` /
     *     `is_float($x)` / `is_null($x)` / `is_callable($x)` / `is_scalar($x)` /
     *     `is_iterable($x)` / `is_resource($x)`, with exactly one argument
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

        // An extra argument changes semantics (`is_callable($x, true)` only checks syntax).
        return match (self::singleArgFunctionName($body, $paramName)) {
            'is_string' => new Union([new TString()]),
            'is_int', 'is_integer', 'is_long' => new Union([new TInt()]),
            'is_array' => new Union([new TArray([Type::getArrayKey(), Type::getMixed()])]),
            'is_object' => new Union([new TObject()]),
            'is_bool' => new Union([new TBool()]),
            // No is_real: removed in PHP 8, so a userland global function may own the name.
            'is_float', 'is_double' => new Union([new TFloat()]),
            'is_null' => new Union([new TNull()]),
            'is_callable' => new Union([new TCallable()]),
            'is_scalar' => new Union([new TScalar()]),
            // Objects pass is_iterable() only as Traversable; plain TIterable would drop them.
            'is_iterable' => new Union([new TIterable(), new TNamedObject(\Traversable::class)]),
            'is_resource' => new Union([new TResource()]),
            default => null,
        };
    }

    /** `!is_null($x)`, `$x !== null`, or `null !== $x`. */
    private static function isNotNullCheck(Expr $body, string $paramName): bool
    {
        if ($body instanceof BooleanNot) {
            return self::singleArgFunctionName($body->expr, $paramName) === 'is_null';
        }

        return $body instanceof NotIdentical
            && ((self::isVarRef($body->left, $paramName) && self::isNullConst($body->right))
                || (self::isNullConst($body->left) && self::isVarRef($body->right, $paramName)));
    }

    /** Lowercased name of a `func($x)` call whose only argument is the closure param. */
    private static function singleArgFunctionName(Expr $expr, string $paramName): ?string
    {
        $call = self::functionCall($expr);
        if ($call === null || \count($call[1]) !== 1 || !self::isVarRef($call[1][0], $paramName)) {
            return null;
        }

        return $call[0];
    }

    /**
     * Lowercased function name and positional argument values of a static-name call.
     * Null for dynamic names, unpacked or named args, and first-class callable syntax.
     *
     * @return array{string, list<Expr>}|null
     */
    private static function functionCall(Expr $expr): ?array
    {
        if (!$expr instanceof FuncCall || !$expr->name instanceof Name) {
            return null;
        }

        $values = [];
        foreach ($expr->args as $arg) {
            if (!$arg instanceof Arg || $arg->unpack || $arg->name instanceof Identifier) {
                return null;
            }

            $values[] = $arg->value;
        }

        return [$expr->name->toLowerString(), $values];
    }

    /**
     * Match `is_a($x, Foo::class[, true|false])` / `is_subclass_of($x, Foo::class[, true|false])`.
     *
     * @return array{string, bool}|null [FQCN, allow_string]
     */
    private static function classCheckTarget(Expr $body, string $paramName): ?array
    {
        $call = self::functionCall($body);
        if ($call === null || ($call[0] !== 'is_a' && $call[0] !== 'is_subclass_of')) {
            return null;
        }

        [$name, $args] = $call;
        $count = \count($args);
        if ($count < 2 || $count > 3 || !self::isVarRef($args[0], $paramName)) {
            return null;
        }

        $class = $args[1];
        if (!$class instanceof ClassConstFetch
            || !$class->class instanceof Name
            || $class->class->isSpecialClassName()
            || !$class->name instanceof Identifier
            || $class->name->toLowerString() !== 'class'
        ) {
            return null;
        }

        // PHP defaults: is_subclass_of() accepts class strings, is_a() does not.
        $allowString = $name === 'is_subclass_of';
        if ($count === 3) {
            $flag = $args[2];
            if (!$flag instanceof ConstFetch || !\in_array($flag->name->toLowerString(), ['true', 'false'], true)) {
                return null;
            }

            $allowString = $flag->name->toLowerString() === 'true';
        }

        $fqcn = self::resolveClassName($class->class);

        return $fqcn === '' ? null : [$fqcn, $allowString];
    }

    /**
     * Narrow TValue for an is_a() / is_subclass_of() predicate. Returns null when any atomic
     * is unsupported or nothing survives.
     */
    private static function narrowByClassCheck(Union $tValue, string $fqcn, bool $allowString, Codebase $codebase): ?Union
    {
        $narrowed = [];
        foreach ($tValue->getAtomicTypes() as $atomic) {
            $kept = self::classCheckAtomic($atomic, $fqcn, $allowString, $codebase);
            if ($kept === null) {
                return null;
            }

            \array_push($narrowed, ...$kept);
        }

        return $narrowed === [] ? null : new Union($narrowed);
    }

    /**
     * Objects intersect with Foo; strings become `class-string<…&Foo>` when allowed; other
     * scalars, null, and arrays drop out ([]). Null for atomics these rules don't cover
     * (templates, mixed variants, refined strings, callable, ...).
     *
     * @return list<Atomic>|null
     */
    private static function classCheckAtomic(Atomic $atomic, string $fqcn, bool $allowString, Codebase $codebase): ?array
    {
        $target = new Union([new TNamedObject($fqcn)]);

        if ($atomic::class === TMixed::class) {
            return $allowString
                ? [new TNamedObject($fqcn), new TClassString($fqcn, new TNamedObject($fqcn))]
                : [new TNamedObject($fqcn)];
        }

        if ($atomic instanceof TObject || $atomic instanceof TNamedObject) {
            return \array_values(Type::intersectUnionTypes(new Union([$atomic]), $target, $codebase)?->getAtomicTypes() ?? []);
        }

        if ($atomic instanceof TString && $allowString) {
            // class-string<Foo> is a subtype only of these; other refinements (literal, lowercase,
            // numeric, template/dependent class strings) would be lost or widened.
            $mappable = [TString::class, TNonEmptyString::class, TNonFalsyString::class, TClassString::class];
            if (!\in_array($atomic::class, $mappable, true)) {
                return null;
            }

            $asType = $atomic instanceof TClassString ? $atomic->as_type : null;
            $classes = $asType instanceof TNamedObject
                ? Type::intersectUnionTypes(new Union([$asType]), $target, $codebase)
                : $target;

            $classStrings = [];
            foreach ($classes?->getAtomicTypes() ?? [] as $class) {
                if (!$class instanceof TNamedObject) {
                    return null;
                }

                $classStrings[] = new TClassString($class->value, $class);
            }

            return $classStrings;
        }

        $dropped = $atomic instanceof TString || $atomic instanceof TInt || $atomic instanceof TFloat
            || $atomic instanceof TBool || $atomic instanceof TNull
            || $atomic instanceof TArray || $atomic instanceof TKeyedArray;

        return $dropped ? [] : null;
    }

    /**
     * Resolve a class name node to its FQCN. Prefers Psalm's `resolvedName` attribute
     * (Psalm's SimpleNameResolver stores it as a string via `.toString()`) and falls
     * back to the source spelling.
     *
     * `@psalm-var` (not `@var`) so Rector preserves it (Rector ignores `@psalm-` prefixed annotations).
     * Declaring the local as `string|null` rather than letting it default to mixed avoids the coverage hit
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
