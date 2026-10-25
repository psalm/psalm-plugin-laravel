<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\HigherOrderCollectionProxy;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Provides correct return types for method calls on HigherOrderCollectionProxy.
 *
 * Laravel's proxy delegates `->method()` to each item via `__call`:
 *   $collection->each->delete()  ≡  $collection->each(fn ($v) => $v->delete())
 *
 * There are two resolution paths in Psalm, each requiring a different hook:
 *
 * 1. **`@mixin TValue` path (most common):** Psalm's `@mixin TValue` annotation on
 *    `HigherOrderCollectionProxy` causes it to resolve `delete()` through the item
 *    type (e.g. `Customer`), returning `bool|null`. For chained calls like
 *    `$collection->sortByDesc->getTotal()->values()`, this causes `InvalidMethodCall`
 *    because `int` doesn't have `values()`. `AfterMethodCallAnalysisInterface` fires
 *    AFTER any resolution and overrides the return type with the correct value.
 *
 * 2. **`__call` path (edge cases):** When the mixin resolution doesn't apply (e.g.,
 *    `sealAllMethods` mode with no mixin matching), Psalm falls back to `__call`.
 *    `MethodReturnTypeProviderInterface` intercepts this path and prevents
 *    `UndefinedMagicMethod` errors. `MethodParamsProviderInterface` prevents
 *    `TooManyArguments` errors by providing a variadic mixed param.
 *
 * The return type depends on which proxy property was accessed:
 * - passthrough (each, filter, reject, …) → static<TKey, TValue> (preserves concrete type)
 * - mapping (map) → Collection<TKey, TMethodReturn>
 * - boolean (contains, every, some) → bool
 * - aggregation (avg, sum) → numeric types
 * - first/last → TValue|null
 * - sole → TValue
 *
 * Collection template params (TKey, TValue) and the concrete collection class are extracted
 * from the COLLECTION expression (the receiver of the property fetch), not from the
 * proxy itself, because Psalm doesn't fully resolve trait `@property-read` template
 * params during substitution. The proxy's own params, `HigherOrderCollectionProxy<TMethod,
 * TValue, TCollection>` (Laravel 13.31+ shape, which the stubs declare for every version), are
 * read only by the `Enumerable<TKey, TValue>` fallback: TValue at index 1, the key from the
 * TCollection at index 2 (`array-key` when that is not a generic Enumerable, or when the proxy
 * comes from a Laravel 12.27-13.30 trait annotation that still declares only `<TKey, TValue>`).
 *
 * Property access on the proxy ($collection->map->name) is NOT yet supported —
 * Psalm's PropertyTypeProviderEvent doesn't expose template type parameters.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/511
 * @internal
 */
final class HigherOrderCollectionProxyHandler implements
    AfterMethodCallAnalysisInterface,
    MethodReturnTypeProviderInterface,
    MethodParamsProviderInterface
{
    // Pre-lowercased constant avoids repeated strtolower() in the inner loop,
    // which runs for every method call in the analyzed codebase.
    private const PROXY_CLASS_LOWER = 'illuminate\support\higherordercollectionproxy';

    /**
     * Proxy properties that return the original collection (passthrough).
     * The called method's return value is ignored — these methods operate
     * on items for side effects or filtering.
     *
     * Note: `when` and `unless` are intentionally excluded here. At runtime,
     * `$collection->when->method()` calls `$collection->when(fn($v) => $v->method())`,
     * which — since the callable is passed as `$value` with no `$callback` — returns
     * `HigherOrderWhenProxy`, not the collection. These are unusual proxy uses and
     * are not modelled here to avoid false-negative type narrowing.
     */
    private const PASSTHROUGH_METHODS = [
        'each',
        'filter',
        'reject',
        'skipuntil',
        'skipwhile',
        'sortby',
        'sortbydesc',
        'takeuntil',
        'takewhile',
        'unique',
        'until',
    ];

    /** Proxy properties that return a boolean result. */
    private const BOOLEAN_METHODS = [
        'contains',
        'doesntcontain',
        'every',
        'hasmany',
        'hassole',
        'some',
    ];

    // -------------------------------------------------------------------------
    // AfterMethodCallAnalysisInterface — fires after @mixin TValue resolution
    // -------------------------------------------------------------------------

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall) {
            return;
        }

        $source = $event->getStatementsSource();
        $calleeType = $source->getNodeTypeProvider()->getType($expr->var);

        if (!$calleeType instanceof Union) {
            return;
        }

        foreach ($calleeType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TGenericObject || \strtolower($atomic->value) !== self::PROXY_CLASS_LOWER) {
                continue;
            }

            // A receiver union of several collections cannot be modelled from one branch: keep
            // Psalm's own resolution instead of the single-collection override or fallback.
            $proxyExpr = $expr->var;
            if ($proxyExpr instanceof PropertyFetch) {
                $receiverType = $source->getNodeTypeProvider()->getType($proxyExpr->var);
                if ($receiverType instanceof Union && \count(self::collectionAtomics($receiverType, $source->getCodebase())) > 1) {
                    return;
                }
            }

            $typeParams = $atomic->type_params;
            if (\count($typeParams) < 2) {
                continue;
            }

            // Extract the proxy method name (e.g. "each" from $collection->each->delete())
            $proxyMethod = self::extractProxyMethodNameFromExpr($expr);
            if ($proxyMethod === null) {
                // Unknown proxy shape — fall back to Enumerable<TKey, TValue> to at least
                // allow chaining of collection methods without InvalidMethodCall.
                $event->setReturnTypeCandidate(self::enumerableFallback($typeParams[1], $typeParams[2] ?? null, $source->getCodebase()));
                return;
            }

            // Resolve TKey, TValue, and the concrete collection class from the
            // collection expression (the receiver of the property fetch).
            $collectionInfo = self::extractCollectionInfoFromExpr($expr, $source);
            if ($collectionInfo === null) {
                // Couldn't find the collection — fall back to Enumerable.
                $event->setReturnTypeCandidate(self::enumerableFallback($typeParams[1], $typeParams[2] ?? null, $source->getCodebase()));
                return;
            }

            [$tKey, $tValue, $collectionClass] = $collectionInfo;

            $returnType = self::resolveProxyReturnType(
                $proxyMethod,
                $tKey,
                $tValue,
                $collectionClass,
                $source->getCodebase(),
                self::extractCalledMethodName($expr),
            );

            if ($returnType instanceof Union) {
                $event->setReturnTypeCandidate($returnType);
            }

            return;
        }
    }

    // -------------------------------------------------------------------------
    // MethodReturnTypeProviderInterface — fires for __call path (edge cases)
    // -------------------------------------------------------------------------

    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [HigherOrderCollectionProxy::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $source = $event->getSource();
        $stmt = $event->getStmt();

        // We need a MethodCall (not StaticCall) to access the proxy expression.
        // getNodeTypeProvider() and getCodebase() are on the StatementsSource interface,
        // so no downcast to StatementsAnalyzer is needed.
        if (!$stmt instanceof MethodCall) {
            return null;
        }

        $proxyExpr = $stmt->var;
        if (!$proxyExpr instanceof PropertyFetch) {
            return null;
        }

        $collectionType = $source->getNodeTypeProvider()->getType($proxyExpr->var);
        if (!$collectionType instanceof Union) {
            return null;
        }

        $collectionInfo = self::extractCollectionInfoFromType($collectionType, $source->getCodebase());
        if ($collectionInfo === null) {
            return null;
        }

        [$tKey, $tValue, $collectionClass] = $collectionInfo;

        $proxyMethod = self::extractProxyMethodNameFromFetch($proxyExpr);
        if ($proxyMethod === null) {
            return null;
        }

        return self::resolveProxyReturnType(
            $proxyMethod,
            $tKey,
            $tValue,
            $collectionClass,
            $source->getCodebase(),
            $event->getMethodNameLowercase(),
        );
    }

    // -------------------------------------------------------------------------
    // MethodParamsProviderInterface — prevents TooManyArguments for __call
    // -------------------------------------------------------------------------

    /**
     * Provide method params for proxied method calls.
     *
     * MissingMethodCallHandler calls checkMethodArgs() after the return type
     * provider succeeds, which tries to get params for the magic method
     * (e.g., HigherOrderCollectionProxy::delete). Without this provider, Psalm
     * throws UnexpectedValueException because the method has no storage.
     *
     * We return a single variadic mixed param to accept any arguments without
     * Psalm reporting TooManyArguments on valid calls like ->each->update([...]).
     * @psalm-pure
     */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): array
    {
        return [
            new FunctionLikeParameter('args', false, Type::getMixed(), is_variadic: true),
        ];
    }

    // -------------------------------------------------------------------------
    // Shared logic
    // -------------------------------------------------------------------------

    /**
     * Resolve the return type based on which proxy property was accessed.
     *
     * @param lowercase-string $proxyMethod  The proxy property name (e.g. "each", "map")
     * @param lowercase-string $calledMethod The method called through the proxy (e.g. "delete")
     * @param string $collectionClass        The concrete collection class
     */
    private static function resolveProxyReturnType(
        string $proxyMethod,
        Union $tKey,
        Union $tValue,
        string $collectionClass,
        Codebase $codebase,
        string $calledMethod,
    ): ?Union {
        // Boolean proxy methods — return type doesn't depend on the called method
        if (\in_array($proxyMethod, self::BOOLEAN_METHODS, true)) {
            return Type::getBool();
        }

        // Numeric aggregation proxies — return types don't depend on the called method.
        // avg/average: $reduced[0] / $reduced[1] → float|int|null (div of two ints yields int when exact).
        if (\in_array($proxyMethod, ['average', 'avg'], true)) {
            return new Union([new Type\Atomic\TFloat(), new Type\Atomic\TInt(), new Type\Atomic\TNull()]);
        }

        // percentage: wraps round() → always float|null (never int, unlike avg/average).
        if ($proxyMethod === 'percentage') {
            return new Union([new Type\Atomic\TFloat(), new Type\Atomic\TNull()]);
        }

        // sum: $result + $callback(...) → always int|float (string callees produce TypeError at runtime).
        if ($proxyMethod === 'sum') {
            return new Union([new Type\Atomic\TInt(), new Type\Atomic\TFloat()]);
        }

        // first/last — returns TValue|null
        if ($proxyMethod === 'first' || $proxyMethod === 'last') {
            return Type::combineUnionTypes($tValue, Type::getNull());
        }

        // sole — returns the single matching item (throws when none or several match), never null
        if ($proxyMethod === 'sole') {
            return $tValue;
        }

        // max/min — use reduce() with null as the initial value, so null is always a possible result
        // for empty collections (common with empty query results). The method return type is included
        // to preserve precision (e.g. getPrice(): int → int|null instead of mixed|null).
        if ($proxyMethod === 'max' || $proxyMethod === 'min') {
            $methodReturnType = self::resolveMethodReturnTypeOnValue(
                $tValue,
                $calledMethod,
                $codebase,
            ) ?? Type::getMixed();
            return Type::combineUnionTypes($methodReturnType, Type::getNull());
        }

        // map — return type depends on collection class and what the mapped method returns:
        // - LazyCollection::map() returns LazyCollection (static) → use $collectionClass
        // - EloquentCollection::map() returns static only when mapped values are Model instances;
        //   otherwise it falls back to Support\Collection (via toBase()). Since proxy map calls
        //   extract scalar values (e.g. ->map->getKey()), always return base Collection here.
        // - Support\Collection::map() returns Collection → use $collectionClass (same class)
        if ($proxyMethod === 'map') {
            $methodReturnType = self::resolveMethodReturnTypeOnValue($tValue, $calledMethod, $codebase);
            $mapClass = ClassLineage::isA($codebase, $collectionClass, EloquentCollection::class) ? Collection::class : $collectionClass;

            return new Union([
                new TGenericObject($mapClass, [
                    $tKey,
                    $methodReturnType ?? Type::getMixed(),
                ]),
            ]);
        }

        // flatMap — inner structure is unpacked; static return type preserves LazyCollection.
        // EloquentCollection falls back to base Collection; key widened to array-key.
        if ($proxyMethod === 'flatmap') {
            $flatMapClass = ClassLineage::isA($codebase, $collectionClass, EloquentCollection::class)
                ? Collection::class
                : $collectionClass;
            return new Union([
                new TGenericObject($flatMapClass, [Type::getArrayKey(), Type::getMixed()]),
            ]);
        }

        // groupBy — outer key is array-key, inner is re-indexed (preserveKeys defaults to false)
        if ($proxyMethod === 'groupby') {
            $innerCollection = new TGenericObject($collectionClass, [Type::getInt(), $tValue]);

            return new Union([
                new TGenericObject($collectionClass, [
                    Type::getArrayKey(),
                    new Union([$innerCollection]),
                ]),
            ]);
        }

        // partition — always two buckets (truthy/falsy), keyed 0 and 1.
        // EloquentCollection::partition() calls parent::partition()->toBase(), making the outer
        // bucket a base Collection regardless of the receiver.
        if ($proxyMethod === 'partition') {
            $innerCollection = new TGenericObject($collectionClass, [$tKey, $tValue]);
            $outerClass = ClassLineage::isA($codebase, $collectionClass, EloquentCollection::class)
                ? Collection::class
                : $collectionClass;

            return new Union([
                new TGenericObject($outerClass, [
                    Type::getInt(),
                    new Union([$innerCollection]),
                ]),
            ]);
        }

        // keyBy — re-keys by method return value
        if ($proxyMethod === 'keyby') {
            return new Union([
                new TGenericObject($collectionClass, [Type::getArrayKey(), $tValue]),
            ]);
        }

        // Passthrough proxy methods — return the same collection type (preserves concrete subtype)
        if (\in_array($proxyMethod, self::PASSTHROUGH_METHODS, true)) {
            return new Union([
                new TGenericObject($collectionClass, [$tKey, $tValue]),
            ]);
        }

        // Unknown proxy method — defer to Psalm's default
        return null;
    }

    /**
     * Extract TKey, TValue, and the collection class from a MethodCall expression.
     * Used in the AfterMethodCallAnalysis path where we have the full AST.
     *
     * @return array{Union, Union, string}|null [TKey, TValue, collectionClassName]
     */
    private static function extractCollectionInfoFromExpr(MethodCall $expr, \Psalm\StatementsSource $source): ?array
    {
        // $expr->var is the proxy expression (e.g., $collection->each)
        $proxyExpr = $expr->var;

        if (!$proxyExpr instanceof PropertyFetch) {
            return null;
        }

        // $proxyExpr->var is the collection expression (e.g., $collection)
        $collectionType = $source->getNodeTypeProvider()->getType($proxyExpr->var);

        if (!$collectionType instanceof Union) {
            return null;
        }

        return self::extractCollectionInfoFromType($collectionType, $source->getCodebase());
    }

    /**
     * Extract TKey, TValue, and the collection class from a Union type.
     *
     * Declines (null) when the union holds more than one collection atomic, generic or not:
     * taking one would silently drop the other branches' item types.
     *
     * @return array{Union, Union, string}|null [TKey, TValue, collectionClassName]
     * @psalm-mutation-free
     */
    private static function extractCollectionInfoFromType(Union $collectionType, Codebase $codebase): ?array
    {
        $atomics = self::collectionAtomics($collectionType, $codebase);

        if (\count($atomics) !== 1) {
            return null;
        }

        $atomic = $atomics[0];
        if (!$atomic instanceof TGenericObject || \count($atomic->type_params) < 2) {
            return null;
        }

        return [$atomic->type_params[0], $atomic->type_params[1], $atomic->value];
    }

    /**
     * `Enumerable<TKey, TValue>` rebuilt from the proxy's own type params, for receivers that
     * {@see extractCollectionInfoFromType()} cannot read (a non-generic subclass, whose proxy
     * comes from the vendor trait annotation).
     *
     * @param Union|null $tCollection absent when the vendor annotation is the pre-13.31 `<TKey, TValue>`
     * @psalm-mutation-free
     */
    private static function enumerableFallback(Union $tValue, ?Union $tCollection, Codebase $codebase): Union
    {
        return new Union([
            new TGenericObject(Enumerable::class, [self::enumerableKey($tCollection, $codebase), $tValue]),
        ]);
    }

    /**
     * The key type the proxy's TCollection binds to `Enumerable::TKey`, resolved through the class ancestry
     * ({@see ClassLineage::projectedTemplate()}) so a subclass that reorders its templates still yields its
     * key. `array-key` when that is not provable (no TCollection, non-generic or unknown class, a union).
     *
     * Caveat: the projected key is kept only when it is a flat union of plain int/string key types. A compound
     * `@extends` mapping that still names a template (`Collection<K|int, V>`) falls back to `array-key` rather
     * than leak the unresolved template; see HigherOrderProxyCompoundKeyKnownLimitation.phpt.
     *
     * @psalm-mutation-free
     */
    private static function enumerableKey(?Union $tCollection, Codebase $codebase): Union
    {
        if ($tCollection?->isSingle() === true) {
            $atomic = $tCollection->getSingleAtomic();

            if ($atomic instanceof TNamedObject) {
                $key = ClassLineage::projectedTemplate($codebase, $atomic, Enumerable::class, 'TKey');

                if ($key instanceof Union && self::isPlainKey($key)) {
                    return $key;
                }
            }
        }

        return Type::getArrayKey();
    }

    /**
     * Whether every atomic of a key type is an exact int/string key class (no template, no nested type).
     *
     * @psalm-pure
     */
    private static function isPlainKey(Union $key): bool
    {
        foreach ($key->getAtomicTypes() as $atomic) {
            if (!\in_array($atomic::class, [
                Type\Atomic\TInt::class,
                Type\Atomic\TLiteralInt::class,
                Type\Atomic\TIntRange::class,
                Type\Atomic\TString::class,
                Type\Atomic\TLiteralString::class,
                Type\Atomic\TNonEmptyString::class,
                Type\Atomic\TNonFalsyString::class,
                Type\Atomic\TNumericString::class,
                Type\Atomic\TArrayKey::class,
            ], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<TNamedObject> every named-object atomic of the union that is an Enumerable
     * @psalm-mutation-free
     */
    private static function collectionAtomics(Union $collectionType, Codebase $codebase): array
    {
        $atomics = [];

        foreach ($collectionType->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject && ClassLineage::isA($codebase, $atomic->value, Enumerable::class)) {
                $atomics[] = $atomic;
            }
        }

        return $atomics;
    }

    /**
     * Extract the proxy property name from a MethodCall AST node.
     *
     * For `$collection->each->delete()`:
     *   $expr->var is PropertyFetch(name="each", var=$collection)
     *   We return "each".
     *
     * @return lowercase-string|null
     * @psalm-mutation-free
     */
    private static function extractProxyMethodNameFromExpr(MethodCall $expr): ?string
    {
        return self::extractProxyMethodNameFromFetch($expr->var);
    }

    /**
     * Extract the proxy property name from a PropertyFetch AST node.
     *
     * @return lowercase-string|null
     * @psalm-mutation-free
     */
    private static function extractProxyMethodNameFromFetch(\PhpParser\Node $node): ?string
    {
        if (!$node instanceof PropertyFetch || !$node->name instanceof Identifier) {
            return null;
        }

        /** @var lowercase-string */
        return \strtolower($node->name->name);
    }

    /**
     * Extract the method name being called through the proxy.
     *
     * For `$collection->each->delete()`, returns "delete".
     *
     * @psalm-return lowercase-string
     * @psalm-mutation-free
     */
    private static function extractCalledMethodName(MethodCall $expr): string
    {
        if ($expr->name instanceof Identifier) {
            /** @var lowercase-string */
            return \strtolower($expr->name->name);
        }

        return '';
    }

    /**
     * Resolve the return type of a method called on TValue.
     *
     * For `$collection->map->getKey()` where TValue is User,
     * this resolves User::getKey()'s return type.
     *
     * @param lowercase-string $methodName
     */
    private static function resolveMethodReturnTypeOnValue(
        Union $tValue,
        string $methodName,
        Codebase $codebase,
    ): ?Union {
        // Collect return types from all named-object atomics in TValue (not just the first),
        // so that a union TValue like User|Admin gives User::method()|Admin::method() rather
        // than only User::method().
        $returnTypes = [];

        foreach ($tValue->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            $className = $atomic->value;
            $methodId = $className . '::' . $methodName;

            if (!self::methodExistsOnClass($codebase, $className, $methodName)) {
                continue;
            }

            try {
                $type = $codebase->getMethodReturnType($methodId, $className);
            } catch (\InvalidArgumentException|\UnexpectedValueException|\RuntimeException) {
                // getMethodReturnType() can throw on unusual method storage edge cases
                // even after a methodExistsOnClass() check — fall back to mixed.
                $type = Type::getMixed();
            }

            if ($type instanceof \Psalm\Type\Union) {
                $returnTypes[] = $type;
            }
        }

        if ($returnTypes === []) {
            return null;
        }

        return \array_reduce(
            \array_slice($returnTypes, 1),
            static fn(Union $carry, Union $type): \Psalm\Type\Union => Type::combineUnionTypes($carry, $type),
            $returnTypes[0],
        );
    }

    /**
     * Check method existence via declaring_method_ids to avoid resolving
     * through __call (which would give us mixed instead of the real type).
     * @psalm-mutation-free
     */
    private static function methodExistsOnClass(Codebase $codebase, string $className, string $methodName): bool
    {
        try {
            $classStorage = $codebase->classlike_storage_provider->get(\strtolower($className));
        } catch (\InvalidArgumentException) {
            return false;
        }

        return isset($classStorage->declaring_method_ids[$methodName]);
    }
}
