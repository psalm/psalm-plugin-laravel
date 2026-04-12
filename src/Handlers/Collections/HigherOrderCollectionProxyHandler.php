<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\HigherOrderCollectionProxy;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
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
 *
 * Template params (TKey, TValue) and the concrete collection class are extracted
 * from the COLLECTION expression (the receiver of the property fetch), not from the
 * proxy itself, because Psalm doesn't fully resolve trait `@property-read` template
 * params during substitution.
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
     */
    private const PASSTHROUGH_METHODS = [
        'each', 'filter', 'reject',
        'skipuntil', 'skipwhile', 'sortby', 'sortbydesc',
        'takeuntil', 'takewhile', 'unique',
        'unless', 'until', 'when',
    ];

    /** Proxy properties that return a boolean result. */
    private const BOOLEAN_METHODS = [
        'contains', 'doesntcontain', 'every', 'hasmany', 'hassole', 'some',
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

        if ($calleeType === null) {
            return;
        }

        foreach ($calleeType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TGenericObject
                || \strtolower($atomic->value) !== self::PROXY_CLASS_LOWER
            ) {
                continue;
            }

            $typeParams = $atomic->type_params;
            if (\count($typeParams) < 2) {
                return;
            }

            // Extract the proxy method name (e.g. "each" from $collection->each->delete())
            $proxyMethod = self::extractProxyMethodNameFromExpr($expr);
            if ($proxyMethod === null) {
                // Unknown proxy shape — fall back to Enumerable<TKey, TValue> to at least
                // allow chaining of collection methods without InvalidMethodCall.
                $event->setReturnTypeCandidate(new Union([
                    new TGenericObject('Illuminate\Support\Enumerable', [$typeParams[0], $typeParams[1]]),
                ]));
                return;
            }

            // Resolve TKey, TValue, and the concrete collection class from the
            // collection expression (the receiver of the property fetch).
            $collectionInfo = self::extractCollectionInfoFromExpr($expr, $source);
            if ($collectionInfo === null) {
                // Couldn't find the collection — fall back to Enumerable.
                $event->setReturnTypeCandidate(new Union([
                    new TGenericObject('Illuminate\Support\Enumerable', [$typeParams[0], $typeParams[1]]),
                ]));
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

            if ($returnType !== null) {
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

        if (!$source instanceof StatementsAnalyzer || !$stmt instanceof MethodCall) {
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

        $collectionInfo = self::extractCollectionInfoFromType($collectionType);
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

        // Numeric aggregation proxies
        if (\in_array($proxyMethod, ['average', 'avg', 'percentage'], true)) {
            return new Union([new Type\Atomic\TFloat(), new Type\Atomic\TNull()]);
        }

        if ($proxyMethod === 'sum') {
            return new Union([new Type\Atomic\TInt(), new Type\Atomic\TFloat()]);
        }

        // first/last — returns TValue|null
        if ($proxyMethod === 'first' || $proxyMethod === 'last') {
            return Type::combineUnionTypes($tValue, Type::getNull());
        }

        // max/min — returns the called method result directly
        if ($proxyMethod === 'max' || $proxyMethod === 'min') {
            return self::resolveMethodReturnTypeOnValue($tValue, $calledMethod, $codebase) ?? Type::getMixed();
        }

        // map — always returns base Collection (Laravel's map() returns Collection, not static)
        if ($proxyMethod === 'map') {
            $methodReturnType = self::resolveMethodReturnTypeOnValue($tValue, $calledMethod, $codebase);

            return new Union([
                new TGenericObject(Collection::class, [
                    $tKey,
                    $methodReturnType ?? Type::getMixed(),
                ]),
            ]);
        }

        // flatMap — inner structure is unpacked, keys are re-indexed
        if ($proxyMethod === 'flatmap') {
            return new Union([
                new TGenericObject(Collection::class, [Type::getInt(), Type::getMixed()]),
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

        // partition — always two buckets (truthy/falsy), keyed 0 and 1
        if ($proxyMethod === 'partition') {
            $innerCollection = new TGenericObject($collectionClass, [$tKey, $tValue]);

            return new Union([
                new TGenericObject($collectionClass, [
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
    private static function extractCollectionInfoFromExpr(
        MethodCall $expr,
        \Psalm\StatementsSource $source,
    ): ?array {
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

        return self::extractCollectionInfoFromType($collectionType);
    }

    /**
     * Extract TKey, TValue, and the collection class from a Union type.
     *
     * @return array{Union, Union, string}|null [TKey, TValue, collectionClassName]
     * @psalm-mutation-free
     */
    private static function extractCollectionInfoFromType(Union $collectionType): ?array
    {
        foreach ($collectionType->getAtomicTypes() as $atomic) {
            if (
                $atomic instanceof TGenericObject
                && \count($atomic->type_params) >= 2
                && \is_a($atomic->value, Enumerable::class, allow_string: true)
            ) {
                return [$atomic->type_params[0], $atomic->type_params[1], $atomic->value];
            }
        }

        return null;
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
     * @return lowercase-string
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
        foreach ($tValue->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            $className = $atomic->value;
            $methodId = $className . '::' . $methodName;

            if (!self::methodExistsOnClass($codebase, $className, $methodName)) {
                continue;
            }

            return $codebase->getMethodReturnType($methodId, $className);
        }

        return null;
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
