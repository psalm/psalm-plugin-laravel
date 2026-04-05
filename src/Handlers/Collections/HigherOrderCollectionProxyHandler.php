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
 * Provides return types for method calls on HigherOrderCollectionProxy.
 *
 * Laravel's proxy delegates `->method()` to each item via `__call`:
 *   $collection->each->delete()  ≡  $collection->each(fn ($v) => $v->delete())
 *
 * Psalm resolves `->delete()` through the proxy's `__call` magic method. This
 * handler intercepts the return type in that path: MissingMethodCallHandler calls
 * the return type provider, and if we return a non-null type, the magic method
 * is considered handled (no UndefinedMagicMethod reported).
 *
 * Template params are extracted from the COLLECTION expression (the receiver
 * of the property fetch), not from the proxy itself, because Psalm doesn't
 * resolve trait `@property-read` template params during substitution.
 *
 * The return type depends on which proxy property was accessed:
 * - passthrough (each, filter, reject, …) → static<TKey, TValue>
 * - mapping (map) → Collection<TKey, TMethodReturn>
 * - boolean (contains, every, some) → bool
 * - aggregation (avg, sum) → numeric types
 * - first/last → TValue|null
 *
 * Property access on the proxy ($collection->map->name) is NOT yet supported —
 * Psalm's PropertyTypeProviderEvent doesn't expose template type parameters.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/511
 * @internal
 */
final class HigherOrderCollectionProxyHandler implements
    MethodReturnTypeProviderInterface,
    MethodParamsProviderInterface
{
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

    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [HigherOrderCollectionProxy::class];
    }

    /**
     * Provide method params for proxied method calls.
     *
     * MissingMethodCallHandler calls checkMethodArgs() after the return type
     * provider succeeds, which tries to get params for the magic method
     * (e.g., HigherOrderCollectionProxy::delete). Without this provider, Psalm
     * throws UnexpectedValueException because the method has no storage.
     *
     * MethodParamsProviderEvent doesn't provide enough context (no AST node,
     * no template params) to resolve the actual params from TValue. We return
     * a single variadic mixed param to accept any arguments without Psalm
     * reporting TooManyArguments on valid calls like ->each->update([...]).
     * @psalm-pure
     */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        return [
            new FunctionLikeParameter('args', false, Type::getMixed(), is_variadic: true),
        ];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $collectionInfo = self::extractCollectionInfo($event);
        if ($collectionInfo === null) {
            return null;
        }

        [$tKey, $tValue, $collectionClass] = $collectionInfo;

        // Determine which proxy property was accessed (each, map, filter, …)
        $proxyMethod = self::extractProxyMethodName($event);
        if ($proxyMethod === null) {
            return null;
        }

        return self::resolveProxyReturnType($proxyMethod, $tKey, $tValue, $collectionClass, $event);
    }

    /**
     * Extract TKey, TValue, and the collection class from the event context.
     *
     * @return array{Union, Union, string}|null [TKey, TValue, collectionClassName]
     */
    private static function extractCollectionInfo(MethodReturnTypeProviderEvent $event): ?array
    {
        $source = $event->getSource();
        $stmt = $event->getStmt();

        if (!$source instanceof StatementsAnalyzer || !$stmt instanceof MethodCall) {
            return null;
        }

        // $stmt->var is the proxy expression (e.g., $collection->each)
        $proxyExpr = $stmt->var;

        if (!$proxyExpr instanceof PropertyFetch) {
            return null;
        }

        // $proxyExpr->var is the collection expression (e.g., $collection)
        $collectionType = $source->getNodeTypeProvider()->getType($proxyExpr->var);

        if (!$collectionType instanceof Union) {
            return null;
        }

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
     * Map the proxy method to the appropriate return type.
     *
     * @param lowercase-string $proxyMethod
     * @param string $collectionClass The concrete collection class (Collection, LazyCollection, etc.)
     */
    private static function resolveProxyReturnType(
        string $proxyMethod,
        Union $tKey,
        Union $tValue,
        string $collectionClass,
        MethodReturnTypeProviderEvent $event,
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

        // max/min — returns the called method/property result directly
        if ($proxyMethod === 'max' || $proxyMethod === 'min') {
            return self::resolveCalledMethodReturnType($tValue, $event) ?? Type::getMixed();
        }

        // map — always returns base Collection (Laravel's map() returns Collection, not static)
        if ($proxyMethod === 'map') {
            $methodReturnType = self::resolveCalledMethodReturnType($tValue, $event);

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

        // Passthrough proxy methods — return the same collection type
        if (\in_array($proxyMethod, self::PASSTHROUGH_METHODS, true)) {
            return new Union([
                new TGenericObject($collectionClass, [$tKey, $tValue]),
            ]);
        }

        // Unknown proxy method — defer to Psalm's default (mixed from __call)
        return null;
    }

    /**
     * Extract the proxy property name from the AST.
     *
     * For `$collection->each->delete()`:
     *   $stmt = MethodCall(name="delete", var=PropertyFetch(name="each", var=$collection))
     *   We return "each".
     *
     * @return lowercase-string|null
     * @psalm-mutation-free
     */
    private static function extractProxyMethodName(MethodReturnTypeProviderEvent $event): ?string
    {
        $stmt = $event->getStmt();

        if (!$stmt instanceof MethodCall) {
            return null;
        }

        $var = $stmt->var;

        if (!$var instanceof PropertyFetch || !$var->name instanceof Identifier) {
            return null;
        }

        /** @var lowercase-string */
        return \strtolower($var->name->name);
    }

    /**
     * Resolve the return type of the method being called on TValue.
     *
     * For `$collection->map->getName()` where TValue is User,
     * this resolves User::getName()'s return type.
     */
    private static function resolveCalledMethodReturnType(Union $tValue, MethodReturnTypeProviderEvent $event): ?Union
    {
        $codebase = $event->getSource()->getCodebase();
        $calledMethod = $event->getMethodNameLowercase();

        foreach ($tValue->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            $className = $atomic->value;
            $methodId = $className . '::' . $calledMethod;

            if (!self::methodExistsOnClass($codebase, $className, $calledMethod)) {
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
