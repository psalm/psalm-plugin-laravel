<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Magic;

use PhpParser\Node\Expr\MethodCall;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Union;

/**
 * Intercepts method calls on classes that forward to other classes via @mixin or __call,
 * and preserves the caller's generic type for fluent (self-returning) methods.
 *
 * Two resolution paths:
 * - Path 1 (Mixin interception): Psalm resolves a method via @mixin on the source class
 *   (e.g., HasOne -> @mixin Builder -> Builder::where). The handler fires for Builder,
 *   detects the original caller was a Relation, and returns the Relation's generic type.
 * - Path 2 (Direct __call): The method isn't found via @mixin (mixin is single-hop),
 *   so Psalm falls to __call on the source class. The handler fires for HasOne directly
 *   and resolves via the search classes.
 *
 * Also implements MethodParamsProvider for Path 2 methods (QueryBuilder-only methods
 * like orderBy, limit, groupBy) so Psalm can check arguments.
 */
final class MethodForwardingHandler implements
    MethodReturnTypeProviderInterface,
    MethodParamsProviderInterface
{
    private static ?ForwardingRule $rule = null;

    /** @var array<lowercase-string, bool> Indexed source classes for O(1) lookup */
    private static array $sourceClassIndex = [];

    /** @var array<lowercase-string, bool> Indexed search classes for O(1) lookup in mixin interception */
    private static array $searchClassIndex = [];

    /** @psalm-external-mutation-free */
    public static function init(ForwardingRule $rule): void
    {
        self::$rule = $rule;
        self::$sourceClassIndex = [];
        self::$searchClassIndex = [];

        foreach ($rule->allSourceClasses() as $class) {
            self::$sourceClassIndex[\strtolower($class)] = true;
        }

        foreach ($rule->searchClasses as $class) {
            self::$searchClassIndex[\strtolower($class)] = true;
        }

        ReturnTypeResolver::initForRule($rule);
    }

    /**
     * @return list<string>
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        if (!self::$rule instanceof \Psalm\LaravelPlugin\Handlers\Magic\ForwardingRule) {
            return [];
        }

        $classes = self::$rule->allSourceClasses();

        if (self::$rule->interceptMixin) {
            $classes = \array_merge($classes, self::$rule->searchClasses);
        }

        /** @var list<string> */
        return \array_values(\array_unique($classes));
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $rule = self::$rule;

        if (!$rule instanceof \Psalm\LaravelPlugin\Handlers\Magic\ForwardingRule) {
            return null;
        }

        $source = $event->getSource();

        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $fqClassName = $event->getFqClasslikeName();
        $methodName = $event->getMethodNameLowercase();
        $codebase = $source->getCodebase();

        // PATH 1: Mixin interception (fqClassName is Builder or QueryBuilder).
        // Must run first — see spec "Path ordering" section for rationale.
        $mixinResult = self::handleMixinInterception($source, $event, $fqClassName, $methodName, $codebase);

        if ($mixinResult instanceof \Psalm\Type\Union) {
            return $mixinResult;
        }

        // Skip methods already in declaring_method_ids on the source class.
        // These have stub return types (@return $this) that Psalm resolves correctly.
        // Only needed for Path 2 (source class calls, not mixin target calls).
        if (self::isInDeclaringMethodIds($codebase, $fqClassName, $methodName)) {
            return null;
        }

        // PATH 2: Direct __call (fqClassName is HasOne, BelongsTo, etc.)
        $fqClassNameLower = \strtolower($fqClassName);

        if (!isset(self::$sourceClassIndex[$fqClassNameLower])) {
            return null;
        }

        $templateParams = $event->getTemplateTypeParameters()
            ?? self::extractTemplateParamsFromCaller($source, $event, $fqClassName);

        return ReturnTypeResolver::resolve(
            $fqClassName,
            $templateParams,
            $codebase,
            $methodName,
        );
    }

    /**
     * Provide parameter types for methods resolved via __call on source classes.
     *
     * Only fires for Path 2 (QueryBuilder-only methods like orderBy, limit, groupBy).
     * Mixin-resolved methods (Path 1) already have params from the target class.
     *
     * @return list<FunctionLikeParameter>|null
     */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        if (!self::$rule instanceof \Psalm\LaravelPlugin\Handlers\Magic\ForwardingRule) {
            return null;
        }

        $fqClassName = $event->getFqClasslikeName();

        $fqClassNameLower = \strtolower($fqClassName);

        if (!isset(self::$sourceClassIndex[$fqClassNameLower])) {
            return null;
        }

        $statementsSource = $event->getStatementsSource();

        if (!$statementsSource instanceof StatementsAnalyzer) {
            return null;
        }

        $methodName = $event->getMethodNameLowercase();
        $codebase = $statementsSource->getCodebase();

        // Don't override params for methods declared on the source class (stubs)
        if (self::isInDeclaringMethodIds($codebase, $fqClassName, $methodName)) {
            return null;
        }

        // Find method on search classes, return its params
        foreach (self::$rule->searchClasses as $targetClass) {
            try {
                $classStorage = $codebase->classlike_storage_provider->get(\strtolower($targetClass));
            } catch (\InvalidArgumentException) {
                continue;
            }

            $declaringId = $classStorage->declaring_method_ids[$methodName] ?? null;

            if ($declaringId !== null) {
                try {
                    return $codebase->methods->getMethodParams($declaringId);
                } catch (\InvalidArgumentException|\UnexpectedValueException) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Intercept method calls that Psalm resolved via @mixin on a source class.
     *
     * When $relation->where() is called on HasOne<Phone, User>, Psalm resolves
     * where() via @mixin Builder<Phone> and fires the provider for Builder.
     * This method detects the original caller (HasOne) from the node type provider
     * and returns the Relation's generic type instead of Builder's.
     */
    private static function handleMixinInterception(
        StatementsAnalyzer $source,
        MethodReturnTypeProviderEvent $event,
        string $mixinTargetClass,
        string $methodName,
        Codebase $codebase,
    ): ?Union {
        $rule = self::$rule;

        if (!$rule instanceof \Psalm\LaravelPlugin\Handlers\Magic\ForwardingRule || !$rule->interceptMixin) {
            return null;
        }

        $stmt = $event->getStmt();

        if (!$stmt instanceof MethodCall) {
            return null;
        }

        // Get the ORIGINAL caller's type from the node type provider.
        // $stmt->var type is set BEFORE mixin resolution
        // (confirmed in MethodCallAnalyzer.php lines 67-69).
        $callerType = $source->getNodeTypeProvider()->getType($stmt->var);

        if (!$callerType instanceof \Psalm\Type\Union) {
            return null;
        }

        $mixinTargetLower = \strtolower($mixinTargetClass);

        foreach ($callerType->getAtomicTypes() as $atomicType) {
            if (!$atomicType instanceof TGenericObject) {
                continue;
            }

            // Is this caller a registered source class?
            $callerClassLower = \strtolower($atomicType->value);

            if (!isset(self::$sourceClassIndex[$callerClassLower])) {
                continue;
            }

            // Is the mixin target one of our search classes?
            if (!isset(self::$searchClassIndex[$mixinTargetLower])) {
                continue;
            }

            // Match! Apply Decorated forwarding with the caller's template params.
            return ReturnTypeResolver::resolve(
                $atomicType->value,
                $atomicType->type_params,
                $codebase,
                $methodName,
            );
        }

        return null;
    }

    /**
     * Extract template parameters from the caller's node type.
     *
     * Falls back to node type provider when event->getTemplateTypeParameters()
     * returns null (Path 2 __call dispatch doesn't always populate template params).
     *
     * @return non-empty-list<Union>|null
     */
    private static function extractTemplateParamsFromCaller(
        StatementsAnalyzer $source,
        MethodReturnTypeProviderEvent $event,
        string $expectedClass,
    ): ?array {
        $stmt = $event->getStmt();

        if (!$stmt instanceof MethodCall) {
            return null;
        }

        // Node type provider always has the type of $stmt->var — Psalm analyzes
        // left-to-right, inner-to-outer, so the receiver is analyzed before
        // the method call's return type provider fires.
        $varType = $source->getNodeTypeProvider()->getType($stmt->var);

        if (!$varType instanceof \Psalm\Type\Union) {
            return null;
        }

        $expectedLower = \strtolower($expectedClass);

        foreach ($varType->getAtomicTypes() as $atomic) {
            if (
                $atomic instanceof TGenericObject
                && \strtolower($atomic->value) === $expectedLower
            ) {
                return $atomic->type_params;
            }
        }

        return null;
    }

    /**
     * Check if a method is in the class's declaring_method_ids (i.e., declared or inherited,
     * not resolved via __call or @mixin).
     *
     * @psalm-mutation-free
     */
    private static function isInDeclaringMethodIds(Codebase $codebase, string $class, string $method): bool
    {
        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($class));

            return isset($storage->declaring_method_ids[$method]);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
