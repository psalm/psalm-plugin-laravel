<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\HigherOrderCollectionProxy;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Unset_;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\Statements\Expression\ExpressionIdentifier;
use Psalm\Internal\Analyzer\Statements\Expression\NullsafeChainState;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Node\Expr\VirtualMethodCall;
use Psalm\Node\VirtualNode;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeStatementAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TTemplateParam;
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
 * A receiver that is a union of several collection types ({@see resolveProxyReturnTypeForUnion()})
 * is resolved per branch and the branch results are combined.
 *
 * Property access on the proxy (`$collection->map->name`) is typed by {@see afterExpressionAnalysis()}: Psalm
 * analyses the fetch through the item (`@mixin TValue`, or the proxy's `__get` when no item declares the
 * property), and the handler wraps that item-level result with the same proxy mapping. Only a fetch Psalm
 * analyses afresh is retyped; a re-read from scope already carries the wrapped type.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/511
 * @internal
 */
final class HigherOrderCollectionProxyHandler implements
    AfterMethodCallAnalysisInterface,
    MethodReturnTypeProviderInterface,
    MethodParamsProviderInterface,
    BeforeExpressionAnalysisInterface,
    BeforeStatementAnalysisInterface,
    AfterExpressionAnalysisInterface
{
    // Pre-lowercased constant avoids repeated strtolower() in the inner loop,
    // which runs for every method call in the analyzed codebase.
    private const PROXY_CLASS_LOWER = 'illuminate\support\higherordercollectionproxy';

    /**
     * Node attribute on a `$x->{proxy}->{prop}` fetch, written before Psalm analyses it: the fetch's scope key
     * (`''` without one) when Psalm will analyse it afresh, false when it will read it from scope.
     */
    private const FRESH_FETCH_ATTRIBUTE = 'psalmLaravelHigherOrderFreshFetch';

    /**
     * Node attribute on a `$x->{proxy}` fetch holding a proxy: its type when its own analysis finished. Read
     * instead of the node type, which Psalm's `__get` stand-in for the enclosing `$x->{proxy}->{prop}` overwrites
     * with a single non-null atomic (it sets the type on a shallow copy of the node data).
     */
    private const PROXY_TYPE_ATTRIBUTE = 'psalmLaravelHigherOrderProxyType';

    /**
     * Node attribute on a `$x->{proxy}->{prop}` fetch that is the direct operand of isset()/empty()/unset(). PHP
     * checks the property there without `__get`, and the proxy has no `__isset()`/`__unset()`: isset() is false and
     * empty() true at runtime. Everywhere else (the left side of `??`, a fetch behind an array dim, a property or a
     * method call) PHP reads it through `__get`.
     */
    private const PRESENCE_TARGET_ATTRIBUTE = 'psalmLaravelHigherOrderPresenceTarget';

    /**
     * Node attribute on a retyped `$x->{proxy}->{prop}` fetch: `[item-level type, retyped type]`. Psalm analyses
     * some nodes twice in the same node data (the `getIterator()` stand-in of a `foreach`, ArrayAccess
     * `offsetGet()`) and combines the new item-level type with the node's current one; resetting a node that
     * still holds the retyped type keeps that combination item-level, so it is wrapped once.
     */
    private const RETYPE_ATTRIBUTE = 'psalmLaravelHigherOrderRetype';

    /**
     * Every proxy name the handler models, mapped to the shape of its result ({@see resolveProxyReturnType()}).
     * The property-fetch hooks gate on the same keys.
     *
     * - passthrough: the original collection; the called method's return value is ignored (side effects, filters).
     * - boolean, average, percentage, sum: fixed result types, independent of the called method.
     * - first/last: TValue|null; sole: TValue.
     * - max/min: the item-level result or null.
     * - map, flatmap, groupby, partition, keyby: a new collection built from the items.
     *
     * `when` and `unless` are intentionally absent. At runtime `$collection->when->method()` calls
     * `$collection->when(fn($v) => $v->method())`, which (the callable being passed as `$value` with no
     * `$callback`) returns `HigherOrderWhenProxy`, not the collection. These are unusual proxy uses and are not
     * modelled to avoid false-negative type narrowing.
     */
    private const PROXY_KINDS = [
        'each' => 'passthrough',
        'filter' => 'passthrough',
        'reject' => 'passthrough',
        'skipuntil' => 'passthrough',
        'skipwhile' => 'passthrough',
        'sortby' => 'passthrough',
        'sortbydesc' => 'passthrough',
        'takeuntil' => 'passthrough',
        'takewhile' => 'passthrough',
        'unique' => 'passthrough',
        'until' => 'passthrough',
        'contains' => 'boolean',
        'doesntcontain' => 'boolean',
        'every' => 'boolean',
        'hasmany' => 'boolean',
        'hassole' => 'boolean',
        'some' => 'boolean',
        'average' => 'average',
        'avg' => 'average',
        'percentage' => 'percentage',
        'sum' => 'sum',
        'first' => 'first-last',
        'last' => 'first-last',
        'sole' => 'sole',
        'max' => 'max-min',
        'min' => 'max-min',
        'map' => 'map',
        'flatmap' => 'flatmap',
        'groupby' => 'groupby',
        'partition' => 'partition',
        'keyby' => 'keyby',
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

        $proxyExpr = $expr->var;

        foreach ($calleeType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TGenericObject || \strtolower($atomic->value) !== self::PROXY_CLASS_LOWER) {
                continue;
            }

            $typeParams = $atomic->type_params;
            if (\count($typeParams) < 2) {
                continue;
            }

            // Psalm's stand-in call for a property fetch that no item declares (`$c->map->name` becomes
            // `$proxy->__get('name')`): answer the item-level value only. afterExpressionAnalysis() wraps it.
            if ($expr instanceof VirtualMethodCall && self::extractCalledMethodName($expr) === '__get') {
                $itemType = self::arrayItemGet($typeParams[1], $expr);
                if ($itemType instanceof Union) {
                    $event->setReturnTypeCandidate($itemType);
                }

                return;
            }

            // Extract the proxy method name (e.g. "each" from $collection->each->delete())
            $proxyMethod = self::extractProxyMethodNameFromExpr($expr);

            // A receiver union of several collections is resolved per branch. An unknown proxy
            // shape or name declines, keeping Psalm's own result.
            $receiverType = $proxyExpr instanceof PropertyFetch
                ? $source->getNodeTypeProvider()->getType($proxyExpr->var)
                : null;
            if ($receiverType instanceof Union && \count(self::collectionAtomics($receiverType, $source->getCodebase())) > 1) {
                $unionType = $proxyMethod === null ? null : self::resolveProxyReturnTypeForUnion(
                    $receiverType,
                    $proxyMethod,
                    self::extractCalledMethodName($expr),
                    $source->getCodebase(),
                );
                if ($unionType instanceof Union) {
                    $event->setReturnTypeCandidate($unionType);
                }

                return;
            }

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

        // The stand-in `__get` of a property fetch: the item-level value, see afterMethodCallAnalysis().
        if ($stmt instanceof VirtualMethodCall && $event->getMethodNameLowercase() === '__get') {
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

        $proxyMethod = self::extractProxyMethodNameFromFetch($proxyExpr);
        if ($proxyMethod === null) {
            return null;
        }

        if (\count(self::collectionAtomics($collectionType, $source->getCodebase())) > 1) {
            return self::resolveProxyReturnTypeForUnion(
                $collectionType,
                $proxyMethod,
                $event->getMethodNameLowercase(),
                $source->getCodebase(),
            );
        }

        $collectionInfo = self::extractCollectionInfoFromType($collectionType, $source->getCodebase());
        if ($collectionInfo === null) {
            return null;
        }

        [$tKey, $tValue, $collectionClass] = $collectionInfo;

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
    // Before/AfterExpressionAnalysisInterface — property fetch through the proxy
    // -------------------------------------------------------------------------

    /**
     * Records, before Psalm analyses `$x->{proxy}->{prop}`, whether it will read the fetch from scope. Only now is
     * that knowable: afterwards a fresh fetch and a scoped re-read leave the same scope entry behind. Also marks
     * the direct operands of isset()/empty() ({@see PRESENCE_TARGET_ATTRIBUTE}), and puts back the item-level type
     * of a node Psalm analyses again ({@see RETYPE_ATTRIBUTE}).
     */
    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        if ($expr instanceof Isset_ && !$expr instanceof VirtualNode) {
            foreach ($expr->vars as $var) {
                self::markPresenceTarget($var);
            }

            return null;
        }

        if ($expr instanceof Empty_ && !$expr instanceof VirtualNode) {
            self::markPresenceTarget($expr->expr);

            return null;
        }

        if (!$expr instanceof PropertyFetch || !self::isProxiedFetch($expr)) {
            return null;
        }

        $source = $event->getStatementsSource();
        /** @var array{Union, Union}|null $retype */
        $retype = $expr->getAttribute(self::RETYPE_ATTRIBUTE);
        if ($retype !== null && $source->getNodeTypeProvider()->getType($expr) === $retype[1]) {
            $source->getNodeTypeProvider()->setType($expr, $retype[0]);
        }

        $varId = ExpressionIdentifier::getExtendedVarId($expr, $source->getFQCLN(), $source);
        $expr->setAttribute(
            self::FRESH_FETCH_ATTRIBUTE,
            $varId !== null && isset($event->getContext()->vars_in_scope[$varId]) ? false : ($varId ?? ''),
        );

        return null;
    }

    /** Marks the direct operands of unset(), see {@see PRESENCE_TARGET_ATTRIBUTE}. */
    #[\Override]
    public static function beforeStatementAnalysis(BeforeStatementAnalysisEvent $event): ?bool
    {
        $stmt = $event->getStmt();
        if ($stmt instanceof Unset_) {
            foreach ($stmt->vars as $var) {
                self::markPresenceTarget($var);
            }
        }

        return null;
    }

    /**
     * Wraps the item-level type Psalm gave a fresh `$x->{proxy}->{prop}` fetch with the proxy's mapping, keeping
     * its taint `parent_nodes`, and stores the result in scope so re-reads and narrowings see the wrapped type.
     * Records the type of every `$x->{proxy}` fetch holding a proxy, see {@see PROXY_TYPE_ATTRIBUTE}.
     */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $fetch = $event->getExpr();
        if (!$fetch instanceof PropertyFetch && !$fetch instanceof NullsafePropertyFetch) {
            return null;
        }

        if ($fetch instanceof PropertyFetch) {
            self::retypeProxiedFetch($fetch, $event);
        }

        if (self::modelledProxyName($fetch) !== null) {
            $type = $event->getStatementsSource()->getNodeTypeProvider()->getType($fetch);
            if ($type instanceof Union && self::holdsProxy($type)) {
                $fetch->setAttribute(self::PROXY_TYPE_ATTRIBUTE, $type);
            } elseif ($fetch->hasAttribute(self::PROXY_TYPE_ATTRIBUTE)) {
                $fetch->setAttribute(self::PROXY_TYPE_ATTRIBUTE, null);
            }
        }

        return null;
    }

    private static function retypeProxiedFetch(PropertyFetch $fetch, AfterExpressionAnalysisEvent $event): void
    {
        $proxyFetch = $fetch->var;
        $proxyMethod = self::modelledProxyName($proxyFetch);
        $freshKey = $fetch->getAttribute(self::FRESH_FETCH_ATTRIBUTE);
        if ($proxyMethod === null || !\is_string($freshKey)
            || (!$proxyFetch instanceof PropertyFetch && !$proxyFetch instanceof NullsafePropertyFetch)
            || $fetch->getAttribute(self::PRESENCE_TARGET_ATTRIBUTE) === true
        ) {
            return;
        }

        $context = $event->getContext();

        $source = $event->getStatementsSource();
        $types = $source->getNodeTypeProvider();
        $proxyType = $proxyFetch->getAttribute(self::PROXY_TYPE_ATTRIBUTE);
        $psalmType = $types->getType($fetch);
        if (!$proxyType instanceof Union || !$psalmType instanceof Union) {
            return;
        }

        // A scope key that changed during the analysis (an array dim typed meanwhile) leaves fresh vs scoped open.
        $varId = ExpressionIdentifier::getExtendedVarId($fetch, $source->getFQCLN(), $source);
        if (($varId ?? '') !== $freshKey) {
            return;
        }

        // A `?->` short-circuit null is the receiver's, not the item's: Psalm's chain state tells them apart.
        $receiverState = NullsafeChainState::of($proxyFetch);
        $shortCircuit = $receiverState !== NullsafeChainState::None && $proxyType->isNullable();
        $itemType = $psalmType;
        if ($shortCircuit && NullsafeChainState::of($fetch) === NullsafeChainState::ShortCircuit) {
            $item = $psalmType->getBuilder();
            $item->removeType('null');
            if ($item->getAtomicTypes() === []) {
                return;
            }

            $itemType = $item->freeze();
        }

        $result = self::resolveThroughProxy(
            $proxyMethod,
            $proxyType,
            $types->getType($proxyFetch->var),
            $itemType,
            $source->getCodebase(),
        );
        if (!$result instanceof Union) {
            return;
        }

        // Mirrors InstancePropertyFetchAnalyzer: a nullable receiver adds null. Inside isset()/`??` Psalm adds it
        // only for a `?->` short-circuit, but seeds the fetch with null instead when the receiver's null is not
        // ignored; that null sits in the item now, so it is added outside too, keeping the `??` default reachable.
        $builder = $result->getBuilder();
        if ($proxyType->isNullable()
            && ($shortCircuit || !$context->inside_isset || !$proxyType->ignore_nullable_issues)
        ) {
            $builder->addType(new TNull());
            $builder->ignore_nullable_issues = $proxyType->ignore_nullable_issues;
        }

        $builder->parent_nodes = $psalmType->parent_nodes;
        $retyped = $builder->freeze();

        if ($shortCircuit) {
            $receiverState->afterLink($result->isNullable())->markOn($fetch);
        }

        $types->setType($fetch, $retyped);
        // The item type without the short-circuit null: restored as-is, a second analysis would read that null as
        // the link's own and keep it in the items.
        $fetch->setAttribute(self::RETYPE_ATTRIBUTE, [$itemType, $retyped]);
        if ($varId !== null && isset($context->vars_in_scope[$varId])) {
            $context->vars_in_scope[$varId] = $retyped;
        }
    }

    /**
     * The proxy mapping for a property fetch, given the item-level value Psalm computed. Receivers are resolved as
     * for a method call: one readable collection, or a union of collections branch by branch. Any other receiver
     * whose proxy type is still provable (a template, a non-generic or slot-reordering subclass) gets the same
     * mapping over `Enumerable`, keyed and valued from each proxy branch's own params and combined: a supertype of
     * every collection. Declines when the fetched object is not only the proxy (another class's own `$map`).
     *
     * @param lowercase-string $proxyMethod
     */
    private static function resolveThroughProxy(
        string $proxyMethod,
        Union $proxyType,
        ?Union $receiverType,
        Union $itemType,
        Codebase $codebase,
    ): ?Union {
        /** @var list<array{Union, ?Union}> $proxies each proxy branch's TValue and TCollection */
        $proxies = [];
        foreach ($proxyType->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNull) {
                continue;
            }

            if (!$atomic instanceof TGenericObject || \strtolower($atomic->value) !== self::PROXY_CLASS_LOWER) {
                return null;
            }

            $tValue = $atomic->type_params[1] ?? null;
            if ($tValue === null) {
                return null;
            }

            $proxies[] = [$tValue, $atomic->type_params[2] ?? null];
        }

        if ($proxies === []) {
            return null;
        }

        if ($receiverType instanceof Union && \count(self::collectionAtomics($receiverType, $codebase)) > 1) {
            return self::resolveProxyReturnTypeForUnion($receiverType, $proxyMethod, $itemType, $codebase);
        }

        $receiver = $receiverType instanceof Union ? self::readableCollection($receiverType, $codebase) : null;
        if ($receiver instanceof TGenericObject) {
            return self::resolveProxyReturnType(
                $proxyMethod,
                $receiver->type_params[0],
                $receiver->type_params[1],
                $receiver->value,
                $codebase,
                $itemType,
            );
        }

        $combined = null;
        foreach ($proxies as [$tValue, $tCollection]) {
            $branch = self::resolveProxyReturnType(
                $proxyMethod,
                self::enumerableKey($tCollection, $codebase),
                $tValue,
                Enumerable::class,
                $codebase,
                $itemType,
            );
            if (!$branch instanceof Union) {
                return null;
            }

            $combined = $combined instanceof Union ? Type::combineUnionTypes($combined, $branch) : $branch;
        }

        return $combined;
    }

    /**
     * The receiver's only non-null atomic when it is a plain two-slot generic collection, else null.
     *
     * @psalm-capabilities read-props
     */
    private static function readableCollection(Union $receiverType, Codebase $codebase): ?TGenericObject
    {
        $found = null;
        foreach ($receiverType->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNull) {
                continue;
            }

            if ($found instanceof TGenericObject
                || !$atomic instanceof TGenericObject
                || !ClassLineage::isA($codebase, $atomic->value, Enumerable::class)
                || !self::bindsKeyAndValuePositionally($atomic, $codebase)
            ) {
                return null;
            }

            $found = $atomic;
        }

        return $found;
    }

    /**
     * The value `__get()` reads from array items (`$value[$key]`), for Psalm's stand-in `__get` call of a property
     * fetch through the proxy. Only array shapes that all declare the key resolve; anything else stays `mixed`.
     */
    private static function arrayItemGet(Union $tValue, MethodCall $call): ?Union
    {
        $args = $call->getArgs();
        $key = isset($args[0]) && $args[0]->value instanceof String_ ? $args[0]->value->value : null;
        if ($key === null) {
            return null;
        }

        $result = null;
        foreach ($tValue->getAtomicTypes() as $atomic) {
            $value = $atomic instanceof TKeyedArray ? $atomic->properties[$key] ?? null : null;
            if (!$value instanceof Union || $value->possibly_undefined) {
                return null;
            }

            $result = $result instanceof Union ? Type::combineUnionTypes($result, $value) : $value;
        }

        return $result;
    }

    /**
     * The lowercase proxy name of a `$x->{proxy}` / `$x?->{proxy}` fetch the resolver models, else null.
     *
     * @return lowercase-string|null
     * @psalm-mutation-free
     */
    private static function modelledProxyName(Expr $node): ?string
    {
        if ((!$node instanceof PropertyFetch && !$node instanceof NullsafePropertyFetch) || !$node->name instanceof Identifier) {
            return null;
        }

        $name = \strtolower($node->name->name);

        return isset(self::PROXY_KINDS[$name]) ? $name : null;
    }

    /**
     * Whether the node is a `$x->{proxy}->{prop}` / `$x?->{proxy}->{prop}` fetch with a modelled proxy name.
     *
     * @psalm-mutation-free
     */
    private static function isProxiedFetch(Expr $node): bool
    {
        return $node instanceof PropertyFetch && $node->name instanceof Identifier && self::modelledProxyName($node->var) !== null;
    }

    private static function markPresenceTarget(Expr $operand): void
    {
        if (self::isProxiedFetch($operand)) {
            $operand->setAttribute(self::PRESENCE_TARGET_ATTRIBUTE, true);
        }
    }

    /** @psalm-pure */
    private static function holdsProxy(Union $type): bool
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TGenericObject && \strtolower($atomic->value) === self::PROXY_CLASS_LOWER) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Shared logic
    // -------------------------------------------------------------------------

    /**
     * Resolve the return type based on which proxy property was accessed.
     *
     * @param lowercase-string $proxyMethod  The proxy property name (e.g. "each", "map")
     * @param lowercase-string|Union $proxied The method called through the proxy (e.g. "delete"), or the
     *                                        item-level value of a property fetched through it
     * @param string $collectionClass        The concrete collection class
     */
    private static function resolveProxyReturnType(
        string $proxyMethod,
        Union $tKey,
        Union $tValue,
        string $collectionClass,
        Codebase $codebase,
        string|Union $proxied,
    ): ?Union {
        $kind = self::PROXY_KINDS[$proxyMethod] ?? null;
        if ($kind === null) {
            // Unknown proxy method — defer to Psalm's default
            return null;
        }

        return match ($kind) {
            // The same collection type (preserves the concrete subtype)
            'passthrough' => new Union([new TGenericObject($collectionClass, [$tKey, $tValue])]),
            'boolean' => Type::getBool(),
            // avg/average: $reduced[0] / $reduced[1] → float|int|null (div of two ints yields int when exact).
            'average' => new Union([new Type\Atomic\TFloat(), new Type\Atomic\TInt(), new Type\Atomic\TNull()]),
            // percentage: wraps round() → always float|null (never int, unlike avg/average).
            'percentage' => new Union([new Type\Atomic\TFloat(), new Type\Atomic\TNull()]),
            // sum: $result + $callback(...) → always int|float (string callees produce TypeError at runtime).
            'sum' => new Union([new Type\Atomic\TInt(), new Type\Atomic\TFloat()]),
            'first-last' => Type::combineUnionTypes($tValue, Type::getNull()),
            // sole — the single matching item (throws when none or several match), never null
            'sole' => $tValue,
            // max/min — reduce() with null as the initial value, so null is always a possible result for empty
            // collections (common with empty query results). The item-level result is included to preserve
            // precision (e.g. getPrice(): int → int|null instead of mixed|null).
            'max-min' => Type::combineUnionTypes(
                self::proxiedResult($tValue, $proxied, $codebase) ?? Type::getMixed(),
                Type::getNull(),
            ),
            // map — LazyCollection::map() and Support\Collection::map() return the same class. EloquentCollection::map()
            // returns static only when mapped values are Model instances, otherwise Support\Collection (via toBase()).
            // Since proxy maps extract plain values (e.g. ->map->getKey()), Eloquent always gives the base Collection.
            'map' => new Union([
                new TGenericObject(self::toBaseClass($collectionClass, $codebase), [
                    $tKey,
                    self::proxiedResult($tValue, $proxied, $codebase) ?? Type::getMixed(),
                ]),
            ]),
            // flatMap — inner structure is unpacked; static return type preserves LazyCollection.
            // EloquentCollection falls back to base Collection; key widened to array-key.
            'flatmap' => new Union([
                new TGenericObject(self::toBaseClass($collectionClass, $codebase), [Type::getArrayKey(), Type::getMixed()]),
            ]),
            // groupBy — outer key is array-key, inner is re-indexed (preserveKeys defaults to false)
            'groupby' => new Union([
                new TGenericObject($collectionClass, [
                    Type::getArrayKey(),
                    new Union([new TGenericObject($collectionClass, [Type::getInt(), $tValue])]),
                ]),
            ]),
            // partition — always two buckets (truthy/falsy), keyed 0 and 1. EloquentCollection::partition() calls
            // parent::partition()->toBase(), making the outer bucket a base Collection regardless of the receiver.
            'partition' => new Union([
                new TGenericObject(self::toBaseClass($collectionClass, $codebase), [
                    Type::getInt(),
                    new Union([new TGenericObject($collectionClass, [$tKey, $tValue])]),
                ]),
            ]),
            // keyBy — re-keys by method return value
            'keyby' => new Union([new TGenericObject($collectionClass, [Type::getArrayKey(), $tValue])]),
        };
    }

    /**
     * What a proxied call or fetch gives per item: Psalm's item-level value of a property fetch, or the declared
     * return type of the method called on TValue.
     *
     * @param lowercase-string|Union $proxied
     */
    private static function proxiedResult(Union $tValue, string|Union $proxied, Codebase $codebase): ?Union
    {
        return $proxied instanceof Union ? $proxied : self::resolveMethodReturnTypeOnValue($tValue, $proxied, $codebase);
    }

    /**
     * Support\Collection for an Eloquent collection, whose map-like methods return `toBase()` results.
     *
     * @psalm-capabilities read-props
     */
    private static function toBaseClass(string $collectionClass, Codebase $codebase): string
    {
        return ClassLineage::isA($codebase, $collectionClass, EloquentCollection::class) ? Collection::class : $collectionClass;
    }

    /**
     * Resolve the proxy return type for a receiver that is a union of several collections: each
     * branch is typed with its own TKey/TValue/class and the results are combined, so the union
     * keeps what the proxied method adds (first/last add null) instead of Psalm's raw `@mixin
     * TValue` result.
     *
     * Declines (null) for an unknown proxy name, and for a union holding anything besides
     * collections and null (a template or another class whose own `first` proxy would be overwritten).
     * A branch whose TKey/TValue are not provably its first two type params (a non-generic
     * subclass, reordered templates) makes the whole answer mixed rather than a guess.
     *
     * @param Union $receiverType a union with at least two Enumerable atomics
     * @param lowercase-string $proxyMethod
     * @param lowercase-string|Union $proxied see resolveProxyReturnType()
     */
    private static function resolveProxyReturnTypeForUnion(
        Union $receiverType,
        string $proxyMethod,
        string|Union $proxied,
        Codebase $codebase,
    ): ?Union {
        $branches = self::collectionAtomics($receiverType, $codebase);

        foreach ($receiverType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNull && !\in_array($atomic, $branches, true)) {
                return null;
            }
        }

        $combined = null;
        $opaqueBranch = false;

        foreach ($branches as $branch) {
            $readable = $branch instanceof TGenericObject && self::bindsKeyAndValuePositionally($branch, $codebase);
            $opaqueBranch = $opaqueBranch || !$readable;

            // An unreadable branch still resolves, with placeholders, so an unknown proxy name declines.
            $branchType = self::resolveProxyReturnType(
                $proxyMethod,
                $readable ? $branch->type_params[0] : Type::getMixed(),
                $readable ? $branch->type_params[1] : Type::getMixed(),
                $branch->value,
                $codebase,
                $proxied,
            );

            if (!$branchType instanceof Union) {
                return null;
            }

            $combined = $combined instanceof Union ? Type::combineUnionTypes($combined, $branchType) : $branchType;
        }

        return $opaqueBranch ? Type::getMixed() : $combined;
    }

    /**
     * Whether the atomic's class is a plain two-slot collection: it declares exactly two templates
     * and `Enumerable::TKey` / `TValue` resolve to the first / second of them. The resolver rebuilds
     * `Class<TKey, TValue>` positionally, which is wrong for a subclass that reorders or adds
     * templates. Decided from the class declaration alone, since equal instantiated params
     * (`Reversed<string, string>`) do not prove which slot feeds which template.
     *
     * @psalm-capabilities read-props
     */
    private static function bindsKeyAndValuePositionally(TGenericObject $atomic, Codebase $codebase): bool
    {
        try {
            $storage = $codebase->classlike_storage_provider->get($atomic->value);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return false;
        }

        $templates = $storage->template_types ?? [];
        if (\count($atomic->type_params) !== 2 || \count($templates) !== 2) {
            return false;
        }

        $own = [];
        foreach ($templates as $name => $boundByClass) {
            $own[] = new TTemplateParam($name, \reset($boundByClass), $storage->name);
        }

        $ownParams = [new Union([$own[0]]), new Union([$own[1]])];
        $probe = new TGenericObject($storage->name, $ownParams);

        foreach (['TKey' => $own[0], 'TValue' => $own[1]] as $template => $expected) {
            $projected = ClassLineage::projectedTemplate($codebase, $probe, Enumerable::class, $template);
            $actual = $projected?->isSingle() === true ? $projected->getSingleAtomic() : null;

            if (!$actual instanceof TTemplateParam
                || $actual->param_name !== $expected->param_name
                || $actual->defining_class !== $expected->defining_class
            ) {
                return false;
            }
        }

        return true;
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
     * taking one would silently drop the other branches' item types. Such receivers go through
     * {@see resolveProxyReturnTypeForUnion()} instead.
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
